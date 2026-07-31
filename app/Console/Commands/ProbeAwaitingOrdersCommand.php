<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\ShipHeroOrderQueueIndex;
use App\Services\PortalQueueCountsService;
use App\Services\ShipHeroOrderService;
use Illuminate\Console\Command;
use Throwable;

class ProbeAwaitingOrdersCommand extends Command
{
    protected $signature = 'orders:probe-awaiting {account : Client account id}';

    protected $description = 'Compare ShipHero Ready to Ship vs local awaiting index for one account';

    public function handle(ShipHeroOrderService $orders): int
    {
        $accountId = (int) $this->argument('account');
        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            $this->error('Account #'.$accountId.' not found.');

            return self::FAILURE;
        }

        $customerId = trim((string) $account->shiphero_customer_account_id);
        $this->info('Account #'.$account->id.' '.$account->company_name);
        $this->line('Status: '.$account->status);
        $this->line('ShipHero customer id: '.($customerId !== '' ? $customerId : '(missing)'));
        $this->line('Queue sync status: '.($account->order_queue_sync_status ?? '—'));
        $this->line('Queue synced at: '.($account->order_queue_synced_at ?? '—'));
        if (! empty($account->order_queue_sync_error)) {
            $this->warn('Queue sync error: '.$account->order_queue_sync_error);
        }

        $indexCount = ShipHeroOrderQueueIndex::query()
            ->where('client_account_id', $accountId)
            ->where('queue_kind', ShipHeroOrderQueueIndex::KIND_AWAITING)
            ->count();
        $this->line('Local awaiting index rows: '.$indexCount);

        if ($customerId === '') {
            $this->error('No shiphero_customer_account_id — cannot probe ShipHero.');

            return self::FAILURE;
        }

        $timezone = PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;

        $probes = [
            'ready_to_ship only (no dates)' => [
                'customer_account_id' => $customerId,
                'tab' => 'awaiting',
                'timezone' => $timezone,
                'skip_date_window' => true,
                'first' => 10,
            ],
            'ready_to_ship + since May 1 order_date' => [
                'customer_account_id' => $customerId,
                'tab' => 'awaiting',
                'timezone' => $timezone,
                'order_date_from' => PortalQueueCountsService::RTS_DASHBOARD_ORDER_FROM,
                'order_date_to' => now($timezone)->toDateString(),
                'first' => 10,
            ],
        ];

        foreach ($probes as $label => $filters) {
            $this->line('');
            $this->comment($label);
            try {
                $page = $orders->listOrders($filters);
                $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];
                $hasNext = (bool) ($page['pagination']['has_next_page'] ?? false);
                $this->line('  First page rows: '.count($rows).($hasNext ? ' (has more pages)' : ''));
                foreach (array_slice($rows, 0, 3) as $row) {
                    $this->line(sprintf(
                        '  - #%s ready_to_ship=%s status=%s order_date=%s',
                        (string) ($row['order_number'] ?? $row['id'] ?? '?'),
                        ! empty($row['ready_to_ship']) ? 'yes' : 'no',
                        (string) ($row['raw_fulfillment_status'] ?? $row['status'] ?? ''),
                        (string) ($row['order_date'] ?? '')
                    ));
                }
            } catch (Throwable $e) {
                $this->warn('  Failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
