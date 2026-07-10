<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\OrderDashboardSection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live ShipHero API aggregates for admin Home / Fulfillment dashboard totals.
 *
 * Matches ShipHero UI semantics:
 * - Ready to ship: order_date May 1 → today, ready_to_ship + unfulfilled
 * - On-hold: order_date today, has_hold + unfulfilled (excludes backorder via listOrders post-filter)
 * - Shipped today: shipments API label count for today
 */
class ShipHeroDashboardMetricsService
{
    private const CACHE_TTL_MINUTES = 10;

    private const COUNT_MAX_PAGES = 50;

    /** @var ShipHeroOrderService */
    private $orders;

    /** @var PortalQueueCountsService */
    private $queueCounts;

    public function __construct(ShipHeroOrderService $orders, PortalQueueCountsService $queueCounts)
    {
        $this->orders = $orders;
        $this->queueCounts = $queueCounts;
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    public function aggregateReadyToShip(bool $useCache = true): array
    {
        return $this->cachedAggregate('ready_to_ship', $useCache, function () {
            return $this->aggregateAcrossAccounts(function (ClientAccount $account, array $context) {
                $from = $this->isoDateOnly($context['awaiting_from'] ?? null);
                $to = $this->isoDateOnly($context['awaiting_to'] ?? null);

                return $this->orders->countOrders([
                    'customer_account_id' => (string) $context['customer_id'],
                    'tab' => 'awaiting',
                    'order_date_from' => $from,
                    'order_date_to' => $to,
                    'timezone' => $context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                    'max_pages' => self::COUNT_MAX_PAGES,
                ]);
            }, OrderDashboardSection::KEY_READY_TO_SHIP);
        });
    }

    /**
     * On-hold orders with order placement date = today (matches ShipHero dashboard filter).
     *
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    public function aggregateOnHoldToday(bool $useCache = true): array
    {
        return $this->cachedAggregate('on_hold_today', $useCache, function () {
            return $this->aggregateAcrossAccounts(function (ClientAccount $account, array $context) {
                $from = $this->isoDateOnly($context['open_from'] ?? null);
                $to = $this->isoDateOnly($context['open_to'] ?? null);

                return $this->orders->countOrders([
                    'customer_account_id' => (string) $context['customer_id'],
                    'tab' => 'on_hold',
                    'order_date_from' => $from,
                    'order_date_to' => $to,
                    'timezone' => $context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                    'max_pages' => self::COUNT_MAX_PAGES,
                ]);
            }, null);
        });
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    public function aggregateShippedToday(bool $useCache = true): array
    {
        return $this->cachedAggregate('shipped_today', $useCache, function () {
            return $this->aggregateAcrossAccounts(function (ClientAccount $account, array $context) {
                return $this->orders->countShipments([
                    'customer_account_id' => (string) $context['customer_id'],
                    'date_from' => $context['shipped_from'],
                    'date_to' => $context['shipped_to'],
                    'timezone' => $context['timezone'] ?? PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE,
                    'max_pages' => 200,
                ]);
            }, OrderDashboardSection::KEY_SHIPPED);
        });
    }

    /**
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    public function aggregateForSection(string $sectionKey, bool $useCache = true): array
    {
        switch ($sectionKey) {
            case OrderDashboardSection::KEY_READY_TO_SHIP:
                return $this->aggregateReadyToShip($useCache);
            case OrderDashboardSection::KEY_SHIPPED:
                return $this->aggregateShippedToday($useCache);
            default:
                throw new \RuntimeException('Unsupported live metrics section: '.$sectionKey);
        }
    }

    /**
     * @param  callable(ClientAccount, array<string, mixed>): array{count: int, truncated: bool}  $countForAccount
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function aggregateAcrossAccounts(callable $countForAccount, ?string $sectionKey): array
    {
        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'status', 'shiphero_customer_account_id']);

        $rows = [];
        $total = 0;
        $truncated = false;
        $failures = 0;

        foreach ($accounts as $account) {
            $context = $sectionKey !== null
                ? $this->queueCounts->contextForDashboardSection($account, $sectionKey)
                : $this->queueCounts->contextForOnHoldDashboardTotal($account);

            try {
                $result = $countForAccount($account, $context);
            } catch (Throwable $e) {
                $failures++;
                Log::warning('shiphero_dashboard_metrics.account_count_failed', [
                    'client_account_id' => (int) $account->id,
                    'section_key' => $sectionKey,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $count = (int) ($result['count'] ?? 0);
            $truncated = $truncated || (bool) ($result['truncated'] ?? false);

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
            'payload' => [
                'accounts' => $rows,
                'truncated' => $truncated,
                'accounts_failed' => $failures,
                'accounts_total' => $accounts->count(),
            ],
            'total_count' => $total,
        ];
    }

    /**
     * @param  callable(): array{payload: array<string, mixed>, total_count: int}  $compute
     * @return array{payload: array<string, mixed>, total_count: int}
     */
    private function cachedAggregate(string $metricKey, bool $useCache, callable $compute): array
    {
        $timezone = PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;
        $today = Carbon::now($timezone)->toDateString();
        $cacheKey = sprintf('orders:dashboard_metrics:v1:%s:%s', $metricKey, $today);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)
                && isset($cached['payload'], $cached['total_count'])
                && is_array($cached['payload'])) {
                return [
                    'payload' => $cached['payload'],
                    'total_count' => (int) $cached['total_count'],
                ];
            }
        }

        $result = $compute();

        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $failures = (int) ($payload['accounts_failed'] ?? 0);
        $accountsTotal = (int) ($payload['accounts_total'] ?? 0);
        $shouldCache = $accountsTotal === 0
            || $failures < $accountsTotal
            || (int) ($result['total_count'] ?? 0) > 0;

        if ($shouldCache) {
            Cache::put($cacheKey, $result, now()->addMinutes(self::CACHE_TTL_MINUTES));
        }

        return $result;
    }

    /**
     * Read cached on-hold total for dashboard display (never calls ShipHero).
     */
    public function cachedOnHoldTotal(): int
    {
        $timezone = PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;
        $today = Carbon::now($timezone)->toDateString();
        $cacheKey = sprintf('orders:dashboard_metrics:v1:%s:%s', 'on_hold_today', $today);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return (int) ($cached['total_count'] ?? 0);
        }

        return 0;
    }

    public function clearCacheForToday(): void
    {
        $timezone = PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;
        $today = Carbon::now($timezone)->toDateString();
        foreach (['ready_to_ship', 'on_hold_today', 'shipped_today'] as $metricKey) {
            Cache::forget(sprintf('orders:dashboard_metrics:v1:%s:%s', $metricKey, $today));
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
