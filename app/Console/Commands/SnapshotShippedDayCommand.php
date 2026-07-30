<?php

namespace App\Console\Commands;

use App\Services\ShippedDaySnapshotService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class SnapshotShippedDayCommand extends Command
{
    protected $signature = 'orders:snapshot-shipped-day
        {--date= : NY calendar day Y-m-d (default: today in America/New_York)}';

    protected $description = 'Persist shipped totals + per-account breakdown for a day (default today, America/New_York)';

    public function handle(ShippedDaySnapshotService $snapshots): int
    {
        $tz = ShippedDaySnapshotService::TIMEZONE;
        $dateOption = trim((string) $this->option('date'));

        try {
            if ($dateOption !== '') {
                $day = Carbon::parse($dateOption, $tz)->startOfDay();
            } else {
                $day = $snapshots->nowNy()->startOfDay();
            }
        } catch (Throwable $e) {
            $this->error('Invalid --date: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Capturing shipped day snapshot for '.$day->toDateString().' ('.$tz.')…');

        try {
            $row = $snapshots->captureDay($day);
        } catch (Throwable $e) {
            $this->error('Snapshot failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $accountCount = $row->accounts()->count();
        $this->info(sprintf(
            'Saved snapshot #%d: total_count=%d, accounts=%d, captured_at=%s',
            (int) $row->id,
            (int) $row->total_count,
            $accountCount,
            $row->captured_at !== null ? $row->captured_at->toIso8601String() : 'n/a'
        ));

        return self::SUCCESS;
    }
}
