<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\AttendanceBreak;
use App\Models\AttendanceEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillAttendanceEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backfill-attendance-events';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely and idempotently backfill attendance_events ledger entries from historical records and breaks';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting historical attendance events backfill...');

        $createdRecordsCount = 0;
        $createdBreaksCount = 0;

        // 1. Backfill from AttendanceRecords
        $records = AttendanceRecord::with(['checkInEvent', 'checkOutEvent'])->get();
        foreach ($records as $record) {
            // Check-in event
            $checkInTime = $record->checkInEvent ? $record->checkInEvent->event_time_server : null;
            if ($checkInTime) {
                $exists = AttendanceEvent::where('attendance_record_id', $record->id)
                    ->whereIn('event_type', ['ATTENDANCE_CHECKED_IN', 'check_in'])
                    ->exists();

                if (!$exists) {
                    AttendanceEvent::create([
                        'uuid' => Str::uuid()->toString(),
                        'attendance_record_id' => $record->id,
                        'employee_id' => $record->employee_id,
                        'event_type' => 'ATTENDANCE_CHECKED_IN',
                        'event_time_server' => $checkInTime,
                        'event_time_device' => $record->checkInEvent ? $record->checkInEvent->event_time_device : $checkInTime,
                        'latitude' => $record->checkInEvent ? $record->checkInEvent->latitude : null,
                        'longitude' => $record->checkInEvent ? $record->checkInEvent->longitude : null,
                        'event_source' => 'historical_backfill',
                        'actor_role' => 'employee',
                        'metadata' => [
                            'note' => 'Backfilled from historical attendance record'
                        ]
                    ]);
                    $createdRecordsCount++;
                }
            }

            // Check-out event
            $checkOutTime = $record->checkOutEvent ? $record->checkOutEvent->event_time_server : null;
            if ($checkOutTime) {
                $exists = AttendanceEvent::where('attendance_record_id', $record->id)
                    ->whereIn('event_type', ['ATTENDANCE_CHECKED_OUT', 'check_out'])
                    ->exists();

                if (!$exists) {
                    AttendanceEvent::create([
                        'uuid' => Str::uuid()->toString(),
                        'attendance_record_id' => $record->id,
                        'employee_id' => $record->employee_id,
                        'event_type' => 'ATTENDANCE_CHECKED_OUT',
                        'event_time_server' => $checkOutTime,
                        'event_time_device' => $record->checkOutEvent ? $record->checkOutEvent->event_time_device : $checkOutTime,
                        'latitude' => $record->checkOutEvent ? $record->checkOutEvent->latitude : null,
                        'longitude' => $record->checkOutEvent ? $record->checkOutEvent->longitude : null,
                        'event_source' => 'historical_backfill',
                        'actor_role' => 'employee',
                        'metadata' => [
                            'note' => 'Backfilled from historical attendance record',
                            'net_duration_minutes' => $record->net_duration_minutes
                        ]
                    ]);
                    $createdRecordsCount++;
                }
            }
        }

        // 2. Backfill from AttendanceBreaks
        $breaks = AttendanceBreak::all();
        foreach ($breaks as $b) {
            if ($b->start_time_server) {
                $exists = AttendanceEvent::where('attendance_break_id', $b->id)
                    ->where('event_type', 'BREAK_STARTED')
                    ->exists();

                if (!$exists) {
                    AttendanceEvent::create([
                        'uuid' => Str::uuid()->toString(),
                        'attendance_record_id' => $b->attendance_record_id,
                        'attendance_break_id' => $b->id,
                        'employee_id' => $b->employee_id,
                        'event_type' => 'BREAK_STARTED',
                        'event_time_server' => $b->start_time_server,
                        'event_time_device' => $b->start_time_device ?: $b->start_time_server,
                        'event_source' => 'historical_backfill',
                        'actor_role' => 'employee',
                        'metadata' => [
                            'reason' => $b->reason,
                            'note' => 'Backfilled from historical break record'
                        ]
                    ]);
                    $createdBreaksCount++;
                }
            }

            if ($b->end_time_server) {
                $exists = AttendanceEvent::where('attendance_break_id', $b->id)
                    ->where('event_type', 'BREAK_ENDED')
                    ->exists();

                if (!$exists) {
                    AttendanceEvent::create([
                        'uuid' => Str::uuid()->toString(),
                        'attendance_record_id' => $b->attendance_record_id,
                        'attendance_break_id' => $b->id,
                        'employee_id' => $b->employee_id,
                        'event_type' => 'BREAK_ENDED',
                        'event_time_server' => $b->end_time_server,
                        'event_time_device' => $b->end_time_device ?: $b->end_time_server,
                        'event_source' => 'historical_backfill',
                        'actor_role' => 'employee',
                        'metadata' => [
                            'duration_seconds' => $b->duration_seconds,
                            'duration_minutes' => $b->duration_minutes,
                            'note' => 'Backfilled from historical break record'
                        ]
                    ]);
                    $createdBreaksCount++;
                }
            }
        }

        $this->info("Backfill complete. Created {$createdRecordsCount} attendance events and {$createdBreaksCount} break events.");
        return 0;
    }
}
