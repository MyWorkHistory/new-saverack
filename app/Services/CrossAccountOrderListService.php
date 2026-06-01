<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class CrossAccountOrderListService
{
    private const MAX_ACCOUNTS_PER_REQUEST = 40;

    private const MAX_MERGED_ROWS = 100;

    /** @var ShipHeroOrderService */
    private $orders;

    /** @var PortalQueueCountsService */
    private $portalQueueCounts;

    public function __construct(ShipHeroOrderService $orders, PortalQueueCountsService $portalQueueCounts)
    {
        $this->orders = $orders;
        $this->portalQueueCounts = $portalQueueCounts;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, pagination: array<string, mixed>, meta: array<string, mixed>}
     */
    public function list(User $user, array $filters): array
    {
        $accounts = $this->eligibleAccounts($user);
        if ($accounts->isEmpty()) {
            return [
                'rows' => [],
                'pagination' => [
                    'has_next_page' => false,
                    'end_cursor' => null,
                ],
                'meta' => [
                    'cross_account' => true,
                    'accounts_queried' => 0,
                ],
            ];
        }

        $orderNumber = isset($filters['order_number']) ? trim(ltrim((string) $filters['order_number'], '#')) : '';
        if ($orderNumber !== '') {
            return $this->listByOrderNumber($accounts, $filters, $orderNumber);
        }

        return $this->listMergedAcrossAccounts($accounts, $filters);
    }

    /**
     * @return Collection<int, ClientAccount>
     */
    private function eligibleAccounts(User $user): Collection
    {
        return ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name')
            ->orderBy('id')
            ->get()
            ->filter(function (ClientAccount $account) use ($user) {
                if ($user->isAdministrator() || $user->isCrmOwner()) {
                    return true;
                }

                return Gate::forUser($user)->allows('view', $account);
            })
            ->values()
            ->take(self::MAX_ACCOUNTS_PER_REQUEST);
    }

    /**
     * @param  Collection<int, ClientAccount>  $accounts
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, pagination: array<string, mixed>, meta: array<string, mixed>}
     */
    private function listByOrderNumber(Collection $accounts, array $filters, string $orderNumber): array
    {
        $rows = [];
        $queried = 0;

        foreach ($accounts as $account) {
            $queried++;
            try {
                $customerId = trim((string) $account->shiphero_customer_account_id);
                $payload = $this->fetchAccountList($account, $customerId, $filters, $orderNumber, null);
            } catch (RuntimeException $e) {
                continue;
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($payload['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = $this->annotateRow($row, $account);
            }
        }

        $rows = $this->sortRows($rows);
        $rows = array_slice($rows, 0, self::MAX_MERGED_ROWS);

        return [
            'rows' => array_values($rows),
            'pagination' => [
                'has_next_page' => false,
                'end_cursor' => null,
            ],
            'meta' => [
                'cross_account' => true,
                'accounts_queried' => $queried,
                'order_number' => $orderNumber,
            ],
        ];
    }

    /**
     * @param  Collection<int, ClientAccount>  $accounts
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, pagination: array<string, mixed>, meta: array<string, mixed>}
     */
    private function listMergedAcrossAccounts(Collection $accounts, array $filters): array
    {
        $rows = [];
        $queried = 0;
        $perAccountFirst = max(5, (int) floor(self::MAX_MERGED_ROWS / max(1, $accounts->count())));

        foreach ($accounts as $account) {
            $queried++;
            try {
                $customerId = trim((string) $account->shiphero_customer_account_id);
                $payload = $this->fetchAccountList($account, $customerId, $filters, null, $perAccountFirst);
            } catch (RuntimeException $e) {
                continue;
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($payload['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = $this->annotateRow($row, $account);
            }
        }

        $rows = $this->sortRows($rows);
        $rows = array_slice($rows, 0, self::MAX_MERGED_ROWS);

        return [
            'rows' => array_values($rows),
            'pagination' => [
                'has_next_page' => false,
                'end_cursor' => null,
            ],
            'meta' => [
                'cross_account' => true,
                'accounts_queried' => $queried,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function fetchAccountList(
        ClientAccount $account,
        string $customerId,
        array $filters,
        ?string $orderNumber,
        ?int $first
    ): array {
        $tab = (string) ($filters['tab'] ?? 'manage');
        $shipDateFrom = $this->dateStartIso($filters['order_date_from'] ?? null);
        $shipDateTo = $this->dateEndIso($filters['order_date_to'] ?? null);
        $timezone = PortalQueueCountsService::DEFAULT_ACCOUNT_TIMEZONE;

        if ($tab === 'shipped') {
            $context = $this->portalQueueCounts->contextForAccount($account, [
                'order_date_from' => $filters['order_date_from'] ?? null,
                'order_date_to' => $filters['order_date_to'] ?? null,
            ]);
            $shipDateFrom = $context['shipped_from'];
            $shipDateTo = $context['shipped_to'];
            $timezone = $context['timezone'];

            return $this->orders->listShippedOrders([
                'customer_account_id' => $customerId,
                'order_date_from' => $shipDateFrom,
                'order_date_to' => $shipDateTo,
                'timezone' => $timezone,
                'order_number' => $orderNumber,
                'first' => $first ?? (int) ($filters['first'] ?? 25),
            ]);
        }

        return $this->orders->listOrders([
            'customer_account_id' => $customerId,
            'tab' => $tab,
            'order_date_from' => $shipDateFrom,
            'order_date_to' => $shipDateTo,
            'timezone' => $timezone,
            'fulfillment_status' => $filters['fulfillment_status'] ?? null,
            'ready_to_ship' => array_key_exists('ready_to_ship', $filters) ? (bool) $filters['ready_to_ship'] : null,
            'hold_reason' => $filters['hold_reason'] ?? null,
            'order_number' => $orderNumber,
            'first' => $first ?? (int) ($filters['first'] ?? 25),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function annotateRow(array $row, ClientAccount $account): array
    {
        $row['client_account_id'] = (int) $account->id;
        $row['client_account_company_name'] = (string) $account->company_name;
        if (trim((string) ($row['account'] ?? '')) === '') {
            $row['account'] = (string) $account->company_name;
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, function (array $a, array $b) {
            $da = $this->rowSortTimestamp($a);
            $db = $this->rowSortTimestamp($b);

            return $db <=> $da;
        });

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowSortTimestamp(array $row): int
    {
        $raw = $row['order_date'] ?? $row['ship_date'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return 0;
        }

        try {
            return Carbon::parse($raw)->getTimestamp();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function dateStartIso($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->startOfDay()->toIso8601String();
    }

    private function dateEndIso($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->endOfDay()->toIso8601String();
    }
}
