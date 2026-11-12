<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\Shift;
use Carbon\Carbon;

class AttendancePolicyService
{
    /**
     * Resolve the active policy details for an employee on a given date/time
     */
    public function resolveActivePolicy(Employee $employee, string $dateTime = null): array
    {
        $now = $dateTime ? Carbon::parse($dateTime) : Carbon::now();
        $date = $now->toDateString();
        
        // An assignment is "currently active" if it started and either has no end
        // (assignment_type = 'secondary', ongoing) or hasn't ended yet ('temporary').
        $activeAssignmentScope = function ($query) use ($now) {
            $query->where('status', 'active')
                ->where('start_time', '<=', $now)
                ->where(function ($q) use ($now) {
                    $q->whereNull('end_time')->orWhere('end_time', '>=', $now);
                });
        };

        // 1. Resolve Active Assignment — prefer a time-bounded temporary assignment
        // (more specific "you must be here right now") over an ongoing secondary one.
        $assignment = $employee->assignments()
            ->where($activeAssignmentScope)
            ->orderByRaw("assignment_type = 'temporary' DESC")
            ->orderByDesc('created_at')
            ->first();

        // 2. Determine Primary Location & Shift
        $location = $assignment ? $assignment->assignedLocation : ($employee->defaultLocation ?: WorkLocation::where('status', 'active')->first());
        $shift = $assignment && $assignment->assignedShift ? $assignment->assignedShift : $employee->getActiveShiftForDate($date);

        // 3. Resolve ALL active assigned locations (secondary + temporary) — an
        // employee may legitimately check in/out from any of these, not just
        // the single "primary" one resolved above.
        $locationsList = [];
        $activeAssignments = $employee->assignments()
            ->where($activeAssignmentScope)
            ->with(['assignedLocation'])
            ->get();

        foreach ($activeAssignments as $ass) {
            if ($ass->assignedLocation) {
                $locationsList[$ass->assignedLocation->uuid] = [
                    'uuid' => $ass->assignedLocation->uuid,
                    'name' => $ass->assignedLocation->name,
                    'latitude' => $ass->assignedLocation->latitude,
                    'longitude' => $ass->assignedLocation->longitude,
                    'check_in_radius' => $ass->assignedLocation->check_in_radius,
                    'checkout_radius' => $ass->assignedLocation->checkout_radius,
                    'is_temporary' => $ass->assignment_type === 'temporary',
                ];
            }
        }
        
        $defaultLoc = $employee->defaultLocation ?: WorkLocation::where('status', 'active')->first();
        if ($defaultLoc && !isset($locationsList[$defaultLoc->uuid])) {
            $locationsList[$defaultLoc->uuid] = [
                'uuid' => $defaultLoc->uuid,
                'name' => $defaultLoc->name,
                'latitude' => $defaultLoc->latitude,
                'longitude' => $defaultLoc->longitude,
                'check_in_radius' => $defaultLoc->check_in_radius,
                'checkout_radius' => $defaultLoc->checkout_radius,
                'is_temporary' => false,
            ];
        }
        
        // 4. Resolve Background Tracking Policy
        $trackingPolicy = 'disabled';
        if ($assignment && $assignment->tracking_policy) {
            $trackingPolicy = $assignment->tracking_policy;
        } elseif ($location && $location->background_tracking_policy) {
            $trackingPolicy = $location->background_tracking_policy;
        }
        
        // 5. Resolve Offline Policy
        $offlinePolicy = 'enabled';
        if ($assignment && $assignment->offline_policy) {
            $offlinePolicy = $assignment->offline_policy;
        } elseif ($location && $location->offline_attendance_policy) {
            $offlinePolicy = $location->offline_attendance_policy;
        }
        
        // 6. Resolve Weekly Off Days — prefer the active shift's own working-days
        // configuration (HR portal > Shift Management), since that's specific to
        // the schedule the employee is actually on. Falls back to a company-wide
        // WeeklyOffRule, then Sunday-only, for shifts that don't define it.
        $weeklyOffDays = [0]; // Default 0 = Sunday
        if ($shift && !empty($shift->working_days) && is_array($shift->working_days)) {
            $workingDays = array_map('intval', $shift->working_days);
            $weeklyOffDays = array_values(array_diff(range(0, 6), $workingDays));
        } elseif ($employee->weeklyOffRule && is_array($employee->weeklyOffRule->days)) {
            $weeklyOffDays = array_map('intval', $employee->weeklyOffRule->days);
        }
        
        return [
            'assignment' => $assignment ? [
                'uuid' => $assignment->uuid,
                'type' => $assignment->assignment_type,
                'reason' => $assignment->reason
            ] : null,
            'location' => $location ? [
                'uuid' => $location->uuid,
                'name' => $location->name,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'check_in_radius' => $location->check_in_radius,
                'checkout_radius' => $location->checkout_radius
            ] : null,
            'locations' => array_values($locationsList),
            'shift' => $shift ? [
                'uuid' => $shift->uuid,
                'name' => $shift->name,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
                'late_threshold' => $shift->late_threshold_minutes
            ] : null,
            'weekly_off_days' => $weeklyOffDays,
            'tracking_policy' => $trackingPolicy,
            'offline_policy' => $offlinePolicy
        ];
    }
}
