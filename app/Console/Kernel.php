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
        // Scan-station tokens expire (PointageScanController::TOKEN_LIFETIME_DAYS); drop the dead rows.
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
        // Daily database backup (config/backup.php). On the office PC a Windows scheduled task
        // runs `php artisan db:backup` itself, since nothing runs schedule:run there.
        $schedule->command('db:backup')->dailyAt('13:00');
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
