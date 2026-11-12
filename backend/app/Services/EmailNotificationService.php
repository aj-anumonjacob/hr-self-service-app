<?php

namespace App\Services;

use App\Models\AttendanceRequest;
use App\Models\AttendanceClaim;
use App\Models\EmailNotificationLog;
use App\Models\Employee;
use App\Models\SecurityConfiguration;
use App\Models\User;
use App\Support\EmailTemplates;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    /**
     * Send email to reporting manager when a late/half-day request is submitted.
     */
    public function sendRequestSubmittedToManager(AttendanceRequest $request): ?EmailNotificationLog
    {
        $manager = $request->reportingManager;
        $managerUser = $manager ? $manager->user : null;
        $recipientEmail = $manager ? $manager->email : ($managerUser ? $managerUser->email : null);
        $recipientName = $manager ? $manager->full_name : ($managerUser ? $managerUser->name : 'Manager');

        // Fallback to company admin if reporting manager is unassigned
        if (!$recipientEmail && $request->company) {
            $adminUser = User::whereHas('roles', function ($q) {
                $q->whereIn('slug', ['admin', 'manager', 'hr']);
            })->first();
            if ($adminUser) {
                $recipientEmail = $adminUser->email;
                $recipientName = $adminUser->name;
            }
        }

        if (!$recipientEmail) {
            Log::warning("Could not resolve manager email for AttendanceRequest UUID: {$request->uuid}");
            return null;
        }

        $idempotencyKey = "email:request:{$request->id}:submitted:{$request->status}";
        $existing = EmailNotificationLog::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $employee = $request->employee;
        $empName = $employee ? $employee->full_name : 'Employee';
        $dateStr = $request->date ? $request->date->format('Y-m-d') : Carbon::now()->toDateString();
        $typeLabel = $request->request_type === 'morning_half_day' ? 'Morning Half-Day' : 'Late Arrival';
        $shiftStr = $request->shift ? "{$request->shift->name} ({$request->shift->start_time} - {$request->shift->end_time})" : 'Standard Shift';
        $arrivalStr = $request->expected_arrival_at ? $request->expected_arrival_at->format('h:i A') : "Delay: {$request->delay_minutes} mins";
        $reasonLabel = ucwords(str_replace('_', ' ', $request->reason_code));
        $descriptionStr = htmlspecialchars($request->description ?? 'N/A');

        $subject = "Attendance Request from {$empName} — {$dateStr}";
        $body = "Subject: {$subject}\n\nDear {$recipientName},\n\n{$empName} has submitted a {$typeLabel} request for {$dateStr}.\n\nScheduled shift:\n{$shiftStr}\n\nExpected arrival:\n{$arrivalStr}\n\nReason:\n{$reasonLabel}\n\nEmployee note:\n{$descriptionStr}\n\nPlease review this request in the Attendance Manager Portal.\n\nRegards,\nAttendance Management System";

        $log = EmailNotificationLog::create([
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'template_name' => 'attendance_request_submitted',
            'related_type' => 'attendance_request',
            'related_id' => $request->id,
            'subject' => $subject,
            'body_text' => $body,
            'delivery_status' => 'sent',
            'attempted_at' => Carbon::now(),
            'sent_at' => Carbon::now(),
            'idempotency_key' => $idempotencyKey
        ]);

        return $log;
    }

    /**
     * Notify configured recipients (default: support@example.com, configurable
     * from the HR portal's Attendance App > Configuration tab) when an employee
     * submits an attendance claim — including quick-claims for a missed
     * check-in/check-out raised from the mobile dashboard.
     */
    public function sendClaimSubmittedToManager(AttendanceClaim $claim): ?EmailNotificationLog
    {
        $recipients = SecurityConfiguration::getVal('notification_recipients', ['support@example.com']);
        if (!is_array($recipients) || empty($recipients)) {
            $recipients = ['support@example.com'];
        }
        $senderEmail = SecurityConfiguration::getVal('sender_email', 'support@example.com');

        $idempotencyKey = "email:claim:{$claim->id}:submitted:{$claim->status}";
        $existing = EmailNotificationLog::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $employee = $claim->employee;
        $empName = $employee ? $employee->full_name : 'Employee';
        $empCode = $employee ? ($employee->employee_code ?? $employee->employee_number ?? (string) $employee->id) : '—';
        $dateStr = $claim->date ? $claim->date->format('Y-m-d') : Carbon::now()->toDateString();

        $mail = EmailTemplates::attendanceClaimSubmitted([
            'employee_name' => $empName,
            'employee_code' => $empCode,
            'claim_type_label' => ucwords(str_replace('_', ' ', $claim->claim_type)),
            'date' => $dateStr,
            'recorded_check_in' => $this->formatClaimDateTime($claim->recorded_check_in_at, 'h:i A'),
            'recorded_check_out' => $this->formatClaimDateTime($claim->recorded_check_out_at, 'h:i A'),
            'requested_check_in' => $this->formatClaimDateTime($claim->requested_check_in_at, 'h:i A'),
            'requested_check_out' => $this->formatClaimDateTime($claim->requested_check_out_at, 'h:i A'),
            'reason_label' => ucwords(str_replace('_', ' ', $claim->reason_code ?? 'other')),
            'description' => $claim->description,
            'submitted_at' => $this->formatClaimDateTime($claim->submitted_at, 'D, M j, Y g:i A') ?? Carbon::now()->toDayDateTimeString(),
        ], $senderEmail);

        $isSent = false;
        $failureReason = null;

        try {
            Mail::html($mail['html'], function ($message) use ($recipients, $senderEmail, $mail) {
                $message->to($recipients)
                        ->from($senderEmail, 'Your Company Attendance App')
                        ->subject($mail['subject']);
            });
            $isSent = true;
        } catch (\Throwable $e) {
            $failureReason = $e->getMessage();
            Log::error("Failed to send attendance claim email for claim #{$claim->id}: {$failureReason}");
        }

        $log = EmailNotificationLog::create([
            'recipient_email' => implode(',', $recipients),
            'recipient_name' => 'Support / IT',
            'template_name' => 'attendance_claim_submitted',
            'related_type' => 'attendance_claim',
            'related_id' => $claim->id,
            'subject' => $mail['subject'],
            'body_text' => "{$empName} ({$empCode}) submitted a " . ucwords(str_replace('_', ' ', $claim->claim_type)) . " claim for {$dateStr}.",
            'delivery_status' => $isSent ? 'sent' : 'failed',
            'attempted_at' => Carbon::now(),
            'sent_at' => $isSent ? Carbon::now() : null,
            'failure_reason' => $failureReason,
            'idempotency_key' => $idempotencyKey
        ]);

        return $log;
    }

    /**
     * Notify configured recipients (default: support@example.com, configurable
     * from the HR portal's Attendance App > Configuration tab) when an employee
     * submits a leave request from the mobile app.
     *
     * Accepts a plain array (LeaveController queries via DB::table(), not Eloquent)
     * with keys: id, employee_name, employee_code, leave_type_name, date_from,
     * date_to, days, reason, has_attachment, created_at.
     */
    public function sendLeaveRequestSubmitted(array $leaveData): ?EmailNotificationLog
    {
        $recipients = SecurityConfiguration::getVal('notification_recipients', ['support@example.com']);
        if (!is_array($recipients) || empty($recipients)) {
            $recipients = ['support@example.com'];
        }
        $senderEmail = SecurityConfiguration::getVal('sender_email', 'support@example.com');

        $requestId = $leaveData['id'] ?? null;
        $idempotencyKey = "email:leave_request:{$requestId}:submitted";
        $existing = EmailNotificationLog::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $empName = $leaveData['employee_name'] ?? 'Employee';
        $empCode = $leaveData['employee_code'] ?? '—';
        $leaveType = $leaveData['leave_type_name'] ?? 'Leave';

        $mail = EmailTemplates::leaveRequestSubmitted([
            'employee_name' => $empName,
            'employee_code' => $empCode,
            'leave_type_name' => $leaveType,
            'date_from' => $leaveData['date_from'] ?? null,
            'date_to' => $leaveData['date_to'] ?? null,
            'days' => $leaveData['days'] ?? null,
            'reason' => $leaveData['reason'] ?? null,
            'has_attachment' => $leaveData['has_attachment'] ?? false,
            'submitted_at' => $this->formatClaimDateTime($leaveData['created_at'] ?? null, 'D, M j, Y g:i A') ?? Carbon::now()->toDayDateTimeString(),
        ], $senderEmail);

        $isSent = false;
        $failureReason = null;

        try {
            Mail::html($mail['html'], function ($message) use ($recipients, $senderEmail, $mail) {
                $message->to($recipients)
                        ->from($senderEmail, 'Your Company Attendance App')
                        ->subject($mail['subject']);
            });
            $isSent = true;
        } catch (\Throwable $e) {
            $failureReason = $e->getMessage();
            Log::error("Failed to send leave request email for request #{$requestId}: {$failureReason}");
        }

        return EmailNotificationLog::create([
            'recipient_email' => implode(',', $recipients),
            'recipient_name' => 'Support / IT',
            'template_name' => 'leave_request_submitted',
            'related_type' => 'leave_request',
            'related_id' => $requestId,
            'subject' => $mail['subject'],
            'body_text' => "{$empName} ({$empCode}) submitted a {$leaveType} request.",
            'delivery_status' => $isSent ? 'sent' : 'failed',
            'attempted_at' => Carbon::now(),
            'sent_at' => $isSent ? Carbon::now() : null,
            'failure_reason' => $failureReason,
            'idempotency_key' => $idempotencyKey
        ]);
    }

    /**
     * Send a missed check-in/check-out alert directly to the employee's own
     * inbox, only if they have a valid email on file. Mirrors the in-app
     * notification sent by ShiftReminderService for the same event.
     */
    public function sendMissedShiftAlertToEmployee(Employee $employee, string $type, string $shiftLabel, string $dateStr, string $expectedTime, string $message): ?EmailNotificationLog
    {
        $recipientEmail = $employee->email;
        if (empty($recipientEmail) || !filter_var($recipientEmail, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $idempotencyKey = "email:shift_reminder:{$employee->id}:{$type}:{$dateStr}";
        $existing = EmailNotificationLog::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $senderEmail = SecurityConfiguration::getVal('sender_email', 'support@example.com');
        $eventLabel = str_starts_with($type, 'checkin') ? 'Check-in' : 'Check-out';
        $empCode = $employee->employee_code ?? $employee->employee_number ?? (string) $employee->id;

        $mail = EmailTemplates::missedShiftAlert([
            'employee_name' => $employee->full_name,
            'employee_code' => $empCode,
            'shift_label' => $shiftLabel,
            'date' => $dateStr,
            'event_label' => $eventLabel,
            'expected_time' => $expectedTime,
            'message' => $message,
        ], $senderEmail);

        $isSent = false;
        $failureReason = null;

        try {
            Mail::html($mail['html'], function ($message) use ($recipientEmail, $senderEmail, $mail) {
                $message->to($recipientEmail)
                        ->from($senderEmail, 'Your Company Attendance App')
                        ->subject($mail['subject']);
            });
            $isSent = true;
        } catch (\Throwable $e) {
            $failureReason = $e->getMessage();
            Log::error("Failed to send missed shift alert email for employee #{$employee->id}: {$failureReason}");
        }

        return EmailNotificationLog::create([
            'recipient_email' => $recipientEmail,
            'recipient_name' => $employee->full_name,
            'template_name' => 'missed_shift_alert',
            'related_type' => 'employee',
            'related_id' => $employee->id,
            'subject' => $mail['subject'],
            'body_text' => $message,
            'delivery_status' => $isSent ? 'sent' : 'failed',
            'attempted_at' => Carbon::now(),
            'sent_at' => $isSent ? Carbon::now() : null,
            'failure_reason' => $failureReason,
            'idempotency_key' => $idempotencyKey
        ]);
    }

    /**
     * Send a real email alert to the configured notification recipients and
     * the employee's reporting manager once a break crosses the admin-configured
     * "Overdue Break Alert Threshold" (company_break_settings.overdue_alert_threshold_minutes).
     * Idempotent per-break — fires once, not on every escalation cycle.
     */
    public function sendOverdueBreakAlertToHR($break, int $overdueMinutes): ?EmailNotificationLog
    {
        $employee = $break->employee;
        if (!$employee) return null;

        $idempotencyKey = "email:break:{$break->id}:overdue_alert";
        $existing = EmailNotificationLog::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $recipients = SecurityConfiguration::getVal('notification_recipients', ['support@example.com']);
        if (!is_array($recipients) || empty($recipients)) {
            $recipients = ['support@example.com'];
        }
        $manager = $employee->reportingManager;
        if ($manager && !empty($manager->email)) {
            $recipients[] = $manager->email;
        }
        $recipients = array_values(array_unique(array_filter($recipients)));
        if (empty($recipients)) {
            return null;
        }

        $senderEmail = SecurityConfiguration::getVal('sender_email', 'support@example.com');
        $templateData = $this->buildOverdueBreakTemplateData($break, $employee, $overdueMinutes, 'hr');
        $mail = EmailTemplates::overdueBreakAlert($templateData, $senderEmail);

        $isSent = false;
        $failureReason = null;
        try {
            Mail::html($mail['html'], function ($message) use ($recipients, $senderEmail, $mail) {
                $message->to($recipients)
                        ->from($senderEmail, 'Your Company Attendance App')
                        ->subject($mail['subject']);
            });
            $isSent = true;
        } catch (\Throwable $e) {
            $failureReason = $e->getMessage();
            Log::error("Failed to send overdue break HR alert for break #{$break->id}: {$failureReason}");
        }

        $log = EmailNotificationLog::create([
            'recipient_email' => implode(',', $recipients),
            'recipient_name' => 'HR / Reporting Manager',
            'template_name' => 'overdue_break_hr_alert',
            'related_type' => 'attendance_break',
            'related_id' => $break->id,
            'subject' => $mail['subject'],
            'body_text' => "{$templateData['employee_name']} has not ended their break and is {$overdueMinutes} minutes overdue.",
            'delivery_status' => $isSent ? 'sent' : 'failed',
            'attempted_at' => Carbon::now(),
            'sent_at' => $isSent ? Carbon::now() : null,
            'failure_reason' => $failureReason,
            'idempotency_key' => $idempotencyKey
        ]);

        if ($isSent) {
            Log::info("Sent overdue break email alert to HR/Manager for employee {$templateData['employee_name']} (Break #{$break->id}, {$overdueMinutes}m overdue)");
        }

        return $log;
    }

    /**
     * Send a real email directly to the employee's own inbox, only if they
     * have a valid email on file. Same idempotent-once-per-break behavior as
     * sendOverdueBreakAlertToHR.
     */
    public function sendOverdueBreakAlertToEmployee($break, int $overdueMinutes): ?EmailNotificationLog
    {
        $employee = $break->employee;
        if (!$employee || empty($employee->email) || !filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $idempotencyKey = "email:break:{$break->id}:overdue_alert_employee";
        $existing = EmailNotificationLog::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $senderEmail = SecurityConfiguration::getVal('sender_email', 'support@example.com');
        $templateData = $this->buildOverdueBreakTemplateData($break, $employee, $overdueMinutes, 'employee');
        $mail = EmailTemplates::overdueBreakAlert($templateData, $senderEmail);

        $isSent = false;
        $failureReason = null;
        try {
            Mail::html($mail['html'], function ($message) use ($employee, $senderEmail, $mail) {
                $message->to($employee->email)
                        ->from($senderEmail, 'Your Company Attendance App')
                        ->subject($mail['subject']);
            });
            $isSent = true;
        } catch (\Throwable $e) {
            $failureReason = $e->getMessage();
            Log::error("Failed to send overdue break employee alert for break #{$break->id}: {$failureReason}");
        }

        return EmailNotificationLog::create([
            'recipient_email' => $employee->email,
            'recipient_name' => $templateData['employee_name'],
            'template_name' => 'overdue_break_employee_alert',
            'related_type' => 'attendance_break',
            'related_id' => $break->id,
            'subject' => $mail['subject'],
            'body_text' => "Your break is {$overdueMinutes} minutes overdue.",
            'delivery_status' => $isSent ? 'sent' : 'failed',
            'attempted_at' => Carbon::now(),
            'sent_at' => $isSent ? Carbon::now() : null,
            'failure_reason' => $failureReason,
            'idempotency_key' => $idempotencyKey
        ]);
    }

    private function buildOverdueBreakTemplateData($break, Employee $employee, int $overdueMinutes, string $audience): array
    {
        $policy = $break->breakPolicy;
        return [
            'employee_name' => $employee->full_name ?? 'Employee',
            'employee_code' => $employee->employee_code ?? $employee->employee_number ?? (string) $employee->id,
            'policy_name' => $policy ? $policy->name : 'Standard Break',
            'allowed_minutes' => $policy ? $policy->allowed_duration_minutes : 60,
            'started_at' => $break->start_time_device ?? ($break->created_at ? $break->created_at->format('h:i A') : 'N/A'),
            'overdue_minutes' => $overdueMinutes,
            'audience' => $audience,
        ];
    }

    /**
     * Safely format a datetime attribute that may arrive as a Carbon instance
     * (model-cast) or a plain string (uncast column) without throwing.
     */
    private function formatClaimDateTime($value, string $format): ?string
    {
        if (empty($value)) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }
        try {
            return Carbon::parse($value)->format($format);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
