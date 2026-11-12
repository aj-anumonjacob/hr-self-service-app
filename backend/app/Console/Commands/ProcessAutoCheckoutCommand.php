<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AutoCheckoutService;

class ProcessAutoCheckoutCommand extends Command
{
    protected $signature = 'app:process-auto-checkout';
    protected $description = 'Process automatic checkout for unclosed attendance records past cutoff time';

    public function handle(AutoCheckoutService $service): int
    {
        $this->info("Processing automatic checkouts...");
        $count = $service->processAutoCheckouts();
        $this->info("Processed {$count} automatic checkouts.");

        return 0;
    }
}
