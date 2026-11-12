<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceClaim;
use App\Models\AttendanceClaimAction;
use App\Models\AttendanceRecord;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendancePolicySetting;
use App\Models\AuditLog;
use App\Services\InAppNotificationService;
use App\Services\EmailNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AttendanceClaimController extends Controller
{
    protected InAppNotificationService $inAppNotificationService;
    protected EmailNotificationService $emailNotificationService;

    public function __construct(
        InAppNotificationService $inAppNotificationService,
        EmailNotificationService $emailNotificationService
    ) {
        $this->inAppNotificationService = $inAppNotificationService;
        $this->emailNotificationService = $emailNotificationService;
    }

    /**
     * List employee's attendance claims.
     */
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee profile not found.'], 404);
        }

        $claims = AttendanceClaim::where('employee_id', $employee->id)
            ->with(['attendanceRecord', 'reportingManager', 'actions.actor'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $claims->items(),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'total' => $claims->total(),
            ]
        ]);
    }

    /**
     * Submit a new Attendance Claim.
     */
    public function store(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee profile not found.'], 404);
        }

        $policySettings = AttendancePolicySetting::forCompany($employee->company_id);
        $maxChars = $policySettings->claim_description_max_chars ?: 500;

        $validator = Validator::make($request->all(), [
            'claim_type' => 'required|in:missing_check_in,incorrect_check_in,missing_check_out,incorrect_check_out,accidental_check_in,accidental_check_out,auto_checkout_correction,other_attendance_issue',
            'date' => 'required|date_format:Y-m-d',
            'requested_check_in_at' => 'nullable|date',
            'requested_check_out_at' => 'nullable|date',
            'reason_code' => 'required|string',
            'description' => "required|string|max:{$maxChars}",
            'attendance_record_id' => 'nullable|integer|exists:attendance_records,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $dateStr = $request->date;
        $tz = $employee->company ? $employee->company->timezone : 'Asia/Dubai';

        $recIn = null;
        $recOut = null;
        $record = null;

        if ($request->filled('attendance_record_id')) {
            $record = AttendanceRecord::with(['checkInEvent', 'checkOutEvent'])->find($request->attendance_record_id);
        } else {
            $record = AttendanceRecord::with(['checkInEvent', 'checkOutEvent'])->where('employee_id', $employee->id)
                ->where('date', $dateStr)
                ->first();
        }

        if ($record) {
            $recIn = $record->checkInEvent?->event_time_server;
            $recOut = $record->checkOutEvent?->event_time_server;
        }

        $reqIn = $request->filled('requested_check_in_at') ? date('Y-m-d H:i:s', strtotime($request->requested_check_in_at)) : null;
        $reqOut = $request->filled('requested_check_out_at') ? date('Y-m-d H:i:s', strtotime($request->requested_check_out_at)) : null;

        return DB::transaction(function () use ($employee, $record, $dateStr, $request, $recIn, $recOut, $reqIn, $reqOut) {
            $manager = $employee->reportingManager;

            $claim = AttendanceClaim::create([
                'employee_id' => $employee->id,
                'reporting_manager_id' => $manager ? $manager->id : null,
                'company_id' => $employee->company_id,
                'attendance_record_id' => $record ? $record->id : null,
                'claim_type' => $request->claim_type,
                'date' => $dateStr,
                'recorded_check_in_at' => $recIn,
                'recorded_check_out_at' => $recOut,
                'requested_check_in_at' => $reqIn,
                'requested_check_out_at' => $reqOut,
                'reason_code' => $request->reason_code,
                'description' => $request->description,
                'status' => 'pending',
                'submitted_at' => Carbon::now()
            ]);

            AttendanceClaimAction::create([
                'attendance_claim_id' => $claim->id,
                'previous_status' => null,
                'new_status' => 'pending',
                'actor_id' => $request->user()->id,
                'actor_role' => 'employee',
                'comment' => 'Claim submitted'
            ]);

            // Notify Manager
            $managerUser = $manager ? $manager->user : null;
            if ($managerUser) {
                $idempotencyKey = "claim:{$claim->id}:submitted:mgr";
                $typeLabel = ucwords(str_replace('_', ' ', $claim->claim_type));
                $this->inAppNotificationService->createNotification(
                    $managerUser,
                    'manager',
                    'CLAIM_SUBMITTED_MANAGER',
                    'Attendance claim received',
                    "{$employee->full_name} submitted a {$typeLabel} claim for {$dateStr}.",
                    ['claim_id' => $claim->id, 'uuid' => $claim->uuid],
                    $idempotencyKey
                );
            }

            // Notify Employee
            $empUser = $employee->user;
            if ($empUser) {
                $idempotencyKey = "claim:{$claim->id}:submitted:emp";
                $this->inAppNotificationService->createNotification(
                    $empUser,
                    'employee',
                    'CLAIM_SUBMITTED_EMPLOYEE',
                    'Claim submitted',
                    'Your attendance claim was submitted for manager review.',
                    ['claim_id' => $claim->id, 'uuid' => $claim->uuid],
                    $idempotencyKey
                );
            }

            // Dispatch Email safely
            try {
                $this->emailNotificationService->sendClaimSubmittedToManager($claim);
            } catch (\Throwable $e) {
                \Log::warning("Email notification error: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Attendance claim submitted successfully.',
                'data' => $claim->load(['attendanceRecord', 'reportingManager', 'actions'])
            ], 201);
        });
    }

    /**
     * Show single attendance claim detail.
     */
    public function show($uuid)
    {
        $claim = AttendanceClaim::where('uuid', $uuid)
            ->with(['employee', 'reportingManager', 'attendanceRecord', 'actions.actor'])
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $claim]);
    }

    /**
     * Employee responds / acknowledges manager's explanation request on a claim.
     */
    public function respond(Request $request, $uuid)
    {
        $employee = $request->user()->employee;
        $claim = AttendanceClaim::where('uuid', $uuid)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        if ($claim->status !== 'explanation_requested') {
            return response()->json(['success' => false, 'message' => 'This claim does not require an explanation response.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'response' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($claim, $request) {
            $prevStatus = $claim->status;
            $claim->update([
                'status' => 'employee_responded',
                'employee_response' => $request->response
            ]);

            AttendanceClaimAction::create([
                'attendance_claim_id' => $claim->id,
                'previous_status' => $prevStatus,
                'new_status' => 'employee_responded',
                'actor_id' => $request->user()->id,
                'actor_role' => 'employee',
                'comment' => $request->response
            ]);

            // Notify Manager
            $manager = $claim->reportingManager;
            $managerUser = $manager ? $manager->user : null;
            if ($managerUser) {
                $idempotencyKey = "claim:{$claim->id}:responded:{$claim->updated_at->timestamp}";
                $this->inAppNotificationService->createNotification(
                    $managerUser,
                    'manager',
                    'CLAIM_EMPLOYEE_RESPONDED',
                    'Employee responded to claim explanation request',
                    "{$claim->employee->full_name} acknowledged and responded to your claim explanation request.",
                    ['claim_id' => $claim->id, 'uuid' => $claim->uuid],
                    $idempotencyKey
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Response submitted to manager.',
                'data' => $claim->fresh(['actions.actor'])
            ]);
        });
    }

    /**
     * Cancel an attendance claim.
     */
    public function cancel(Request $request, $uuid)
    {
        $employee = $request->user()->employee;
        $claim = AttendanceClaim::where('uuid', $uuid)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        if (!in_array($claim->status, ['pending', 'explanation_requested', 'employee_responded'])) {
            return response()->json(['success' => false, 'message' => 'Cannot cancel claim in current status.'], 400);
        }

        $prevStatus = $claim->status;
        $claim->update(['status' => 'cancelled']);

        AttendanceClaimAction::create([
            'attendance_claim_id' => $claim->id,
            'previous_status' => $prevStatus,
            'new_status' => 'cancelled',
            'actor_id' => $request->user()->id,
            'actor_role' => 'employee',
            'comment' => 'Claim cancelled by employee'
        ]);

        return response()->json(['success' => true, 'message' => 'Claim cancelled successfully.', 'data' => $claim]);
    }

    // ── MANAGER ENDPOINTS ───────────────────────────────────────────────────

    /**
     * List claims for manager.
     */
    public function managerIndex(Request $request)
    {
        $manager = $request->user()->employee;
        $filterStatus = $request->query('status'); // pending, needs_explanation, approved, rejected, all

        $query = AttendanceClaim::with(['employee', 'attendanceRecord', 'actions.actor'])
            ->orderBy('created_at', 'desc');

        if ($manager) {
            $query->where('reporting_manager_id', $manager->id);
        }

        if ($filterStatus === 'pending') {
            $query->whereIn('status', ['pending', 'submitted']);
        } else if ($filterStatus === 'needs_explanation') {
            $query->whereIn('status', ['explanation_requested', 'employee_responded']);
        } else if ($filterStatus === 'approved') {
            $query->where('status', 'approved');
        } else if ($filterStatus === 'rejected') {
            $query->where('status', 'rejected');
        }

        $claims = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $claims->items(),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'total' => $claims->total(),
            ]
        ]);
    }

    /**
     * Manager Approve Claim and apply attendance record correction.
     */
    public function approve(Request $request, $uuid)
    {
        $claim = AttendanceClaim::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($claim, $request) {
            $prevStatus = $claim->status;
            $comment = $request->input('comment', 'Claim approved by manager');

            $claim->update([
                'status' => 'approved',
                'actioned_at' => Carbon::now(),
                'manager_comment' => $comment
            ]);

            AttendanceClaimAction::create([
                'attendance_claim_id' => $claim->id,
                'previous_status' => $prevStatus,
                'new_status' => 'approved',
                'actor_id' => $request->user()->id,
                'actor_role' => 'manager',
                'comment' => $comment
            ]);

            // Apply audited attendance correction if record exists or create new record
            $record = $claim->attendanceRecord;
            if (!$record) {
                $record = AttendanceRecord::firstOrCreate(
                    [
                        'employee_id' => $claim->employee_id,
                        'date' => $claim->date->format('Y-m-d')
                    ],
                    [
                        'company_id' => $claim->company_id,
                        'status' => 'present'
                    ]
                );
            }

            $record = AttendanceRecord::with(['checkInEvent', 'checkOutEvent'])->find($record->id);
            $oldIn = $claim->recorded_check_in_at ?: $record->checkInEvent?->event_time_server;
            $oldOut = $claim->recorded_check_out_at ?: $record->checkOutEvent?->event_time_server;

            $newInUtc = $claim->requested_check_in_at ? Carbon::parse($claim->requested_check_in_at) : ($oldIn ? Carbon::parse($oldIn) : null);
            $newOutUtc = $claim->requested_check_out_at ? Carbon::parse($claim->requested_check_out_at) : ($oldOut ? Carbon::parse($oldOut) : null);

            $checkInEvtId = $record->check_in_event_id;
            $checkOutEvtId = $record->check_out_event_id;

            if ($claim->requested_check_in_at || !$checkInEvtId) {
                $evtIn = AttendanceEvent::create([
                    'employee_id' => $claim->employee_id,
                    'attendance_record_id' => $record->id,
                    'event_type' => 'ATTENDANCE_CHECKED_IN',
                    'event_time_server' => $newInUtc ?: Carbon::now(),
                    'event_source' => 'manager_approval',
                    'actor_role' => 'manager'
                ]);
                $checkInEvtId = $evtIn->id;
            }

            if ($claim->requested_check_out_at || !$checkOutEvtId) {
                $evtOut = AttendanceEvent::create([
                    'employee_id' => $claim->employee_id,
                    'attendance_record_id' => $record->id,
                    'event_type' => 'ATTENDANCE_CHECKED_OUT',
                    'event_time_server' => $newOutUtc ?: Carbon::now(),
                    'event_source' => 'manager_approval',
                    'actor_role' => 'manager'
                ]);
                $checkOutEvtId = $evtOut->id;
            }

            // Recalculate duration
            $workedSeconds = 0;
            if ($newInUtc && $newOutUtc) {
                $workedSeconds = abs($newOutUtc->diffInSeconds($newInUtc));
            }

            $record->update([
                'check_in_event_id' => $checkInEvtId,
                'check_out_event_id' => $checkOutEvtId,
                'total_duration_minutes' => (int) ceil($workedSeconds / 60),
                'net_duration_minutes' => (int) ceil($workedSeconds / 60),
                'status' => 'present'
            ]);

            // Create AttendanceCorrection audit
            AttendanceCorrection::create([
                'employee_id' => $claim->employee_id,
                'date' => $claim->date ? $claim->date->format('Y-m-d') : Carbon::now()->toDateString(),
                'correction_type' => $claim->claim_type,
                'requested_time' => $newOutUtc ?: $newInUtc,
                'reason' => "Claim approved: {$claim->claim_type} - {$comment}",
                'manager_id' => $claim->reporting_manager_id,
                'status' => 'approved',
                'manager_notes' => $comment
            ]);

            // Create MANAGER_CORRECTION AttendanceEvent for Recents
            AttendanceEvent::create([
                'employee_id' => $claim->employee_id,
                'attendance_record_id' => $record->id,
                'event_type' => 'MANAGER_CORRECTION',
                'event_time_server' => Carbon::now(),
                'event_source' => 'manager_approval',
                'actor_role' => 'manager',
                'metadata' => [
                    'claim_uuid' => $claim->uuid,
                    'claim_type' => $claim->claim_type,
                    'corrected_check_in' => $newInUtc ? $newInUtc->toIso8601String() : null,
                    'corrected_check_out' => $newOutUtc ? $newOutUtc->toIso8601String() : null,
                ]
            ]);

            // Notify Employee
            $empUser = $claim->employee ? $claim->employee->user : null;
            if ($empUser) {
                $mgrName = $request->user()->name;
                $dateStr = $claim->date ? $claim->date->format('Y-m-d') : '';
                $idempotencyKey = "claim:{$claim->id}:approved:{$claim->actioned_at->timestamp}";

                $this->inAppNotificationService->createNotification(
                    $empUser,
                    'employee',
                    'CLAIM_APPROVED',
                    'Attendance claim approved',
                    "Your attendance claim for {$dateStr} was approved by {$mgrName} and your attendance record has been updated.",
                    ['claim_id' => $claim->id, 'uuid' => $claim->uuid],
                    $idempotencyKey
                );
            }

            return response()->json(['success' => true, 'message' => 'Claim approved and attendance record updated.', 'data' => $claim->fresh(['actions.actor'])]);
        });
    }

    /**
     * Manager Reject Claim.
     */
    public function reject(Request $request, $uuid)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Manager comment is required for rejection.', 'errors' => $validator->errors()], 422);
        }

        $claim = AttendanceClaim::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($claim, $request) {
            $prevStatus = $claim->status;
            $comment = $request->input('comment');

            $claim->update([
                'status' => 'rejected',
                'actioned_at' => Carbon::now(),
                'manager_comment' => $comment
            ]);

            AttendanceClaimAction::create([
                'attendance_claim_id' => $claim->id,
                'previous_status' => $prevStatus,
                'new_status' => 'rejected',
                'actor_id' => $request->user()->id,
                'actor_role' => 'manager',
                'comment' => $comment
            ]);

            // Notify Employee
            $empUser = $claim->employee ? $claim->employee->user : null;
            if ($empUser) {
                $dateStr = $claim->date ? $claim->date->format('Y-m-d') : '';
                $idempotencyKey = "claim:{$claim->id}:rejected:{$claim->actioned_at->timestamp}";

                $this->inAppNotificationService->createNotification(
                    $empUser,
                    'employee',
                    'CLAIM_REJECTED',
                    'Attendance claim rejected',
                    "Your attendance claim for {$dateStr} was rejected. Open the claim to view manager comments.",
                    ['claim_id' => $claim->id, 'uuid' => $claim->uuid],
                    $idempotencyKey
                );
            }

            return response()->json(['success' => true, 'message' => 'Claim rejected.', 'data' => $claim->fresh(['actions.actor'])]);
        });
    }

    /**
     * Manager Request Explanation on Claim.
     */
    public function requestExplanation(Request $request, $uuid)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Comment is required when requesting an explanation.', 'errors' => $validator->errors()], 422);
        }

        $claim = AttendanceClaim::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($claim, $request) {
            $prevStatus = $claim->status;
            $comment = $request->input('comment');

            $claim->update([
                'status' => 'explanation_requested',
                'explanation_request' => $comment
            ]);

            AttendanceClaimAction::create([
                'attendance_claim_id' => $claim->id,
                'previous_status' => $prevStatus,
                'new_status' => 'explanation_requested',
                'actor_id' => $request->user()->id,
                'actor_role' => 'manager',
                'comment' => $comment
            ]);

            // Notify Employee
            $empUser = $claim->employee ? $claim->employee->user : null;
            if ($empUser) {
                $idempotencyKey = "claim:{$claim->id}:exp_req:" . time();

                $this->inAppNotificationService->createNotification(
                    $empUser,
                    'employee',
                    'CLAIM_EXPLANATION_NEEDED',
                    'Additional explanation required for claim',
                    'Your manager requested more information about your attendance claim.',
                    ['claim_id' => $claim->id, 'uuid' => $claim->uuid],
                    $idempotencyKey
                );
            }

            return response()->json(['success' => true, 'message' => 'Explanation requested from employee.', 'data' => $claim->fresh(['actions.actor'])]);
        });
    }
}
