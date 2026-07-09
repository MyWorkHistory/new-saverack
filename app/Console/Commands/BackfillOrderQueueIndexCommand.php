<?php

namespace App\Console\Commands;

use App\Jobs\BackfillOrderQueueIndexChunkJob;
use App\Models\ClientAccount;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderQueueIndexService;
use App\Support\OrderQueueBackfillDateChunks;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class BackfillOrderQueueIndexCommand extends Command
{
    protected $signature = 'orders:backfill-queue-index
        {--from=2026-01-01 : Start date YYYY-MM-DD}
        {--to= : End date YYYY-MM-DD; default today in account timezone}
        {--tab=all : Queue tab: awaiting|on_hold|backorder|shipped|all}
        {--account= : Optional client account id; default all linked accounts}
        {--chunk=month : Shipped chunk size: month|week}
        {--sleep=3 : Seconds to pause between chunks when running inline}
        {--sync : Run inline instead of queueing}
        {--purge : After all chunks for each tab+account, run one purge pass over the full date range}';

    protected $description = 'Backfill ShipHero order queue index for a date range (chunked for shipped)';

    public function handle(
        ShipHeroOrderQueueIndexService $index,
        PortalQueueCountsService $queueCounts
    ): int {
        $fromDate = trim((string) $this->option('from'));
        if ($fromDate === '') {
            $this->error('--from is required.');

            return self::FAILURE;
        }

        $tabOpt = strtolower(trim((string) $this->option('tab')));
        if ($tabOpt === '') {
            $tabOpt = 'all';
        }

        $tabs = $this->resolveTabs($tabOpt);
        if ($tabs === null) {
            return self::FAILURE;
        }

        $chunkUnit = strtolower(trim((string) $this->option('chunk')));
        if (! in_array($chunkUnit, ['month', 'week'], true)) {
            $this->error('Invalid --chunk (use month or week).');

            return self::FAILURE;
        }

        $accounts = $this->resolveAccounts(trim((string) $this->option('account')));
        if ($accounts === null) {
            return self::FAILURE;
        }

        if ($accounts->isEmpty()) {
            $this->warn('No linked client accounts found.');

            return self::SUCCESS;
        }

        $sleepSeconds = max(0, (int) $this->option('sleep'));
        $inline = (bool) $this->option('sync');
        $purge = (bool) $this->option('purge');
        $toOverride = trim((string) $this->option('to'));

        $queued = 0;
        $failures = 0;

        foreach ($accounts as $account) {
            $accountId = (int) $account->id;
            $context = $queueCounts->contextForAccount($account);
            $timezone = (string) ($context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE);
            $toDate = $toOverride !== ''
                ? $toOverride
                : Carbon::now($timezone)->toDateString();

            $this->info('Account #'.$accountId.' ('.$account->company_name.') '.$fromDate.' → '.$toDate);

            foreach ($tabs as $tab) {
                $ranges = $this->rangesForTab($tab, $fromDate, $toDate, $chunkUnit, $timezone);

                foreach ($ranges as $range) {
                    $label = $range['label'];
                    $this->line('  '.$tab.' '.$label.' ('.$range['from'].' → '.$range['to'].')');

                    if ($inline) {
                        try {
                            $stats = $this->runChunk($index, $accountId, $tab, $range['from'], $range['to'], false);
                            $this->line(sprintf(
                                '    done: %d rows, %d pages%s',
                                (int) ($stats['rows_upserted'] ?? 0),
                                (int) ($stats['pages'] ?? 0),
                                ! empty($stats['truncated']) ? ' (truncated)' : ''
                            ));
                        } catch (Throwable $e) {
                            $failures++;
                            $this->warn('    failed: '.$e->getMessage());
                        }

                        if ($sleepSeconds > 0) {
                            sleep($sleepSeconds);
                        }
                    } else {
                        $this->dispatchChunkJob($accountId, $tab, $range['from'], $range['to'], false);
                        $queued++;
                    }
                }

                if ($purge) {
                    $this->line('  '.$tab.' purge pass ('.$fromDate.' → '.$toDate.')');
                    if ($inline) {
                        try {
                            $stats = $this->runChunk($index, $accountId, $tab, $fromDate, $toDate, true);
                            $this->line(sprintf(
                                '    purge done: %d rows, %d pages%s',
                                (int) ($stats['rows_upserted'] ?? 0),
                                (int) ($stats['pages'] ?? 0),
                                ! empty($stats['truncated']) ? ' (truncated)' : ''
                            ));
                        } catch (Throwable $e) {
                            $failures++;
                            $this->warn('    purge failed: '.$e->getMessage());
                        }
                    } else {
                        $this->dispatchChunkJob($accountId, $tab, $fromDate, $toDate, true);
                        $queued++;
                    }
                }
            }
        }

        if ($inline) {
            if ($failures > 0) {
                $this->warn('Backfill finished with '.$failures.' failure(s).');

                return self::FAILURE;
            }
            $this->info('Backfill complete.');

            return self::SUCCESS;
        }

        $this->info('Queued '.$queued.' backfill chunk job(s).');
        $this->line('Run: php artisan queue:work database-long --timeout=3700 --tries=1');
        $this->line('Then: php artisan orders:refresh-home-dashboard --from-index --sync');

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function resolveTabs(string $tabOpt): ?array
    {
        if ($tabOpt === 'all') {
            return ShipHeroOrderQueueIndex::QUEUE_KINDS;
        }

        if (! in_array($tabOpt, ShipHeroOrderQueueIndex::QUEUE_KINDS, true)) {
            $this->error('Invalid --tab: '.$tabOpt);

            return null;
        }

        return [$tabOpt];
    }

    /**
     * @return Collection<int, ClientAccount>|null
     */
    private function resolveAccounts(string $accountOpt)
    {
        $query = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('id');

        if ($accountOpt !== '') {
            $accountId = (int) $accountOpt;
            if ($accountId <= 0) {
                $this->error('Invalid --account id.');

                return null;
            }
            $query->where('id', $accountId);
        }

        return $query->get(['id', 'company_name', 'shiphero_customer_account_id']);
    }

    /**
     * @return list<array{from: string, to: string, label: string}>
     */
    private function rangesForTab(string $tab, string $fromDate, string $toDate, string $chunkUnit, string $timezone): array
    {
        if ($tab === ShipHeroOrderQueueIndex::KIND_SHIPPED) {
            return OrderQueueBackfillDateChunks::between($fromDate, $toDate, $chunkUnit, $timezone);
        }

        return [
            [
                'from' => $fromDate,
                'to' => $toDate,
                'label' => $fromDate.'_'.$toDate,
            ],
        ];
    }

    /**
     * @return array{pages: int, rows_upserted: int, truncated: bool}
     */
    private function runChunk(
        ShipHeroOrderQueueIndexService $index,
        int $accountId,
        string $tab,
        string $from,
        string $to,
        bool $purgeStale
    ): array {
        $maxPages = $tab === ShipHeroOrderQueueIndex::KIND_SHIPPED ? 500 : 100;

        return $index->syncAccountQueueRange(
            $accountId,
            $tab,
            $from,
            $to,
            $purgeStale,
            $maxPages,
            false
        );
    }

    private function dispatchChunkJob(
        int $accountId,
        string $tab,
        string $from,
        string $to,
        bool $purgeStale
    ): void {
        $job = new BackfillOrderQueueIndexChunkJob($accountId, $tab, $from, $to, $purgeStale);
        $connection = $this->backfillQueueConnection();
        if ($connection !== null) {
            dispatch($job)->onConnection($connection);

            return;
        }

        dispatch($job);
    }

    private function backfillQueueConnection(): ?string
    {
        $preferred = trim((string) config('queue.catalog_long_connection', 'database-long'));
        if ($preferred !== '' && config("queue.connections.{$preferred}.driver") !== null) {
            return $preferred;
        }

        foreach (['redis', 'database', 'beanstalkd', 'sqs'] as $name) {
            if (config("queue.connections.{$name}.driver") !== null) {
                return $name;
            }
        }

        return null;
    }
}
