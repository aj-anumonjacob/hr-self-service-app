<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BreakPolicy;
use App\Models\BreakPolicyAssignment;
use App\Models\CompanyBreakSetting;
use App\Models\AttendanceBreak;
use App\Services\BreakPolicyService;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BreakPolicyController extends Controller
{
    protected BreakPolicyService $policyService;

    public function __construct(BreakPolicyService $policyService)
    {
        $this->policyService = $policyService;
    }

    /**
     * List break policies
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;
        $companyId = $employee ? $employee->company_id : 1;

        $policies = BreakPolicy::with(['assignments', 'creator'])
            ->where('company_id', $companyId)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $policies
        ]);
    }

    /**
     * Create break policy
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->hasRole('super_admin') && !$user->hasRole('hr_admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to create break policies.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'policy_type' => 'required|in:flexible,fixed,window',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'allowed_duration_minutes' => 'required|integer|min:1|max:480',
            'max_breaks_per_shift' => 'required|integer|min:1|max:10',
            'is_paid' => 'boolean',
            'requires_approval' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $employee = $user->employee;
        $companyId = $employee ? $employee->company_id : 1;

        $policy = BreakPolicy::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'policy_type' => $request->policy_type,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'allowed_duration_minutes' => $request->allowed_duration_minutes,
            'max_breaks_per_shift' => $request->max_breaks_per_shift,
            'is_paid' => $request->is_paid ?? false,
            'requires_approval' => $request->requires_approval ?? false,
            'is_active' => true,
            'created_by' => $user->id
        ]);

        // Log audit
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'create_break_policy',
            'model_type' => BreakPolicy::class,
            'model_id' => $policy->id,
            'new_values' => $policy->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Break policy created successfully.',
            'data' => $policy
        ]);
    }

    /**
     * Update break policy
     */
    public function update(Request $request, $uuid)
    {
        $user = $request->user();
        if (!$user->hasRole('super_admin') && !$user->hasRole('hr_admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to edit break policies.'], 403);
        }

        $policy = BreakPolicy::where('uuid', $uuid)->first();
        if (!$policy) {
            return response()->json(['success' => false, 'message' => 'Break policy not found.'], 404);
        }

        $oldValues = $policy->toArray();

        $policy->update($request->only([
            'name',
            'policy_type',
            'start_time',
            'end_time',
            'allowed_duration_minutes',
            'max_breaks_per_shift',
            'is_paid',
            'requires_approval',
            'is_active'
        ]));

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'update_break_policy',
            'model_type' => BreakPolicy::class,
            'model_id' => $policy->id,
            'old_values' => $oldValues,
            'new_values' => $policy->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Break policy updated successfully.',
            'data' => $policy
        ]);
    }

    /**
     * Assign break policy
     */
    public function assign(Request $request, $uuid)
    {
        $user = $request->user();
        if (!$user->hasRole('super_admin') && !$user->hasRole('hr_admin') && !$user->hasRole('manager')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to assign policies.'], 403);
        }

        $policy = BreakPolicy::where('uuid', $uuid)->first();
        if (!$policy) {
            return response()->json(['success' => false, 'message' => 'Break policy not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'assignable_type' => 'required|in:company,department,shift,employee',
            'assignable_id' => 'required|integer',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $assignment = BreakPolicyAssignment::create([
            'break_policy_id' => $policy->id,
            'assignable_type' => $request->assignable_type,
            'assignable_id' => $request->assignable_id,
            'effective_from' => $request->effective_from,
            'effective_until' => $request->effective_until,
            'assigned_by' => $user->id
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'assign_break_policy',
            'model_type' => BreakPolicyAssignment::class,
            'model_id' => $assignment->id,
            'new_values' => $assignment->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Break policy assigned successfully.',
            'data' => $assignment
        ]);
    }

    /**
     * Active & Overdue Breaks list for manager/admin dashboard
     */
    public function activeOverdueBreaks(Request $request)
    {
        $user = $request->user();
        $manager = $user->employee;

        $query = AttendanceBreak::with(['employee.department', 'record', 'breakPolicy', 'notificationLogs'])
            ->where('status', 'active')
            ->whereNull('end_time_server');

        // Scoped to manager team if user is manager and not super_admin
        if ($manager && !$user->hasRole('super_admin') && !$user->hasRole('hr_admin')) {
            $query->whereHas('employee', function ($q) use ($manager) {
                $q->where('reporting_manager_id', $manager->id)
                  ->orWhere('department_id', $manager->department_id);
            });
        }

        $breaks = $query->orderBy('expected_end_at', 'asc')->get();

        $now = Carbon::now();
        $items = $breaks->map(function ($b) use ($now) {
            $isOverdue = $b->expected_end_at ? $now->gt($b->expected_end_at) : false;
            $overdueMins = ($isOverdue && $b->expected_end_at) ? (int) ceil($now->diffInSeconds($b->expected_end_at) / 60) : 0;
            $elapsedMins = $b->start_time_server ? (int) ceil($now->diffInSeconds($b->start_time_server) / 60) : 0;

            return [
                'uuid' => $b->uuid,
                'employee_name' => $b->employee ? $b->employee->full_name : 'Unknown',
                'employee_number' => $b->employee ? $b->employee->employee_number : '',
                'department_name' => ($b->employee && $b->employee->department) ? $b->employee->department->name : '',
                'started_at' => $b->start_time_server ? $b->start_time_server->toIso8601String() : null,
                'expected_end_at' => $b->expected_end_at ? $b->expected_end_at->toIso8601String() : null,
                'elapsed_minutes' => $elapsedMins,
                'is_overdue' => $isOverdue,
                'overdue_minutes' => $overdueMins,
                'notification_count' => $b->overdue_notification_count,
                'escalation_level' => $b->escalation_level,
                'series_status' => $b->notification_series_status,
                'policy_name' => $b->breakPolicy ? $b->breakPolicy->name : 'Flexible Break'
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items
        ]);
    }

    /**
     * Manager audited break correction/closure
     */
    public function managerCorrectBreak(Request $request, $uuid)
    {
        $user = $request->user();
        $manager = $user->employee;

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:5|max:500',
            'action' => 'required|in:close,cancel'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'A detailed correction reason is required.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $break = AttendanceBreak::with('employee')->where('uuid', $uuid)->first();
        if (!$break) {
            return response()->json(['success' => false, 'message' => 'Break record not found.'], 404);
        }

        // Scope check
        if ($manager && !$user->hasRole('super_admin') && !$user->hasRole('hr_admin')) {
            if ($break->employee->reporting_manager_id !== $manager->id) {
                return response()->json(['success' => false, 'message' => 'Unauthorized to modify break for this employee.'], 403);
            }
        }

        $now = Carbon::now();
        $durationSeconds = max(0, $now->diffInSeconds($break->start_time_server));
        $durationMinutes = (int) max(1, ceil($durationSeconds / 60));
        $overdueSeconds = ($break->expected_end_at && $now->gt($break->expected_end_at)) ? max(0, $now->diffInSeconds($break->expected_end_at)) : null;

        $oldValues = $break->toArray();

        $newStatus = $request->action === 'cancel' ? 'cancelled' : 'completed';

        $break->update([
            'end_time_server' => $now,
            'end_time_device' => $now,
            'duration_seconds' => $durationSeconds,
            'duration_minutes' => $durationMinutes,
            'overdue_duration_seconds' => $overdueSeconds,
            'status' => $newStatus,
            'notification_series_status' => 'stopped',
            'notification_series_stopped_at' => $now,
            'notification_series_stop_reason' => 'MANAGER_CORRECTED: ' . $request->reason,
            'approved_by' => $manager ? $manager->id : null,
            'approved_at' => $now,
            'violation_code' => $overdueSeconds ? 'EXCEEDED_MAX_DURATION' : null
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'manager_correct_break',
            'model_type' => AttendanceBreak::class,
            'model_id' => $break->id,
            'old_values' => $oldValues,
            'new_values' => array_merge($break->toArray(), ['manager_reason' => $request->reason]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Break corrected and notification escalation stopped.',
            'data' => $break
        ]);
    }

    /**
     * Get or update company break notification settings
     */
    public function companySettings(Request $request)
    {
        $employee = $request->user()->employee;
        $companyId = $employee ? $employee->company_id : 1;

        $settings = $this->policyService->getOrCreateCompanyBreakSettings($companyId);

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    public function updateCompanySettings(Request $request)
    {
        $user = $request->user();
        if (!$user->hasRole('super_admin') && !$user->hasRole('hr_admin')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to update company break settings.'], 403);
        }

        $employee = $user->employee;
        $companyId = $employee ? $employee->company_id : 1;

        $settings = $this->policyService->getOrCreateCompanyBreakSettings($companyId);
        $oldValues = $settings->toArray();

        $settings->update($request->only([
            'initial_overdue_enabled',
            'initial_delay_minutes',
            'repeat_interval_minutes',
            'employee_repeat_enabled',
            'manager_escalation_delay_minutes',
            'admin_escalation_delay_minutes',
            'repeat_manager_escalation',
            'max_repeat_notifications',
            'push_enabled',
            'in_app_enabled'
        ]));

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'update_company_break_settings',
            'model_type' => CompanyBreakSetting::class,
            'model_id' => $settings->id,
            'old_values' => $oldValues,
            'new_values' => $settings->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Company break notification settings updated.',
            'data' => $settings
        ]);
    }
}
