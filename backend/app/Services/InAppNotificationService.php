<?php

namespace App\Services;

use App\Models\InAppNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * InAppNotificationService
 *
 * Creates persistent in-app notifications for the notification centre, and
 * mirrors every one of them as a real Firebase push (see PushNotificationService)
 * so the employee is alerted even when the app is closed or backgrounded.
 * Every notification is uniquely identified by an idempotency_key so
 * worker retries cannot produce duplicate records.
 */
class InAppNotificationService
{
    // Supported notification types
    const TYPE_BREAK_ENDING_SOON      = 'BREAK_ENDING_SOON';
    const TYPE_BREAK_EXPIRED          = 'BREAK_EXPIRED';
    const TYPE_BREAK_OVERDUE_EMPLOYEE = 'BREAK_OVERDUE_EMPLOYEE';
    const TYPE_BREAK_OVERDUE_MANAGER  = 'BREAK_OVERDUE_MANAGER';
    const TYPE_BREAK_OVERDUE_ADMIN    = 'BREAK_OVERDUE_ADMIN';

    protected PushNotificationService $pushNotificationService;

    public function __construct(PushNotificationService $pushNotificationService)
    {
        $this->pushNotificationService = $pushNotificationService;
    }

    /**
     * Create a persistent in-app notification.
     *
     * Returns an existing record if the idempotency_key is already present,
     * so it is safe to call this method inside a retried job.
     *
     * @param  User   $user            The recipient user
     * @param  string $recipientRole   'employee' | 'manager' | 'administrator'
     * @param  string $type            One of the TYPE_* constants
     * @param  string $title           Short title
     * @param  string $message         Full body
     * @param  array  $data            Navigation / contextual payload
     * @param  string $idempotencyKey  Unique key (e.g. "break:42:expired:7")
     * @return InAppNotification
     */
    public function createNotification(
        User   $user,
        string $recipientRole,
        string $type,
        string $title,
        string $message,
        array  $data,
        string $idempotencyKey
    ): InAppNotification {
        // Return existing record to prevent duplicates on worker retries
        $existing = InAppNotification::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $notification = InAppNotification::create([
            'user_id'         => $user->id,
            'recipient_role'  => $recipientRole,
            'type'            => $type,
            'title'           => $title,
            'message'         => $message,
            'data'            => $data,
            'is_read'         => false,
            'scheduled_at'    => Carbon::now(),
            'idempotency_key' => $idempotencyKey,
        ]);

        // Fire the real push alongside the in-app record. Failure here (e.g.
        // Firebase not configured yet, or the employee has no device token)
        // must never block the in-app notification from being saved.
        try {
            $employee = $user->employee;
            if ($employee) {
                $this->pushNotificationService->sendToEmployee($employee, $title, $message, $data);
            }
        } catch (\Throwable $e) {
            Log::warning("Push notification dispatch failed for user {$user->id}: " . $e->getMessage());
        }

        return $notification;
    }

    /**
     * Mark a notification as read.
     */
    public function markRead(InAppNotification $notification): void
    {
        if (!$notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Count unread notifications for a user.
     */
    public function unreadCount(int $userId): int
    {
        return InAppNotification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }
}
