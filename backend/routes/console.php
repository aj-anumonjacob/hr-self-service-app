<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:process-break-warnings')->everyMinute();
Schedule::command('app:process-overdue-breaks')->everyMinute();
Schedule::command('app:process-shift-reminders')->everyMinute();
