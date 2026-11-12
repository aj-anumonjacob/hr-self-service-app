<?php

namespace App\Services;

use App\Models\User;
use App\Models\Employee;
use App\Models\AttendanceBreak;
use App\Models\BreakNotificationLog;
use App\Models\PushNotificationToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class PushNotificationService
{
    /**
     * Send a real FCM push to every active device registered for this
     * employee. Tokens Firebase reports as invalid/unregistered are
     * deactivated so we stop retrying them.
     *
     * @return int number of devices the push was actually delivered to
     */
    public function sendToEmployee(Employee $employee, string $title, string $message, array $data = []): int
    {
        $tokens = PushNotificationToken::where('employee_id', $employee->id)
            ->where('active', true)
            ->pluck('fcm_token')
            ->filter()
            ->values();

        if ($tokens->isEmpty()) {
            return 0;
        }

        // FCM data payload values must all be strings.
        $stringData = [];
        foreach ($data as $key => $value) {
            $stringData[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        // High priority so Android delivers promptly instead of deferring
        // under Doze/App Standby or OEM battery optimization — these are
        // time-sensitive alerts (shift reminders, break overdue, etc.), not
        // background sync hints.
        $fcmMessage = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, $message))
            ->withData($stringData)
            ->withHighestPossiblePriority();

        try {
            $report = Firebase::messaging()->sendMulticast($fcmMessage, $tokens->all());
        } catch (\Throwable $e) {
            Log::error("FCM sendMulticast failed for employee {$employee->id}: " . $e->getMessage());
            return 0;
        }

        $deadTokens = array_merge($report->invalidTokens(), $report->unknownTokens());
        if (!empty($deadTokens)) {
            PushNotificationToken::whereIn('fcm_token', $deadTokens)->update(['active' => false]);
        }

        return count($report->validTokens());
    }

    /**
     * Send break overdue push notification and record log
     */
    public function sendOverdueBreakNotification(
        AttendanceBreak $break,
        User $recipientUser,
        string $recipientRole,
        string $notificationType,
        int $sequenceNumber,
        string $title,
        string $message,
        string $idempotencyKey
    ): BreakNotificationLog {
        // Prevent duplicate delivery for exact idempotency key
        $existingLog = BreakNotificationLog::where('idempotency_key', $idempotencyKey)->first();
        if ($existingLog) {
            return $existingLog;
        }

        $now = Carbon::now();
        $log = BreakNotificationLog::create([
            'break_id' => $break->id,
            'attendance_record_id' => $break->attendance_record_id,
            'employee_id' => $break->employee_id,
            'recipient_user_id' => $recipientUser->id,
            'recipient_role' => $recipientRole,
            'notification_type' => $notificationType,
            'escalation_sequence_number' => $sequenceNumber,
            'scheduled_at' => $now,
            'attempted_at' => $now,
            'delivery_status' => 'processing',
            'title' => $title,
            'message' => $message,
            'idempotency_key' => $idempotencyKey
        ]);

        try {
            $employee = $recipientUser->employee;
            $deliveredCount = 0;
            if ($employee) {
                $deliveredCount = $this->sendToEmployee($employee, $title, $message, [
                    'break_id' => $break->id,
                    'type' => $notificationType,
                ]);
            }

            $log->update([
                'delivery_status' => $deliveredCount > 0 ? 'sent' : 'failed',
                'sent_at' => $deliveredCount > 0 ? Carbon::now() : null,
                'failure_reason' => $deliveredCount > 0 ? null : 'No active push token for recipient',
            ]);
        } catch (\Exception $e) {
            $log->update([
                'delivery_status' => 'failed',
                'failure_reason' => $e->getMessage()
            ]);
        }

        return $log;
    }
}
