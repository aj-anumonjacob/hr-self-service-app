<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\AttendancePolicySetting;
use App\Models\ScheduledReminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ShiftReminderService
{
    protected InAppNotificationService $inAppNotificationService;
    protected EmailNotificationService $emailNotificationService;
    protected PushNotificationService $pushNotificationService;

    public function __construct(
        InAppNotificationService $inAppNotificationService,
        EmailNotificationService $emailNotificationService,
        PushNotificationService $pushNotificationService
    ) {
        $this->inAppNotificationService = $inAppNotificationService;
        $this->emailNotificationService = $emailNotificationService;
        $this->pushNotificationService = $pushNotificationService;
    }

    /**
     * Process check-in and check-out reminders for all active employees on date.
     */
    public function processRemindersForDate(string $dateStr): int
    {
        $employees = Employee::where('status', 'active')->with(['assignedShift', 'company', 'user', 'reportingManager'])->get();
        $processedCount = 0;

        foreach ($employees as $employee) {
            $shift = $employee->assignedShift;
            if (!$shift) continue;

            $tz = $employee->company ? $employee->company->timezone : 'Asia/Dubai';
            $nowLocal = Carbon::now($tz);
            $policySettings = AttendancePolicySetting::forCompany($employee->company_id);

            // Shift start & end local times
            $shiftStartLocal = Carbon::parse("{$dateStr} {$shift->start_time}", $tz);
            $shiftEndLocal   = Carbon::parse("{$dateStr} {$shift->end_time}", $tz);
            if ($shiftEndLocal->lt($shiftStartLocal)) {
                // Overnight shift end crosses midnight
                $shiftEndLocal->addDay();
            }

            // Check existing record
            $record = AttendanceRecord::where('employee_id', $employee->id)
                ->where('date', $dateStr)
                ->first();

            $hasCheckedIn = $record && $record->check_in_event_id;
            $hasCheckedOut = $record && $record->check_out_event_id;

            // Check approved exceptions
            $approvedLateRequest = AttendanceRequest::where('employee_id', $employee->id)
                ->where('date', $dateStr)
                ->where('status', 'approved')
                ->where('request_type', 'late_arrival')
                ->first();

            $approvedMorningHalfDay = AttendanceRequest::where('employee_id', $employee->id)
                ->where('date', $dateStr)
                ->where('status', 'approved')
                ->where('request_type', 'morning_half_day')
                ->first();

            // ── CHECK-IN REMINDERS ─────────────────────────────────────────────
            if (!$hasCheckedIn) {
                // 1. Upcoming reminder (5 min before shift start or 5 min before approved arrival)
                $targetStart = $approvedLateRequest && $approvedLateRequest->expected_arrival_at
                    ? Carbon::parse($approvedLateRequest->expected_arrival_at, $tz)
                    : $shiftStartLocal;

                if (!$approvedMorningHalfDay) {
                    $upcomingTime = $targetStart->copy()->addMinutes($policySettings->checkin_reminder_upcoming_minutes);
                    if ($nowLocal->gte($upcomingTime) && $nowLocal->lt($targetStart)) {
                        $this->sendReminder(
                            $employee,
                            $shift,
                            $dateStr,
                            'checkin_upcoming',
                            $upcomingTime,
                            'Upcoming check-in',
                            'Your shift starts in 5 minutes. Don’t forget to check in.',
                            'employee'
                        );
                        $processedCount++;
                    }

                    // 2. Exact shift start / arrival time
                    if ($policySettings->checkin_reminder_due_enabled && $nowLocal->gte($targetStart) && $nowLocal->lt($targetStart->copy()->addMinutes($policySettings->checkin_reminder_late_escalation_minutes))) {
                        $this->sendReminder(
                            $employee,
                            $shift,
                            $dateStr,
                            'checkin_due',
                            $targetStart,
                            'Time to check in',
                            'Your shift has started. Please check in now.',
                            'employee'
                        );
                        $processedCount++;
                    }

                    // 3. Late escalation (10 min after shift start / approved arrival)
                    $lateEscalationTime = $targetStart->copy()->addMinutes($policySettings->checkin_reminder_late_escalation_minutes);
                    if ($nowLocal->gte($lateEscalationTime)) {
                        // Employee notification
                        $this->sendReminder(
                            $employee,
                            $shift,
                            $dateStr,
                            'checkin_late',
                            $lateEscalationTime,
                            'Check-in overdue',
                            'You have not checked in and your attendance is now marked late. Please check in or submit a late/half-day request.',
                            'employee',
                            true
                        );

                        // Manager notification
                        $manager = $employee->reportingManager;
                        $managerUser = $manager ? $manager->user : null;
                        if (!$managerUser && $employee->company) {
                            $managerUser = User::whereHas('roles', function ($q) {
                                $q->whereIn('slug', ['admin', 'manager', 'hr']);
                            })->first();
                        }

                        if ($managerUser) {
                            $idempotencyKey = "shift:{$shift->id}:checkin-late-mgr:{$employee->id}:{$dateStr}";
                            $existing = ScheduledReminder::where('idempotency_key', $idempotencyKey)->first();
                            if (!$existing) {
                                ScheduledReminder::create([
                                    'employee_id' => $employee->id,
                                    'shift_id' => $shift->id,
                                    'shift_date' => $dateStr,
                                    'reminder_type' => 'checkin_late_manager',
                                    'scheduled_for' => $lateEscalationTime,
                                    'status' => 'sent',
                                    'idempotency_key' => $idempotencyKey,
                                    'sent_at' => Carbon::now()
                                ]);

                                $this->inAppNotificationService->createNotification(
                                    $managerUser,
                                    'manager',
                                    'CHECKIN_REMINDER_OVERDUE_MANAGER',
                                    'Employee check-in overdue',
                                    "{$employee->full_name} has not checked in within the permitted time after the scheduled shift start.",
                                    ['employee_id' => $employee->id, 'date' => $dateStr],
                                    $idempotencyKey
                                );
                            }
                        }
                        $processedCount++;
                    }
                }
            }

            // ── CHECK-OUT REMINDERS ────────────────────────────────────────────
            if ($hasCheckedIn && !$hasCheckedOut) {
                // 1. Upcoming checkout reminder (5 min before shift end)
                $checkoutUpcomingTime = $shiftEndLocal->copy()->addMinutes($policySettings->checkout_reminder_upcoming_minutes);
                if ($nowLocal->gte($checkoutUpcomingTime) && $nowLocal->lt($shiftEndLocal)) {
                    $this->sendReminder(
                        $employee,
                        $shift,
                        $dateStr,
                        'checkout_upcoming',
                        $checkoutUpcomingTime,
                        'Shift ending soon',
                        'Your shift ends in 5 minutes. Don’t forget to check out before leaving.',
                        'employee'
                    );
                    $processedCount++;
                }

                // 2. Exact shift end
                if ($policySettings->checkout_reminder_due_enabled && $nowLocal->gte($shiftEndLocal) && $nowLocal->lt($shiftEndLocal->copy()->addMinutes($policySettings->checkout_reminder_overdue_minutes))) {
                    $this->sendReminder(
                        $employee,
                        $shift,
                        $dateStr,
                        'checkout_due',
                        $shiftEndLocal,
                        'Time to check out',
                        'Your shift has ended. Please check out now.',
                        'employee'
                    );
                    $processedCount++;
                }

                // 3. Overdue checkout (10 min after shift end)
                $checkoutOverdueTime = $shiftEndLocal->copy()->addMinutes($policySettings->checkout_reminder_overdue_minutes);
                if ($nowLocal->gte($checkoutOverdueTime)) {
                    $this->sendReminder(
                        $employee,
                        $shift,
                        $dateStr,
                        'checkout_overdue',
                        $checkoutOverdueTime,
                        'Check-out overdue',
                        'You are still checked in 10 minutes after your shift ended. Don’t forget to check out.',
                        'employee',
                        true
                    );
                    $processedCount++;
                }
            }
        }

        return $processedCount;
    }

    protected function sendReminder(
        Employee $employee,
        $shift,
        string $dateStr,
        string $type,
        Carbon $scheduledFor,
        string $title,
        string $message,
        string $recipientRole,
        bool $sendEmail = false
    ): void {
        $user = $employee->user;
        if (!$user) return;

        $idempotencyKey = "shift:{$shift->id}:{$type}:{$employee->id}:{$dateStr}";
        $existing = ScheduledReminder::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) return;

        ScheduledReminder::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'shift_date' => $dateStr,
            'reminder_type' => $type,
            'scheduled_for' => $scheduledFor,
            'status' => 'sent',
            'idempotency_key' => $idempotencyKey,
            'sent_at' => Carbon::now()
        ]);

        $this->inAppNotificationService->createNotification(
            $user,
            $recipientRole,
            strtoupper($type),
            $title,
            $message,
            ['shift_id' => $shift->id, 'date' => $dateStr],
            $idempotencyKey
        );

        // Real FCM push so the employee is alerted even when the app is
        // closed — the in-app notification above only surfaces while the
        // app is open and polling. Best-effort: a missing/dead push token
        // never blocks the in-app notification or email from above.
        try {
            $this->pushNotificationService->sendToEmployee(
                $employee,
                $title,
                $message,
                ['type' => $type, 'shift_id' => $shift->id, 'date' => $dateStr]
            );
        } catch (\Throwable $e) {
            Log::error("Failed to send shift reminder push for employee {$employee->id}: " . $e->getMessage());
        }

        // Missed check-in/check-out only (not the "upcoming/due" nudges): also
        // email the employee directly, if they have an address on file.
        if ($sendEmail) {
            try {
                $this->emailNotificationService->sendMissedShiftAlertToEmployee(
                    $employee,
                    $type,
                    "{$shift->name} ({$shift->start_time} - {$shift->end_time})",
                    $dateStr,
                    $scheduledFor->format('h:i A'),
                    $message
                );
            } catch (\Throwable $e) {
                Log::error("Failed to send missed shift email for employee {$employee->id}: " . $e->getMessage());
            }
        }
    }
}
