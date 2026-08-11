<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use RuntimeException;
use Throwable;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('inventory:refresh-restock-report')
            ->dailyAt('07:00')
            ->timezone('America/New_York');
        $schedule->command('inventory:refresh-restock-report')
            ->dailyAt('12:00')
            ->timezone('America/New_York');
        $schedule->command('inventory:refresh-restock-report')
            ->dailyAt('14:30')
            ->timezone('America/New_York');
        // Webhook fallback: reconcile recently updated orders every 5 minutes (lookback remains 15m).
        $schedule->command('orders:sync-recent-updates')
            ->cron('*/5 * * * *')
            ->timezone('America/New_York')
            ->withoutOverlapping(10);
        $schedule->command('orders:reprocess-pending-webhooks')
            ->cron('*/5 * * * *')
            ->timezone('America/New_York')
            ->withoutOverlapping(5);
        $schedule->command('shopify:sync-recent')
            ->cron('*/5 * * * *')
            ->timezone('America/New_York')
            ->withoutOverlapping(10);
        $schedule->command('shopify:reprocess-pending-webhooks')
            ->cron('*/5 * * * *')
            ->timezone('America/New_York')
            ->withoutOverlapping(5);
        // Inventory catalog incremental stays on the lighter 15/30 cadence.
        $schedule->command('inventory:sync-catalog-incremental')
            ->cron('*/15 7-17 * * *')
            ->timezone('America/New_York');
        $schedule->command('inventory:sync-catalog-incremental')
            ->cron('*/30 0-6,18-23 * * *')
            ->timezone('America/New_York');
        $schedule->command('orders:import-dashboard-queues')
            ->cron('*/30 7-17 * * *')
            ->timezone('America/New_York')
            ->withoutOverlapping(90);
        $schedule->command('orders:import-dashboard-queues')
            ->cron('0 0-6,18-23 * * *')
            ->timezone('America/New_York')
            ->withoutOverlapping(90);
        $schedule->command('orders:sync-queue-index --sync')
            ->dailyAt('02:00')
            ->timezone('America/New_York');
        $schedule->command('orders:import-dashboard-queues --tabs=all')
            ->dailyAt('02:30')
            ->timezone('America/New_York')
            ->withoutOverlapping(90);
        // Continue overnight detail-cache backfill until caught up (throttled ShipHero getOrder).
        $schedule->command('orders:backfill-order-details --from=2026-01-01 --limit=150 --sleep=2')
            ->hourly()
            ->timezone('America/New_York')
            ->between('00:00', '06:59')
            ->withoutOverlapping(90);
        $schedule->command('orders:refresh-home-dashboard --sync')
            ->dailyAt('07:05')
            ->timezone('America/New_York');
        $schedule->command('orders:snapshot-shipped-day')
            ->dailyAt('23:00')
            ->timezone('America/New_York')
            ->withoutOverlapping(120);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Block commands that drop or recreate the entire database when APP_ENV=production,
     * unless ALLOW_DESTRUCTIVE_ARTISAN=true (use only on disposable clones, never on live data).
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface|null  $output
     * @return int
     */
    public function handle($input, $output = null)
    {
        try {
            $this->bootstrap();

            if (
                $this->app->environment('production')
                && ! filter_var(env('ALLOW_DESTRUCTIVE_ARTISAN', false), FILTER_VALIDATE_BOOLEAN)
            ) {
                $command = $input->getFirstArgument();
                $blocked = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'];
                if (in_array($command, $blocked, true)) {
                    throw new RuntimeException(
                        'Command "'.$command.'" is disabled when APP_ENV=production (set ALLOW_DESTRUCTIVE_ARTISAN=true only on a throwaway DB). '.
                        'Normal deploy: php artisan migrate --force'
                    );
                }
            }

            return $this->getArtisan()->run($input, $output);
        } catch (Throwable $e) {
            $this->reportException($e);
            $this->renderException($output, $e);

            return 1;
        }
    }
}
