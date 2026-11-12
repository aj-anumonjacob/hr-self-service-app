<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShiftReminderService;
use Carbon\Carbon;

class ProcessShiftRemindersCommand extends Command
{
    protected $signature = 'app:process-shift-reminders {--date= : Shift date to process YYYY-MM-DD}';
    protected $description = 'Process shift check-in and check-out reminders for active employees';

    public function handle(ShiftReminderService $service): int
    {
        $dateStr = $this->option('date') ?: Carbon::now()->toDateString();
        $this->info("Processing shift reminders for date: {$dateStr}");

        $count = $service->processRemindersForDate($dateStr);
        $this->info("Processed {$count} shift reminder notifications.");

        return 0;
    }
}
