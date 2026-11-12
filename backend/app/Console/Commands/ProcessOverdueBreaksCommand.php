<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OverdueBreakProcessorService;

class ProcessOverdueBreaksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-overdue-breaks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process active employee breaks that have exceeded their expected end time and dispatch escalation push notifications';

    /**
     * Execute the console command.
     */
    public function handle(OverdueBreakProcessorService $processor)
    {
        $this->info('Scanning for overdue employee breaks...');
        $count = $processor->processOverdueBreaks();
        $this->info("Processed {$count} overdue break(s).");

        return Command::SUCCESS;
    }
}
