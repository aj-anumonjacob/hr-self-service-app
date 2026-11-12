<?php

namespace App\Services;

use App\Models\AttendanceBreak;
use App\Models\Employee;
use App\Models\User;
use App\Models\CompanyBreakSetting;
use Carbon\Carbon;

/**
 * OverdueBreakProcessorService
 *
 * Processes active breaks that have passed their expected_end_at.
 * For each break it:
 *   1. Sends a persistent in-app notification (via InAppNotificationService)
 *   2. Sends a push notification (via PushNotificationService)
 *   3. Escalates to managers / admins at sequence 2 onwards
 *
 * All notifications use idempotency keys so worker retries are safe.
 */
class OverdueBreakProcessorService
{
    protected PushNotificationService  $pushService;
    protected BreakPolicyService       $policyService;
    protected InAppNotificationService $inAppService;
    protected EmailNotificationService $emailService;

    public function __construct(
        PushNotificationService  $pushService,
        BreakPolicyService       $policyService,
        InAppNotificationService $inAppService,
        EmailNotificationService $emailService
    ) {
        $this->pushService  = $pushService;
        $this->policyService = $policyService;
        $this->inAppService = $inAppService;
        $this->emailService = $emailService;
    }

    /**
     * Process all overdue active breaks
     */
    public function processOverdueBreaks(): int
    {
        $now = Carbon::now();

        $breaks = AttendanceBreak::with(['employee.user', 'employee.reportingManager.user', 'record', 'breakPolicy'])
            ->where('status', 'active')
            ->whereNull('end_time_server')
            ->whereNotNull('expected_end_at')
            ->where('expected_end_at', '<=', $now)
            ->whereNotIn('notification_series_status', ['stopped', 'limit_reached'])
            ->where(function ($q) use ($now) {
                $q->whereNull('next_notification_at')->orWhere('next_notification_at', '<=', $now);
            })
            ->get();

        $processedCount = 0;

        foreach ($breaks as $break) {
            $this->processSingleOverdueBreak($break, $now);
            $processedCount++;
        }

        return $processedCount;
    }

