<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRequest;
use App\Models\AttendanceRequestAction;
use App\Models\AttendancePolicySetting;
use App\Models\Shift;
use App\Services\InAppNotificationService;
use App\Services\EmailNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AttendanceRequestController extends Controller
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
     * List employee's attendance requests.
     */
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee profile not found.'], 404);
        }

        $requests = AttendanceRequest::where('employee_id', $employee->id)
            ->with(['shift', 'reportingManager', 'actions.actor'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $requests->items(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ]
        ]);
    }

    /**
     * Submit a new Late Arrival or Morning Half-Day Request.
     */
    public function store(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee profile not found.'], 404);
        }

        $policySettings = AttendancePolicySetting::forCompany($employee->company_id);

        $validator = Validator::make($request->all(), [
            'date' => 'required|date_format:Y-m-d',
            'request_type' => 'required|in:late_arrival,morning_half_day',
            'delay_minutes' => 'nullable|integer|min:1|max:' . $policySettings->max_late_delay_minutes,
            'expected_arrival_at' => 'nullable|date',
            'reason_code' => 'required|string',
            'description' => $request->reason_code === 'other' ? 'required|string|max:500' : 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $dateStr = $request->date;
        $shift = $employee->assignedShift;
        if (!$shift) {
            return response()->json(['success' => false, 'message' => 'No assigned shift found for employee.'], 400);
        }

        // Prevent active duplicate request for same date
        $existing = AttendanceRequest::where('employee_id', $employee->id)
            ->where('date', $dateStr)
            ->whereIn('status', ['submitted', 'pending', 'approved', 'explanation_requested', 'employee_responded'])
            ->first();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'An active request already exists for this date.'], 400);
        }

        $tz = $employee->company ? $employee->company->timezone : 'Asia/Dubai';
        $shiftStartLocal = Carbon::parse("{$dateStr} {$shift->start_time}", $tz);
        $shiftEndLocal   = Carbon::parse("{$dateStr} {$shift->end_time}", $tz);
        if ($shiftEndLocal->lt($shiftStartLocal)) {
            $shiftEndLocal->addDay();
        }

        $delayMinutes = (int) ($request->delay_minutes ?? 0);
        $expectedArrival = null;

        if ($request->request_type === 'late_arrival') {
            if ($request->filled('expected_arrival_at')) {
                $expectedArrival = Carbon::parse($request->expected_arrival_at, $tz);
                $delayMinutes = (int) max(0, $expectedArrival->diffInMinutes($shiftStartLocal));
            } else if ($delayMinutes > 0) {
                $expectedArrival = $shiftStartLocal->copy()->addMinutes($delayMinutes);
            } else {
                return response()->json(['success' => false, 'message' => 'Either delay_minutes or expected_arrival_at must be provided.'], 422);
            }

            // Validate expected arrival is between shift start and end
            if ($expectedArrival->lte($shiftStartLocal) || $expectedArrival->gte($shiftEndLocal)) {
                return response()->json(['success' => false, 'message' => 'Expected arrival time must be after shift start and before shift end.'], 422);
            }
        } else if ($request->request_type === 'morning_half_day') {
            // Morning half-day: arrival is after half the shift duration
            $shiftDurationMinutes = (int) ($shiftEndLocal->diffInMinutes($shiftStartLocal));
            $halfMinutes = (int) ceil($shiftDurationMinutes * ($policySettings->half_day_threshold_percent / 100.0));
            $expectedArrival = $shiftStartLocal->copy()->addMinutes($halfMinutes);
            $delayMinutes = $halfMinutes;
        }

        return DB::transaction(function () use ($employee, $shift, $dateStr, $request, $delayMinutes, $expectedArrival) {
            $manager = $employee->reportingManager;

            $attRequest = AttendanceRequest::create([
                'employee_id' => $employee->id,
                'reporting_manager_id' => $manager ? $manager->id : null,
                'company_id' => $employee->company_id,
                'shift_id' => $shift->id,
                'date' => $dateStr,
                'request_type' => $request->request_type,
                'delay_minutes' => $delayMinutes,
                'expected_arrival_at' => $expectedArrival ? $expectedArrival->copy()->setTimezone('UTC') : null,
                'reason_code' => $request->reason_code,
                'description' => $request->description,
                'status' => 'pending',
                'submitted_at' => Carbon::now()
            ]);

            AttendanceRequestAction::create([
                'attendance_request_id' => $attRequest->id,
                'previous_status' => null,
                'new_status' => 'pending',
                'actor_id' => $request->user()->id,
                'actor_role' => 'employee',
                'comment' => 'Request submitted'
            ]);

            // Notify Manager
            $managerUser = $manager ? $manager->user : null;
            if ($managerUser) {
                $idempotencyKey = "req:{$attRequest->id}:submitted:mgr";
                $typeLabel = $attRequest->request_type === 'morning_half_day' ? 'morning half-day' : 'late arrival';
                $this->inAppNotificationService->createNotification(
                    $managerUser,
                    'manager',
                    'REQUEST_SUBMITTED_MANAGER',
                    'Attendance request received',
                    "{$employee->full_name} submitted a {$typeLabel} request for {$dateStr}.",
                    ['request_id' => $attRequest->id, 'uuid' => $attRequest->uuid],
                    $idempotencyKey
                );
            }

            // Notify Employee
            $empUser = $employee->user;
            if ($empUser) {
                $idempotencyKey = "req:{$attRequest->id}:submitted:emp";
                $this->inAppNotificationService->createNotification(
                    $empUser,
                    'employee',
                    'REQUEST_SUBMITTED_EMPLOYEE',
                    'Request submitted',
                    'Your attendance request was submitted and is waiting for manager review.',
                    ['request_id' => $attRequest->id, 'uuid' => $attRequest->uuid],
                    $idempotencyKey
                );
            }

            // Dispatch Manager Email
            $this->emailNotificationService->sendRequestSubmittedToManager($attRequest);

            return response()->json([
                'success' => true,
                'message' => 'Attendance request submitted successfully.',
                'data' => $attRequest->load(['shift', 'reportingManager', 'actions'])
            ], 201);
        });
    }

    /**
     * Show single attendance request detail.
     */
    public function show($uuid)
    {
        $attRequest = AttendanceRequest::where('uuid', $uuid)
            ->with(['employee', 'reportingManager', 'shift', 'actions.actor'])
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $attRequest]);
    }

    /**
     * Employee responds / acknowledges manager's explanation request.
     */
    public function respond(Request $request, $uuid)
    {
        $employee = $request->user()->employee;
        $attRequest = AttendanceRequest::where('uuid', $uuid)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        if ($attRequest->status !== 'explanation_requested') {
            return response()->json(['success' => false, 'message' => 'This request does not require an explanation response.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'response' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($attRequest, $request) {
            $prevStatus = $attRequest->status;
            $attRequest->update([
                'status' => 'employee_responded',
                'employee_response' => $request->response
            ]);

            AttendanceRequestAction::create([
                'attendance_request_id' => $attRequest->id,
                'previous_status' => $prevStatus,
                'new_status' => 'employee_responded',
                'actor_id' => $request->user()->id,
                'actor_role' => 'employee',
                'comment' => $request->response
            ]);

            // Notify Manager
            $manager = $attRequest->reportingManager;
            $managerUser = $manager ? $manager->user : null;
            if ($managerUser) {
                $idempotencyKey = "req:{$attRequest->id}:responded:{$attRequest->updated_at->timestamp}";
                $this->inAppNotificationService->createNotification(
                    $managerUser,
                    'manager',
                    'REQUEST_EMPLOYEE_RESPONDED',
                    'Employee responded to explanation request',
                    "{$attRequest->employee->full_name} acknowledged and responded to your explanation request.",
                    ['request_id' => $attRequest->id, 'uuid' => $attRequest->uuid],
                    $idempotencyKey
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Response submitted to manager.',
                'data' => $attRequest->fresh(['actions.actor'])
            ]);
        });
    }

    /**
     * Cancel an attendance request.
     */
    public function cancel(Request $request, $uuid)
    {
        $employee = $request->user()->employee;
        $attRequest = AttendanceRequest::where('uuid', $uuid)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        if (!in_array($attRequest->status, ['pending', 'explanation_requested', 'employee_responded'])) {
            return response()->json(['success' => false, 'message' => 'Cannot cancel request in current status.'], 400);
        }

        $prevStatus = $attRequest->status;
        $attRequest->update(['status' => 'cancelled']);

        AttendanceRequestAction::create([
            'attendance_request_id' => $attRequest->id,
            'previous_status' => $prevStatus,
            'new_status' => 'cancelled',
            'actor_id' => $request->user()->id,
            'actor_role' => 'employee',
            'comment' => 'Request cancelled by employee'
        ]);

        return response()->json(['success' => true, 'message' => 'Request cancelled successfully.', 'data' => $attRequest]);
    }

    // ── MANAGER ENDPOINTS ───────────────────────────────────────────────────

    /**
     * List requests for manager.
     */
    public function managerIndex(Request $request)
    {
        $manager = $request->user()->employee;
        $filterStatus = $request->query('status'); // pending, needs_explanation, approved, rejected, all

        $query = AttendanceRequest::with(['employee', 'shift', 'actions.actor'])
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

        $requests = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $requests->items(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ]
        ]);
    }

    /**
     * Manager Approve Request.
     */
    public function approve(Request $request, $uuid)
    {
        $attRequest = AttendanceRequest::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($attRequest, $request) {
            $prevStatus = $attRequest->status;
            $comment = $request->input('comment', 'Request approved by manager');

            $attRequest->update([
                'status' => 'approved',
                'actioned_at' => Carbon::now(),
                'manager_comment' => $comment
            ]);

            AttendanceRequestAction::create([
                'attendance_request_id' => $attRequest->id,
                'previous_status' => $prevStatus,
                'new_status' => 'approved',
                'actor_id' => $request->user()->id,
                'actor_role' => 'manager',
                'comment' => $comment
            ]);

            // Notify Employee
            $empUser = $attRequest->employee ? $attRequest->employee->user : null;
            if ($empUser) {
                $mgrName = $request->user()->name;
                $typeLabel = $attRequest->request_type === 'morning_half_day' ? 'morning half-day' : 'late arrival';
                $dateStr = $attRequest->date ? $attRequest->date->format('Y-m-d') : '';
                $idempotencyKey = "req:{$attRequest->id}:approved:{$attRequest->actioned_at->timestamp}";

                $this->inAppNotificationService->createNotification(
                    $empUser,
                    'employee',
                    'REQUEST_APPROVED',
                    'Attendance request approved',
                    "Your {$typeLabel} request for {$dateStr} was approved by {$mgrName}.",
                    ['request_id' => $attRequest->id, 'uuid' => $attRequest->uuid],
                    $idempotencyKey
                );
            }

            return response()->json(['success' => true, 'message' => 'Request approved successfully.', 'data' => $attRequest->fresh(['actions.actor'])]);
        });
    }

    /**
     * Manager Reject Request (requires comment).
     */
    public function reject(Request $request, $uuid)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Manager comment is required for rejection.', 'errors' => $validator->errors()], 422);
        }

        $attRequest = AttendanceRequest::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($attRequest, $request) {
            $prevStatus = $attRequest->status;
            $comment = $request->input('comment');

            $attRequest->update([
                'status' => 'rejected',
                'actioned_at' => Carbon::now(),
                'manager_comment' => $comment
            ]);

            AttendanceRequestAction::create([
                'attendance_request_id' => $attRequest->id,
                'previous_status' => $prevStatus,
                'new_status' => 'rejected',
                'actor_id' => $request->user()->id,
                'actor_role' => 'manager',
                'comment' => $comment
            ]);

            // Notify Employee
            $empUser = $attRequest->employee ? $attRequest->employee->user : null;
            if ($empUser) {
                $typeLabel = $attRequest->request_type === 'morning_half_day' ? 'morning half-day' : 'late arrival';
                $dateStr = $attRequest->date ? $attRequest->date->format('Y-m-d') : '';
                $idempotencyKey = "req:{$attRequest->id}:rejected:{$attRequest->actioned_at->timestamp}";

                $this->inAppNotificationService->createNotification(
                    $empUser,
                    'employee',
                    'REQUEST_REJECTED',
                    'Attendance request rejected',
                    "Your {$typeLabel} request for {$dateStr} was rejected. Open the request to view the manager’s comments.",
                    ['request_id' => $attRequest->id, 'uuid' => $attRequest->uuid],
                    $idempotencyKey
                );
            }

            return response()->json(['success' => true, 'message' => 'Request rejected.', 'data' => $attRequest->fresh(['actions.actor'])]);
        });
    }

    /**
     * Manager Request Explanation (requires comment).
     */
    public function requestExplanation(Request $request, $uuid)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Comment is required when requesting an explanation.', 'errors' => $validator->errors()], 422);
        }

        $attRequest = AttendanceRequest::where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($attRequest, $request) {
            $prevStatus = $attRequest->status;
            $comment = $request->input('comment');

            $attRequest->update([
                'status' => 'explanation_requested',
                'explanation_request' => $comment
            ]);

            AttendanceRequestAction::create([
                'attendance_request_id' => $attRequest->id,
                'previous_status' => $prevStatus,
                'new_status' => 'explanation_requested',
                'actor_id' => $request->user()->id,
                'actor_role' => 'manager',
                'comment' => $comment
            ]);

            // Notify Employee
            $empUser = $attRequest->employee ? $attRequest->employee->user : null;
            if ($empUser) {
                $idempotencyKey = "req:{$attRequest->id}:exp_req:" . time();

                $this->inAppNotificationService->createNotification(
                    $empUser,
                    'employee',
                    'REQUEST_EXPLANATION_NEEDED',
                    'Additional explanation required',
                    'Your manager requested more information about your attendance request.',
                    ['request_id' => $attRequest->id, 'uuid' => $attRequest->uuid],
                    $idempotencyKey
                );
            }

            return response()->json(['success' => true, 'message' => 'Explanation requested from employee.', 'data' => $attRequest->fresh(['actions.actor'])]);
        });
    }
}
