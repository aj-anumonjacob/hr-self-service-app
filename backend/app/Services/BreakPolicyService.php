<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\BreakPolicy;
use App\Models\BreakPolicyAssignment;
use App\Models\CompanyBreakSetting;
use Carbon\Carbon;

class BreakPolicyService
{
    /**
     * Resolve effective break policy for an employee based on priority:
     * 1. Employee assignment
     * 2. Shift assignment
     * 3. Department assignment
     * 4. Company default flexible policy
     */
    public function resolveEffectivePolicy(Employee $employee, ?string $dateStr = null): BreakPolicy
    {
        $today = $dateStr ? Carbon::parse($dateStr)->toDateString() : Carbon::now()->toDateString();
        $companyId = $employee->company_id;

        // Active assignments valid for today
        $assignmentsQuery = BreakPolicyAssignment::with('breakPolicy')
            ->whereHas('breakPolicy', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->where('is_active', true);
            })
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today);
            });

        $assignments = $assignmentsQuery->get();

        // Priority 1: Employee
        $empAssignment = $assignments->where('assignable_type', 'employee')
            ->where('assignable_id', $employee->id)
            ->first();
        if ($empAssignment && $empAssignment->breakPolicy) {
            return $empAssignment->breakPolicy;
        }

        // Priority 2: Shift
        if ($employee->assigned_shift_id) {
            $shiftAssignment = $assignments->where('assignable_type', 'shift')
                ->where('assignable_id', $employee->assigned_shift_id)
                ->first();
            if ($shiftAssignment && $shiftAssignment->breakPolicy) {
                return $shiftAssignment->breakPolicy;
            }
        }

        // Priority 3: Department
        if ($employee->department_id) {
            $deptAssignment = $assignments->where('assignable_type', 'department')
                ->where('assignable_id', $employee->department_id)
                ->first();
            if ($deptAssignment && $deptAssignment->breakPolicy) {
                return $deptAssignment->breakPolicy;
            }
        }

        // Priority 4: Company Default Assignment
        $companyAssignment = $assignments->where('assignable_type', 'company')
            ->where('assignable_id', $companyId)
            ->first();
        if ($companyAssignment && $companyAssignment->breakPolicy) {
            return $companyAssignment->breakPolicy;
        }

        // Fallback: Get or create Company Default Flexible Policy
        return $this->getOrCreateCompanyDefaultPolicy($companyId);
    }

    /**
     * Get or create default company flexible policy
     */
    public function getOrCreateCompanyDefaultPolicy(int $companyId): BreakPolicy
    {
        $policy = BreakPolicy::where('company_id', $companyId)
            ->where('policy_type', 'flexible')
            ->where('is_active', true)
            ->first();

        if (!$policy) {
            // Seed a newly-onboarded company with the group-wide default configured
            // in the HR portal's Break Policies panel, rather than hardcoded values,
            // so it stays consistent with every other company until overridden.
            $groupDefaults = \Illuminate\Support\Facades\DB::table('company_break_settings')->first();

            $policy = BreakPolicy::create([
                'company_id' => $companyId,
                'name' => 'Flexible Break',
                'policy_type' => 'flexible',
                'start_time' => null,
                'end_time' => null,
                'allowed_duration_minutes' => $groupDefaults->max_break_duration_minutes ?? 60,
                'max_breaks_per_shift' => $groupDefaults->max_breaks_per_day ?? 1,
                'is_paid' => (bool) ($groupDefaults->is_paid_default ?? false),
                'requires_approval' => false,
                'is_active' => true
            ]);

            BreakPolicyAssignment::create([
                'break_policy_id' => $policy->id,
                'assignable_type' => 'company',
                'assignable_id' => $companyId,
                'effective_from' => Carbon::now()->subYears(1)->toDateString(),
                'effective_until' => null
            ]);
        }

        return $policy;
    }

    /**
     * Get or create company notification settings
     */
    public function getOrCreateCompanyBreakSettings(int $companyId): CompanyBreakSetting
    {
        return CompanyBreakSetting::firstOrCreate(
            ['company_id' => $companyId],
            [
                'initial_overdue_enabled' => true,
                'initial_delay_minutes' => 0,
                'repeat_interval_minutes' => 10,
                'employee_repeat_enabled' => true,
                'manager_escalation_delay_minutes' => 10,
                'admin_escalation_delay_minutes' => 10,
                'repeat_manager_escalation' => true,
                'max_repeat_notifications' => 12,
                'push_enabled' => true,
                'in_app_enabled' => true,
                'max_breaks_per_day' => 3,
                'max_break_duration_minutes' => 60,
                'overdue_alert_threshold_minutes' => 15,
                'is_paid_default' => false,
            ]
        );
    }
}
