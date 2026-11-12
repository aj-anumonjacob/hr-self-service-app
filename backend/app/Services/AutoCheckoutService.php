<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AttendanceEvent;
use App\Models\AttendancePolicySetting;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoCheckoutService
{
    protected InAppNotificationService $inAppNotificationService;

    public function __construct(InAppNotificationService $inAppNotificationService)
    {
        $this->inAppNotificationService = $inAppNotificationService;
    }

    /**
     * Process automatic checkout for all open attendance records past cutoff.
     */
    public function processAutoCheckouts(): int
    {
        $openRecords = AttendanceRecord::whereNotNull('check_in_event_id')
            ->whereNull('check_out_event_id')
            ->with(['employee.company', 'employee.user', 'employee.assignedShift'])
            ->get();

        $autoCheckoutCount = 0;

        foreach ($openRecords as $record) {
            $employee = $record->employee;
            if (!$employee) continue;

            $company = $employee->company;
            $tz = $company ? $company->timezone : 'Asia/Dubai';
            $nowLocal = Carbon::now($tz);

            $policySettings = AttendancePolicySetting::forCompany($employee->company_id);
            if (!$policySettings->auto_checkout_enabled) {
                continue;
            }

            $recordDateStr = is_string($record->date) ? $record->date : ($record->date ? $record->date->format('Y-m-d') : Carbon::now($tz)->toDateString());

            // Exclude overnight shifts whose valid scheduled shift end extends past midnight into the current day
            $shift = $employee->assignedShift;
            if ($shift) {
                $shiftStart = Carbon::parse("{$recordDateStr} {$shift->start_time}", $tz);
                $shiftEnd   = Carbon::parse("{$recordDateStr} {$shift->end_time}", $tz);
                if ($shiftEnd->lt($shiftStart)) {
                    $shiftEnd->addDay();
                    // If current local time is still within valid overnight shift window (plus 2 hrs grace), do not auto-checkout yet
                    if ($nowLocal->lt($shiftEnd->copy()->addHours(2))) {
                        continue;
                    }
                }
            }

            // Company cutoff time on the attendance record date
            $cutoffTimeStr = $policySettings->auto_checkout_cutoff_time ?: '23:59:00';
            $cutoffLocal   = Carbon::parse("{$recordDateStr} {$cutoffTimeStr}", $tz);

            // If current local time has passed cutoff time, execute automatic checkout
            if ($nowLocal->gte($cutoffLocal)) {
                DB::transaction(function () use ($record, $employee, $cutoffLocal, &$autoCheckoutCount) {
                    // Create system_auto_checkout AttendanceEvent
                    $checkoutEvt = AttendanceEvent::create([
                        'employee_id' => $employee->id,
                        'attendance_record_id' => $record->id,
                        'event_type' => 'ATTENDANCE_CHECKED_OUT',
                        'event_time_server' => $cutoffLocal->copy()->setTimezone('UTC'),
                        'event_source' => 'system_auto_checkout',
                        'actor_role' => 'system',
                        'device_id' => 'system_auto_checkout',
                        'metadata' => [
                            'auto_checkout' => true,
                            'cutoff_time' => $cutoffLocal->toIso8601String(),
                            'reason' => 'No manual checkout recorded before company cutoff time.'
                        ]
                    ]);

                    // Calculate total worked duration (excluding unpaid breaks)
                    $checkInUtc = Carbon::parse($record->check_in_time);
                    $checkOutUtc = $cutoffLocal->copy()->setTimezone('UTC');
                    $totalWorkedSeconds = max(0, $checkOutUtc->diffInSeconds($checkInUtc));

                    $unpaidBreakSeconds = $record->breaks()
                        ->where('status', 'completed')
                        ->whereHas('breakPolicy', function ($q) {
                            $q->where('is_paid', false);
                        })
                        ->sum('duration_seconds');

                    $qualifyingWorkedSeconds = max(0, $totalWorkedSeconds - $unpaidBreakSeconds);

                    // Update AttendanceRecord
                    $record->update([
                        'check_out_event_id' => $checkoutEvt->id,
                        'total_duration_minutes' => (int) ceil($qualifyingWorkedSeconds / 60),
                        'net_duration_minutes' => (int) ceil($qualifyingWorkedSeconds / 60),
                        'status' => 'present'
                    ]);

                    // Create AuditLog
                    AuditLog::create([
                        'user_id' => null,
                        'company_id' => $employee->company_id,
                        'action' => 'SYSTEM_AUTO_CHECKOUT',
                        'auditable_type' => AttendanceRecord::class,
                        'auditable_id' => $record->id,
                        'old_values' => json_encode(['check_out_time' => null]),
                        'new_values' => json_encode([
                            'check_out_time' => $checkOutUtc->toIso8601String(),
                            'event_source' => 'system_auto_checkout'
                        ])
                    ]);

                    // Send in-app notification to employee
                    if ($employee->user) {
                        $idempotencyKey = "auto-checkout:{$record->id}:{$record->date}";
                        $formattedCutoff = $cutoffLocal->format('h:i A');
                        $this->inAppNotificationService->createNotification(
                            $employee->user,
                            'employee',
                            'AUTO_CHECKOUT',
                            'Automatically checked out',
                            "You were automatically checked out at {$formattedCutoff} because no checkout was recorded. Submit an attendance claim if this time is incorrect.",
                            ['attendance_record_id' => $record->id, 'date' => $record->date],
                            $idempotencyKey
                        );
                    }

                    $autoCheckoutCount++;
                });
            }
        }

        return $autoCheckoutCount;
    }
}
