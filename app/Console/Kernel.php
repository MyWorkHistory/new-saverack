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
        // $schedule->command('inspire')->hourly();
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
