<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Match unmatched data every 15 minutes
        $schedule->job(new \App\Jobs\MatchUnmatchedData)
            ->everyFifteenMinutes()
            ->withoutOverlapping();
            
        // Clean up expired unmatched data every hour
        $schedule->command('cleanup:expired-unmatched')
            ->hourly()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}