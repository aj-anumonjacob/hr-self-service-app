<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AttendancePolicyService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    protected $policyService;

    public function __construct(AttendancePolicyService $policyService)
    {
        $this->policyService = $policyService;
    }

    /**
     * Get current user profile details
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;
        
        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'employee' => $this->formatEmployeeProfile($employee)
            ]
        ]);
    }

    /**
     * Fetch active work context (Location, Shift, Holiday, Assignment status) for today
     */
    public function todayContext(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee details not found.'], 404);
        }

        $policy = $this->policyService->resolveActivePolicy($employee);
        
        // Check if today is a holiday
        $today = \Carbon\Carbon::now()->toDateString();
        $holiday = null;
        if (\Schema::hasColumn('holidays', 'start_date')) {
            $holidayQuery = \App\Models\Holiday::query();
            if (\Schema::hasColumn('holidays', 'status')) {
                $holidayQuery->where('status', true);
            }
            $holiday = $holidayQuery->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->first();
        } elseif (\Schema::hasColumn('holidays', 'holiday_date')) {
            $holiday = \App\Models\Holiday::where('holiday_date', $today)->first();
        }

        // Check if today is a weekly off
        $isWeeklyOff = false;
        if ($employee->weeklyOffRule) {
            $dayOfWeek = \Carbon\Carbon::now()->dayOfWeek; // 0 (Sunday) to 6 (Saturday)
            $isWeeklyOff = in_array($dayOfWeek, $employee->weeklyOffRule->days);
        }

        // Active check-in record details
        $activeRecord = $employee->attendanceRecords()
            ->where('date', $today)
            ->whereNotNull('check_in_event_id')
            ->whereNull('check_out_event_id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'employee' => $this->formatEmployeeProfile($employee),
                'policy' => $policy,
                'holiday' => $holiday ? [
                    'name' => $holiday->name,
                    'type' => $holiday->holiday_type
                ] : null,
                'is_weekly_off' => $isWeeklyOff,
                'active_session' => $activeRecord ? [
                    'uuid' => $activeRecord->uuid,
                    'check_in_time' => $activeRecord->checkInEvent ? $activeRecord->checkInEvent->event_time_device : null
                ] : null
            ]
        ]);
    }

    protected function formatEmployeeProfile($employee)
    {
        if (!$employee) return null;

        $deptName = null;
        if ($employee->department) {
            $deptName = $employee->department->name;
        } elseif (!empty($employee->department_id) && \Schema::hasTable('departments')) {
            $deptName = \DB::table('departments')->where('id', $employee->department_id)->value('name');
        }

        $compName = null;
        if ($employee->company) {
            $compName = $employee->company->name;
        } elseif (!empty($employee->company_id) && \Schema::hasTable('companies')) {
            $compName = \DB::table('companies')->where('id', $employee->company_id)->value('name');
        }

        $desigName = null;
        if ($employee->designation) {
            $desigName = $employee->designation->name;
        } elseif (!empty($employee->position_title)) {
            $desigName = $employee->position_title;
        } elseif (!empty($employee->designation_id) && \Schema::hasTable('designations')) {
            $desigName = \DB::table('designations')->where('id', $employee->designation_id)->value('name');
        }

        $displayName = !empty($employee->nickname)
            ? trim($employee->nickname)
            : (!empty($employee->first_name) ? trim($employee->first_name) : explode(' ', trim($employee->full_name ?? 'Employee'))[0]);

        $locName = null;
        if ($employee->location) {
            $locName = $employee->location->name;
        } elseif ($employee->defaultLocation) {
            $locName = $employee->defaultLocation->name;
        } elseif (!empty($employee->location_id) && \Schema::hasTable('locations')) {
            $locName = \DB::table('locations')->where('id', $employee->location_id)->value('name');
        }

        $title = strtolower(trim($employee->position_title ?? $desigName ?? ''));
        $maintRole = null;
        if (!empty($title)) {
            if (preg_match('/\b(engineer|engr|eng)\b/i', $title)) $maintRole = 'engineer';
            elseif (preg_match('/\b(supervisor|foreman|lead)\b/i', $title)) $maintRole = 'supervisor';
            elseif (preg_match('/\b(technician|tech|plumber|electrician|hvac|mechanic|carpenter|painter|mason)\b/i', $title)) $maintRole = 'technician';
            elseif (preg_match('/\b(helper|assistant|laborer|handyman)\b/i', $title)) $maintRole = 'helper';
            elseif (preg_match('/\b(maintenance|facility|facilities)\b/i', $title)) $maintRole = 'technician';
        }

        if (!$maintRole && \Schema::hasTable('re_maintenance_schedule_assignees')) {
            $assigned = \DB::table('re_maintenance_schedule_assignees')
                ->where('employee_id', $employee->id)
                ->first();
            if ($assigned) {
                $maintRole = $assigned->team_role ?? 'technician';
            }
        }

        $deptTitle = strtolower(trim($deptName ?? ''));
        $isCleaningStaff = false;
        if (preg_match('/\b(clean|cleaner|cleaning|housekeeping|housekeeper|janitor|maid|cleaners)\b/i', $deptTitle . ' ' . $title)) {
            $isCleaningStaff = true;
        }
        if (!$isCleaningStaff && \Schema::hasTable('order_workers') && \Schema::hasTable('workers')) {
            $isCleaningStaff = \DB::table('order_workers as ow')
                ->join('workers as w', 'w.id', '=', 'ow.worker_id')
                ->where(function($q) use ($employee) {
                    $q->where('w.emp_num', $employee->employee_code ?? '')
                      ->orWhere('w.id', $employee->id);
                })
                ->exists();
        }

        $user = $employee->user;
        $userRoleNames = [];
        if ($user) {
            $userRoleNames = \DB::table('user_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $user->id)
                ->pluck('r.name')
                ->map(fn($n) => strtolower($n))
                ->toArray();
        }

        $roleName = strtolower($user->role ?? '');
        $allAccessKeywords = ['owner', 'admin', 'super_admin', 'accountant', 'manager', 'director', 'supervisor', 'engineer', 'property manager', 'it', 'information technology', 'system', 'technician'];
        $canViewCleaningSchedules = $isCleaningStaff;

        foreach (array_merge($userRoleNames, [$roleName, strtolower($desigName ?? ''), $title, $deptTitle]) as $checkRole) {
            if (empty($checkRole)) continue;
            foreach ($allAccessKeywords as $kw) {
                if (str_contains($checkRole, $kw)) {
                    $canViewCleaningSchedules = true;
                    break 2;
                }
            }
        }

        return [
            'uuid' => !empty($employee->uuid) ? $employee->uuid : (string) $employee->id,
            'employee_number' => !empty($employee->employee_code) ? $employee->employee_code : ($employee->employee_number ?? ''),
            'full_name' => $employee->full_name ?? 'Employee',
            'nickname' => $employee->nickname ?? null,
            'display_name' => $displayName,
            'email' => $employee->email ?? '',
            'mobile_number' => $employee->mobile_number ?? $employee->phone ?? $employee->phone_work ?? $employee->phone_personal ?? null,
            'photo_url' => $employee->photo_url ?? null,
            'company' => $compName,
            'department' => $deptName,
            'designation' => $desigName,
            'location' => $locName,
            'location_name' => $locName,
            'joining_date' => $employee->joining_date ?? $employee->date_joined ?? null,
            'is_maintenance_staff' => ($maintRole !== null),
            'maintenance_role' => $maintRole,
            'is_cleaning_staff' => $isCleaningStaff,
            'can_view_cleaning_schedules' => $canViewCleaningSchedules,
        ];
    }
}
