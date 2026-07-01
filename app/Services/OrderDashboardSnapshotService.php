<?php

namespace App\Services;

use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\OrderDashboardSection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OrderDashboardSnapshotService
{
    /** @var PortalQueueCountsService */
    private $queueCounts;

    /** @var ShipHeroOrderService */
    private $orders;

    public function __construct(PortalQueueCountsService $queueCounts, ShipHeroOrderService $orders)
    {
        $this->queueCounts = $queueCounts;
        $this->orders = $orders;
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrapIfNeeded(): void
    {
        $this->ensureSectionRows();

        $rows = OrderDashboardSection::query()
            ->whereIn('section_key', OrderDashboardSection::ALL_KEYS)
            ->get()
            ->keyBy('section_key');

        $asn = $rows->get(OrderDashboardSection::KEY_ASN_PENDING);
        if (! $asn instanceof OrderDashboardSection || $asn->refreshed_at === null) {
            try {
                $this->refreshSection(OrderDashboardSection::KEY_ASN_PENDING);
            } catch (Throwable $e) {
                Log::warning('order_dashboard.asn_bootstrap_failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        foreach (OrderDashboardSection::SHIPHERO_KEYS as $key) {
            $row = $rows->get($key);
            if (! $row instanceof OrderDashboardSection) {
                continue;
            }
            if ($row->refreshed_at !== null || $row->status === OrderDashboardSection::STATUS_RUNNING) {
                continue;
            }
            RefreshOrderDashboardSectionJob::dispatch($key);
        }
    }

    public function getDashboardPayload(): array
    {
        $this->ensureSectionRows();

        $rows = OrderDashboardSection::query()
            ->whereIn('section_key', OrderDashboardSection::ALL_KEYS)
            ->get()
            ->keyBy('section_key');

        $sections = [];
        foreach (OrderDashboardSection::ALL_KEYS as $key) {
            $row = $rows->get($key);
            $sections[$key] = $this->serializeSection($row instanceof OrderDashboardSection ? $row : null, $key);
        }

        $holdTotal = 0;
        foreach (OrderDashboardSection::HOLD_KEYS as $holdKey) {
            $holdTotal += (int) ($sections[$holdKey]['total_count'] ?? 0);
        }

        return [
            'totals' => [
                'ready_to_ship' => (int) ($sections[OrderDashboardSection::KEY_READY_TO_SHIP]['total_count'] ?? 0),
                'on_hold' => $holdTotal,
                'shipped' => (int) ($sections[OrderDashboardSection::KEY_SHIPPED]['total_count'] ?? 0),
                'asn_pending' => (int) ($sections[OrderDashboardSection::KEY_ASN_PENDING]['total_count'] ?? 0),
            ],
            'sections' => $sections,
        ];
    }

    public function markSectionRunning(string $sectionKey): void
    {
        $this->validateSectionKey($sectionKey);
        $this->ensureSectionRows();

        OrderDashboardSection::query()->where('section_key', $sectionKey)->update([
            'status' => OrderDashboardSection::STATUS_RUNNING,
            'refresh_started_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markSectionFailed(string $sectionKey, string $message): void
    {
        $this->validateSectionKey($sectionKey);
        $this->ensureSectionRows();

        OrderDashboardSection::query()->where('section_key', $sectionKey)->update([
            'status' => OrderDashboardSection::STATUS_FAILED,
            'error_message' => $message !== '' ? $message : 'Refresh failed.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveSectionPayload(string $sectionKey, array $payload, int $totalCount, int $durationMs): void
    {
        $this->validateSectionKey($sectionKey);
        $this->ensureSectionRows();

        OrderDashboardSection::query()->where('section_key', $sectionKey)->update([
            'payload' => $payload,
            'total_count' => max(0, $totalCount),
            'status' => OrderDashboardSection::STATUS_IDLE,
            'refreshed_at' => now(),
            'refresh_started_at' => null,
            'error_message' => null,
            'duration_ms' => max(0, $durationMs),
        ]);
    }

    public function refreshSection(string $sectionKey): void
    {
        $this->validateSectionKey($sectionKey);
        $startedAt = microtime(true);

        $this->markSectionRunning($sectionKey);

        try {
            if ($sectionKey === OrderDashboardSection::KEY_ASN_PENDING) {
                $result = $this->buildAsnPendingPayload();
            } else {
                $result = $this->buildShipHeroSectionPayload($sectionKey);
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->saveSectionPayload(
                $sectionKey,
                $result['payload'],
                (int) $result['total_count'],
                $durationMs
            );

            Log::info('order_dashboard.section_refreshed', [
                'section_key' => $sectionKey,
                'total_count' => (int) $result['total_count'],
                'accounts' => count($result['payload']['accounts'] ?? []),
                'truncated' => (bool) ($result['payload']['truncated'] ?? false),
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            $this->markSectionFailed(
                $sectionKey,
                $e->getMessage() !== '' ? $e->getMessage() : 'Refresh failed.'
            );
            throw $e;
        }
    }

    /**
     * @param  list<string>  $sectionKeys
     */
    public function refreshSections(array $sectionKeys): void
    {
        foreach ($sectionKeys as $key) {
            $this->refreshSection((string) $key);
        }
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function buildAsnPendingPayload(): array
    {
        $counts = ClientAccountAsn::query()
            ->selectRaw('client_account_id, COUNT(*) as aggregate')
            ->where('status', ClientAccountAsn::STATUS_PENDING)
            ->groupBy('client_account_id')
            ->pluck('aggregate', 'client_account_id');

        if ($counts->isEmpty()) {
            return [
                'payload' => ['accounts' => [], 'truncated' => false],
                'total_count' => 0,
            ];
        }

        $accountIds = $counts->keys()->map(static function ($id) {
            return (int) $id;
        })->all();

        $accounts = ClientAccount::query()
            ->whereIn('id', $accountIds)
            ->get(['id', 'company_name', 'status'])
            ->keyBy('id');

        $rows = [];
        $total = 0;
        foreach ($counts as $accountId => $count) {
            $id = (int) $accountId;
            $c = (int) $count;
            if ($c <= 0) {
                continue;
            }
            $account = $accounts->get($id);
            $rows[] = [
                'account_id' => $id,
                'account_name' => $account ? (string) $account->company_name : 'Account #'.$id,
                'account_status' => $account ? (string) $account->status : '',
                'orders_count' => $c,
            ];
            $total += $c;
        }

        usort($rows, static function (array $a, array $b) {
            return ($b['orders_count'] ?? 0) <=> ($a['orders_count'] ?? 0);
        });

        return [
            'payload' => ['accounts' => $rows, 'truncated' => false],
            'total_count' => $total,
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function buildShipHeroSectionPayload(string $sectionKey): array
    {
        $accounts = $this->shipHeroLinkedAccounts();
        if ($accounts === []) {
            return [
                'payload' => ['accounts' => [], 'truncated' => false],
                'total_count' => 0,
            ];
        }

        if ($sectionKey === OrderDashboardSection::KEY_READY_TO_SHIP) {
            return $this->buildReadyToShipPayload($accounts);
        }

        $rows = [];
        $total = 0;
        $truncated = false;

        foreach ($accounts as $account) {
            $context = $this->queueCounts->contextForAccount($account);
            $countResult = $this->countForSection($sectionKey, $context);
            $count = (int) ($countResult['count'] ?? 0);
            $truncated = $truncated || (bool) ($countResult['truncated'] ?? false);
            if ($count <= 0) {
                continue;
            }
            $rows[] = [
                'account_id' => (int) $account->id,
                'account_name' => (string) $account->company_name,
                'account_status' => (string) $account->status,
                'orders_count' => $count,
            ];
            $total += $count;
        }

        usort($rows, static function (array $a, array $b) {
            return ($b['orders_count'] ?? 0) <=> ($a['orders_count'] ?? 0);
        });

        return [
            'payload' => ['accounts' => $rows, 'truncated' => $truncated],
            'total_count' => $total,
        ];
    }

    /**
     * @param  list<ClientAccount>  $accounts
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function buildReadyToShipPayload(array $accounts): array
    {
        $mapped = [];
        foreach ($accounts as $account) {
            $customerId = trim((string) $account->shiphero_customer_account_id);
            if ($customerId === '') {
                continue;
            }
            $context = $this->queueCounts->contextForAccount($account);
            $mapped[] = [
                'id' => (int) $account->id,
                'name' => (string) $account->company_name,
                'customer_account_id' => $customerId,
                'account_status' => (string) $account->status,
                'awaiting_from' => $context['awaiting_from'],
                'awaiting_to' => $context['awaiting_to'],
            ];
        }

        if ($mapped === []) {
            return [
                'payload' => ['accounts' => [], 'truncated' => false],
                'total_count' => 0,
            ];
        }

        $sample = $mapped[0];
        $summary = $this->orders->readyToShipSummaryForAccounts(
            array_map(static function (array $row) {
                return [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'customer_account_id' => (string) $row['customer_account_id'],
                ];
            }, $mapped),
            $this->isoDateOnly($sample['awaiting_from'] ?? null),
            $this->isoDateOnly($sample['awaiting_to'] ?? null)
        );

        $statusById = [];
        foreach ($mapped as $row) {
            $statusById[(int) $row['id']] = (string) ($row['account_status'] ?? '');
        }

        $accountsOut = [];
        foreach ($summary['ready_to_ship_by_account'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['account_id'] ?? 0);
            $count = (int) ($row['orders_count'] ?? 0);
            if ($id <= 0 || $count <= 0) {
                continue;
            }
            $accountsOut[] = [
                'account_id' => $id,
                'account_name' => (string) ($row['account_name'] ?? 'Account'),
                'account_status' => $statusById[$id] ?? '',
                'orders_count' => $count,
            ];
        }

        return [
            'payload' => [
                'accounts' => $accountsOut,
                'truncated' => false,
            ],
            'total_count' => (int) ($summary['ready_to_ship_total'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{count: int, truncated: bool}
     */
    private function countForSection(string $sectionKey, array $context): array
    {
        if ($sectionKey === OrderDashboardSection::KEY_SHIPPED) {
            return $this->orders->countShipments([
                'customer_account_id' => $context['customer_id'],
                'date_from' => $context['shipped_from'],
                'date_to' => $context['shipped_to'],
                'timezone' => $context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                'max_pages' => 200,
            ]);
        }

        if ($sectionKey === OrderDashboardSection::KEY_HOLD_BACKORDER) {
            return $this->orders->countOrders([
                'customer_account_id' => $context['customer_id'],
                'tab' => 'backorder',
                'order_date_from' => $context['open_from'],
                'order_date_to' => $context['open_to'],
                'max_pages' => 50,
            ]);
        }

        $holdReason = $this->holdReasonForSection($sectionKey);
        if ($holdReason === null) {
            throw new RuntimeException('Unsupported hold section: '.$sectionKey);
        }

        return $this->orders->countHoldOrdersForAccount(
            (string) $context['customer_id'],
            $holdReason,
            $context['open_from'],
            $context['open_to']
        );
    }

    private function holdReasonForSection(string $sectionKey): ?string
    {
        switch ($sectionKey) {
            case OrderDashboardSection::KEY_HOLD_OPERATOR:
                return 'operator';
            case OrderDashboardSection::KEY_HOLD_ADDRESS:
                return 'address';
            case OrderDashboardSection::KEY_HOLD_FRAUD:
                return 'fraud';
            case OrderDashboardSection::KEY_HOLD_PAYMENT:
                return 'payment';
            case OrderDashboardSection::KEY_HOLD_USER:
                return 'user';
            default:
                return null;
        }
    }

    /**
     * @return list<ClientAccount>
     */
    private function shipHeroLinkedAccounts(): array
    {
        return ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name')
            ->get()
            ->all();
    }

    private function ensureSectionRows(): void
    {
        $existing = OrderDashboardSection::query()
            ->whereIn('section_key', OrderDashboardSection::ALL_KEYS)
            ->pluck('section_key')
            ->all();

        $missing = array_diff(OrderDashboardSection::ALL_KEYS, $existing);
        if ($missing === []) {
            return;
        }

        $now = now();
        foreach ($missing as $key) {
            OrderDashboardSection::query()->insert([
                'section_key' => $key,
                'payload' => json_encode(['accounts' => [], 'truncated' => false]),
                'total_count' => 0,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSection(?OrderDashboardSection $row, string $fallbackKey): array
    {
        if (! $row instanceof OrderDashboardSection) {
            return [
                'section_key' => $fallbackKey,
                'total_count' => 0,
                'status' => OrderDashboardSection::STATUS_IDLE,
                'refreshed_at' => null,
                'refresh_started_at' => null,
                'error_message' => null,
                'duration_ms' => null,
                'accounts' => [],
                'truncated' => false,
            ];
        }

        $payload = is_array($row->payload) ? $row->payload : [];
        $accounts = isset($payload['accounts']) && is_array($payload['accounts']) ? $payload['accounts'] : [];

        return [
            'section_key' => (string) $row->section_key,
            'total_count' => (int) $row->total_count,
            'status' => (string) $row->status,
            'refreshed_at' => $row->refreshed_at !== null ? $row->refreshed_at->toIso8601String() : null,
            'refresh_started_at' => $row->refresh_started_at !== null ? $row->refresh_started_at->toIso8601String() : null,
            'error_message' => $row->error_message,
            'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
            'accounts' => $accounts,
            'truncated' => (bool) ($payload['truncated'] ?? false),
        ];
    }

    private function validateSectionKey(string $sectionKey): void
    {
        if ($sectionKey === 'all') {
            return;
        }
        if (! in_array($sectionKey, OrderDashboardSection::ALL_KEYS, true)) {
            throw new RuntimeException('Invalid dashboard section: '.$sectionKey);
        }
    }

    private function isoDateOnly(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }
        try {
            return Carbon::parse($iso)->toDateString();
        } catch (Throwable $e) {
            return null;
        }
    }
}
