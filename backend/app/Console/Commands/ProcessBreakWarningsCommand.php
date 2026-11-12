<?php

namespace App\Console\Commands;

use App\Models\AttendanceBreak;
use App\Services\InAppNotificationService;
use App\Services\PushNotificationService;
use App\Services\BreakPolicyService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * ProcessBreakWarningsCommand
 *
 * Runs every minute and sends a "Break ending soon" notification to employees
 * whose break will expire in the next 10 minutes (and has not already been notified).
 *
 * Idempotency key format: break:{breakId}:ending-soon:{employeeUserId}
 */
class ProcessBreakWarningsCommand extends Command
{
    protected $signature = 'app:process-break-warnings';

    protected $description = 'Send "break ending soon" in-app and push notifications to employees approaching their break expiry';

    protected InAppNotificationService $inAppService;
    protected PushNotificationService $pushService;
    protected BreakPolicyService $policyService;

    public function __construct(
        InAppNotificationService $inAppService,
        PushNotificationService  $pushService,
        BreakPolicyService       $policyService
    ) {
        parent::__construct();
        $this->inAppService = $inAppService;
        $this->pushService  = $pushService;
        $this->policyService = $policyService;
    }

    public function handle(): int
    {
        $now = Carbon::now();

        // Window: breaks expiring between now+0s and now+10min
        $windowEnd = $now->copy()->addMinutes(10);

        // Find active breaks in the warning window that have not yet been stopped
        $breaks = AttendanceBreak::with(['employee.user', 'breakPolicy'])
            ->where('status', 'active')
            ->whereNull('end_time_server')
            ->whereNotNull('expected_end_at')
            ->where('expected_end_at', '>', $now)          // not yet expired
            ->where('expected_end_at', '<=', $windowEnd)   // within 10-minute window
            ->whereNotIn('notification_series_status', ['stopped', 'limit_reached'])
            ->get();

        $count = 0;

        foreach ($breaks as $break) {
            $this->processWarning($break, $now);
            $count++;
        }

        $this->info("Processed {$count} break warning(s).");
        return Command::SUCCESS;
    }

    protected function processWarning(AttendanceBreak $break, Carbon $now): void
    {
        $employee = $break->employee;
        if (!$employee) return;

        $employeeUser = $employee->user;
        if (!$employeeUser) return;

        // Skip if policy duration is 10 minutes or less (not enough time for a useful warning)
        $policy = $break->breakPolicy;
        if ($policy && $policy->allowed_duration_minutes <= 10) {
            return;
        }

        $idempotencyKey = "break:{$break->id}:ending-soon:{$employeeUser->id}";
        $remainingSeconds = max(0, (int) $now->diffInSeconds($break->expected_end_at, false));
        $remainingMinutes = (int) ceil($remainingSeconds / 60);

        $title   = 'Break ending soon';
        $message = "Your break will end in {$remainingMinutes} minute(s). Please prepare to resume work.";

        $navData = [
            'navigation' => [
                'type'       => 'activeBreak',
                'break_uuid' => $break->uuid,
            ],
        ];

        // Create persistent in-app notification (idempotent)
        $this->inAppService->createNotification(
            $employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_ENDING_SOON,
            $title,
            $message,
            $navData,
            $idempotencyKey
        );

        // Also send push notification (idempotent via BreakNotificationLog)
        $this->pushService->sendOverdueBreakNotification(
            $break,
            $employeeUser,
            'employee',
            'ending_soon',
            0,          // sequence 0 = pre-expiry
            $title,
            $message,
            $idempotencyKey . '_push'
        );
    }
}