    /**
     * Process a single overdue break
     */
    public function processSingleOverdueBreak(AttendanceBreak $break, ?Carbon $now = null): void
    {
        $now      = $now ?: Carbon::now();
        $employee = $break->employee;
        if (!$employee) return;

        $settings = $this->policyService->getOrCreateCompanyBreakSettings($employee->company_id);

        // Set became_overdue_at on initial overdue trigger
        if (!$break->became_overdue_at) {
            $break->became_overdue_at = $break->expected_end_at;
            $break->notification_series_status = 'active';
        }

        $nextSeq = $break->overdue_notification_count + 1;

        // Safety valve: maximum notifications limit
        if ($nextSeq > $settings->max_repeat_notifications) {
            $break->update([
                'notification_series_status'     => 'limit_reached',
                'notification_series_stopped_at' => $now,
                'notification_series_stop_reason'=> 'MAX_ESCALATION_LIMIT_REACHED',
                'next_notification_at'           => null,
                'violation_code'                 => 'EXCEEDED_MAX_DURATION',
            ]);
            return;
        }

        // diffInSeconds() returns a signed value in this Carbon version (negative
        // when $now is after expected_end_at, which is always true for a real
        // overdue break) — abs() is required or every break silently computes as
        // permanently "1 minute overdue" via the max(0, negative) => 0 floor below.
        $overdueSeconds = max(0, abs($now->diffInSeconds($break->expected_end_at)));
        $overdueMinutes = (int) max(1, ceil($overdueSeconds / 60));

        // Resolve recipients (deduplicated)
        $recipients  = $this->resolveNotificationRecipients($employee);
        $employeeUser = $employee->user;

        // Compose messages per sequence
        if ($nextSeq === 1) {
            // Sequence 1: at expiry (0 minutes overdue)
            $empType  = InAppNotificationService::TYPE_BREAK_EXPIRED;
            $empTitle = 'Break time expired';
            $empBody  = 'Your break time has ended. Please end your break and resume work.';
            $mgrType  = null;
            $mgrTitle = null;
            $mgrBody  = null;
        } elseif ($nextSeq === 2) {
            // Sequence 2: manager/admin escalation begins
            $empType  = InAppNotificationService::TYPE_BREAK_OVERDUE_EMPLOYEE;
            $empTitle = 'Break time overdue';
            $empBody  = "Your break is {$overdueMinutes} minutes overdue. Please end your break and resume work immediately.";
            $mgrType  = InAppNotificationService::TYPE_BREAK_OVERDUE_MANAGER;
            $mgrTitle = 'Employee break overdue';
            $mgrBody  = "{$employee->full_name} has not ended their break and is {$overdueMinutes} minutes overdue.";
        } else {
            // Sequence N: repeat overdue escalation
            $empType  = InAppNotificationService::TYPE_BREAK_OVERDUE_EMPLOYEE;
            $empTitle = 'Break status still not updated';
            $empBody  = "Your break is {$overdueMinutes} minutes overdue. Please end your break and resume work.";
            if ($settings->repeat_manager_escalation) {
                $mgrType  = InAppNotificationService::TYPE_BREAK_OVERDUE_MANAGER;
                $mgrTitle = "Employee break still overdue ({$overdueMinutes}m)";
                $mgrBody  = "{$employee->full_name}'s break is {$overdueMinutes} minutes overdue and remains active.";
            } else {
                $mgrType  = null;
                $mgrTitle = null;
                $mgrBody  = null;
            }
        }

        // Email alerts (configured recipients + employee, if they have an
        // address on file) fire once as soon as the break crosses the
        // admin-configured "Overdue Break Alert Threshold" — independent of
        // the push/in-app escalation sequence above. Both methods are
        // idempotent per-break, so calling them on every processing pass is safe.
        $alertThresholdMinutes = $settings->overdue_alert_threshold_minutes ?: 15;
        if ($overdueMinutes >= $alertThresholdMinutes) {
            $this->emailService->sendOverdueBreakAlertToHR($break, $overdueMinutes);
            $this->emailService->sendOverdueBreakAlertToEmployee($break, $overdueMinutes);
        }

        // Navigation payload for employee
        $empNavData = [
            'navigation' => [
                'type'       => 'activeBreak',
                'break_uuid' => $break->uuid,
            ],
        ];

        // ── Employee notification ─────────────────────────────────────────────
        if ($employeeUser && ($nextSeq === 1 || $nextSeq === 2 || $settings->employee_repeat_enabled)) {
            $idempotencyKey = "break:{$break->id}:overdue:{$nextSeq}:{$employeeUser->id}";

            // 1. In-app (persistent)
            $this->inAppService->createNotification(
                $employeeUser,
                'employee',
                $empType,
                $empTitle,
                $empBody,
                $empNavData,
                $idempotencyKey
            );

            // 2. Push (additional channel)
            $this->pushService->sendOverdueBreakNotification(
                $break,
                $employeeUser,
                'employee',
                $nextSeq === 1 ? 'expired' : 'overdue',
                $nextSeq,
                $empTitle,
                $empBody,
                $idempotencyKey . '_push'
            );
        }

        // ── Manager & Admin notifications ─────────────────────────────────────
        if ($mgrTitle && $mgrBody && $mgrType) {
            foreach ($recipients['managers'] as $mgrUser) {
                $idempotencyKey = "break:{$break->id}:overdue:{$nextSeq}:{$mgrUser->id}";
                $mgrNavData = [
                    'navigation' => [
                        'type'        => 'employeeAttendance',
                        'break_uuid'  => $break->uuid,
                        'employee_id' => $employee->id,
                        'record_id'   => $break->attendance_record_id,
                    ],
                ];

                // In-app
                $this->inAppService->createNotification(
                    $mgrUser,
                    'manager',
                    $mgrType,
                    $mgrTitle,
                    $mgrBody,
                    $mgrNavData,
                    $idempotencyKey
                );

                // Push
                $this->pushService->sendOverdueBreakNotification(
                    $break,
                    $mgrUser,
                    'manager',
                    'manager_escalation',
                    $nextSeq,
                    $mgrTitle,
                    $mgrBody,
                    $idempotencyKey . '_push'
                );
            }

            foreach ($recipients['administrators'] as $adminUser) {
                $idempotencyKey = "break:{$break->id}:overdue:{$nextSeq}:{$adminUser->id}";
                $adminNavData = [
                    'navigation' => [
                        'type'        => 'employeeAttendance',
                        'break_uuid'  => $break->uuid,
                        'employee_id' => $employee->id,
                        'record_id'   => $break->attendance_record_id,
                    ],
                ];

                // In-app
                $this->inAppService->createNotification(
                    $adminUser,
                    'administrator',
                    InAppNotificationService::TYPE_BREAK_OVERDUE_ADMIN,
                    $mgrTitle,
                    $mgrBody,
                    $adminNavData,
                    $idempotencyKey
                );

                // Push
                $this->pushService->sendOverdueBreakNotification(
                    $break,
                    $adminUser,
                    'administrator',
                    'admin_escalation',
                    $nextSeq,
                    $mgrTitle,
                    $mgrBody,
                    $idempotencyKey . '_push'
                );
            }
        }

        // Calculate next notification timestamp
        $nextInterval      = $settings->repeat_interval_minutes > 0 ? $settings->repeat_interval_minutes : 10;
        $nextNotificationAt = $now->copy()->addMinutes($nextInterval);

        $break->update([
            'became_overdue_at'           => $break->became_overdue_at ?: $break->expected_end_at,
            'overdue_notification_count'  => $nextSeq,
            'escalation_level'            => $nextSeq,
            'notification_series_status'  => 'active',
            'next_notification_at'        => $nextNotificationAt,
            'violation_code'              => 'EXCEEDED_MAX_DURATION',
        ]);
    }

    /**
     * Resolve and deduplicate recipient users for manager and admin notifications
     */
    public function resolveNotificationRecipients(Employee $employee): array
    {
        $managerUsers = collect();
        $adminUsers   = collect();

        // 1. Direct Reporting Manager
        if ($employee->reportingManager && $employee->reportingManager->user) {
            $managerUsers->push($employee->reportingManager->user);
        }

        // 2. Fallback: Department or Location Manager
        if ($managerUsers->isEmpty()) {
            if ($employee->department && $employee->department->manager && $employee->department->manager->user) {
                $managerUsers->push($employee->department->manager->user);
            }
            if ($employee->defaultLocation && $employee->defaultLocation->manager && $employee->defaultLocation->manager->user) {
                $managerUsers->push($employee->defaultLocation->manager->user);
            }
        }

        // 3. Authorized Administrators
        $admins = User::whereHas('roles', function ($q) {
            $q->whereIn('slug', ['admin', 'manager', 'hr']);
        })->get();

        foreach ($admins as $admin) {
            // Deduplicate: skip if already listed as manager
            if (!$managerUsers->contains('id', $admin->id)) {
                $adminUsers->push($admin);
            }
        }

        return [
            'managers'       => $managerUsers->unique('id')->values(),
            'administrators' => $adminUsers->unique('id')->values(),
        ];
    }
}
