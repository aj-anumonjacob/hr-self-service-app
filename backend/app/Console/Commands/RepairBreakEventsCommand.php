<?php

namespace App\Console\Commands;

use App\Models\AttendanceBreak;
use App\Models\AttendanceEvent;
use App\Services\BreakPolicyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RepairBreakEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:repair-break-events';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently repair historical completed break duration values and BREAK_ENDED event metadata';

    /**
     * Execute the console command.
     */
    public function handle(BreakPolicyService $policyService)
    {
        $this->info('Starting historical break duration and event metadata repair...');

        $repairedBreaksCount = 0;
        $repairedEventsCount = 0;
        $skippedCount = 0;

        $breaks = AttendanceBreak::with(['employee', 'record', 'breakPolicy'])->get();

        foreach ($breaks as $break) {
            if (!$break->start_time_server || !$break->end_time_server) {
                $skippedCount++;
                continue;
            }

            $startedAt = $break->start_time_server;
            $endedAt = $break->end_time_server;
            $actualDurationSeconds = max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
            $durationMinutes = (int) max(1, ceil($actualDurationSeconds / 60));

            $policy = $break->breakPolicy;
            if (!$policy && $break->employee) {
                $dateStr = $break->record ? $break->record->date : null;
                $policy = $policyService->resolveEffectivePolicy($break->employee, $dateStr);
            }

            $allowedDurationMinutes = $policy ? $policy->allowed_duration_minutes : 60;
            $allowedDurationSeconds = $allowedDurationMinutes * 60;
            $overdueDurationSeconds = max(0, $actualDurationSeconds - $allowedDurationSeconds);
            $wasOverdue = $overdueDurationSeconds > 0;
            $violationCode = $wasOverdue ? 'EXCEEDED_MAX_DURATION' : null;

            // Update break record
            $break->update([
                'duration_seconds' => $actualDurationSeconds,
                'duration_minutes' => $durationMinutes,
                'overdue_duration_seconds' => $overdueDurationSeconds,
                'violation_code' => $break->status === 'completed' ? $violationCode : $break->violation_code,
            ]);
            $repairedBreaksCount++;

            // Find or create BREAK_ENDED event
            $event = AttendanceEvent::where('attendance_break_id', $break->id)
                ->where('event_type', 'BREAK_ENDED')
                ->first();

            $nowStr = Carbon::now()->toIso8601String();
            $existingMetadata = ($event && is_array($event->metadata)) ? $event->metadata : [];

            $newMetadata = array_merge($existingMetadata, [
                'startedAt' => $startedAt->toIso8601String(),
                'endedAt' => $endedAt->toIso8601String(),
                'actualDurationSeconds' => $actualDurationSeconds,
                'allowedDurationSeconds' => $allowedDurationSeconds,
                'overdueDurationSeconds' => $overdueDurationSeconds,
                'wasOverdue' => $wasOverdue,
                'actual_duration_seconds' => $actualDurationSeconds,
                'allowed_duration_seconds' => $allowedDurationSeconds,
                'overdue_duration_seconds' => $overdueDurationSeconds,
                'duration_seconds' => $actualDurationSeconds,
                'duration_minutes' => $durationMinutes,
                'was_overdue' => $wasOverdue,
                'overdue_seconds' => $overdueDurationSeconds,
                'repaired_at' => $nowStr,
            ]);

            if ($event) {
                $event->update([
                    'event_time_server' => $endedAt,
                    'metadata' => $newMetadata,
                ]);
            } else {
                AttendanceEvent::create([
                    'uuid' => Str::uuid()->toString(),
                    'attendance_record_id' => $break->attendance_record_id,
                    'attendance_break_id' => $break->id,
                    'employee_id' => $break->employee_id,
                    'event_type' => 'BREAK_ENDED',
                    'event_time_server' => $endedAt,
                    'event_time_device' => $break->end_time_device ?: $endedAt,
                    'event_source' => 'historical_repair',
                    'actor_role' => 'employee',
                    'idempotency_key' => 'repair_break_end_' . $break->id,
                    'metadata' => $newMetadata,
                ]);
            }

            $repairedEventsCount++;
        }

        $this->info("Repair completed successfully.");
        $this->info("Repaired breaks: {$repairedBreaksCount}");
        $this->info("Repaired events: {$repairedEventsCount}");
        $this->info("Skipped (incomplete timestamps): {$skippedCount}");

        return 0;
    }
}
