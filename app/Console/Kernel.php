<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Incremental sync every 10 minutes — fetches only new reviews
        $schedule->command('reviews:sync')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/reviews-sync.log'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
