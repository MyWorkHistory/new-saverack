<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use App\Support\TimeBudgetedFanout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class CrossAccountInventoryListService
{
    private const MAX_ACCOUNTS_PER_REQUEST = 40;

    private const MAX_MERGED_ROWS = 100;

    private const CROSS_ACCOUNT_WALL_SECONDS = 25;

    /** @var ShipHeroInventoryService */
    private $inventory;

    public function __construct(ShipHeroInventoryService $inventory)
    {
        $this->inventory = $inventory;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, page_info: array<string, mixed>, meta: array<string, mixed>}
     */
    public function list(User $user, array $filters): array
    {
        $accounts = $this->eligibleAccounts($user);
        if ($accounts->isEmpty()) {
            return [
                'rows' => [],
                'page_info' => [
                    'has_next_page' => false,
                    'end_cursor' => null,
                ],
                'meta' => [
                    'cross_account' => true,
                    'accounts_queried' => 0,
                    'accounts_total' => 0,
                    'scan_truncated' => false,
                ],
            ];
        }

        $accountsTotal = $accounts->count();
        $perAccountFirst = max(5, (int) floor(self::MAX_MERGED_ROWS / max(1, $accountsTotal)));
        $kits = isset($filters['kits']) && is_string($filters['kits']) ? $filters['kits'] : 'all';
        $activeStatus = isset($filters['active_status']) && is_string($filters['active_status'])
            ? $filters['active_status']
            : 'active';
        $searchQuery = isset($filters['query']) && is_string($filters['query']) ? trim($filters['query']) : '';
        $searchSkip = isset($filters['search_skip']) ? (int) $filters['search_skip'] : 0;
        $backorderOnly = (bool) ($filters['backorder_only'] ?? false);
        $refresh = (bool) ($filters['refresh'] ?? false);
        $deadline = microtime(true) + self::CROSS_ACCOUNT_WALL_SECONDS;
        $rows = [];

        $fanout = TimeBudgetedFanout::run(
            $accounts,
            function (ClientAccount $account) use (
                $perAccountFirst,
                $kits,
                $activeStatus,
                $searchQuery,
                $searchSkip,
                $backorderOnly,
                $refresh,
                &$rows
            ) {
                try {
                    $customerId = trim((string) $account->shiphero_customer_account_id);
                    $payload = $this->inventory->listInventoryRows(
                        $customerId,
                        $perAccountFirst,
                        null,
                        $kits,
                        $activeStatus,
                        $searchQuery !== '' ? $searchQuery : null,
                        $searchSkip,
                        (int) $account->id,
                        $backorderOnly,
                        $refresh
                    );
                } catch (RuntimeException $e) {
                    return null;
                } catch (Throwable $e) {
                    report($e);

                    return null;
                }

                foreach ($payload['rows'] ?? [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $rows[] = $this->annotateRow($row, $account);
                }

                return null;
            },
            $deadline
        );

        $rows = $this->sortRows($rows);
        $rows = array_slice($rows, 0, self::MAX_MERGED_ROWS);

        return [
            'rows' => array_values($rows),
            'page_info' => [
                'has_next_page' => false,
                'end_cursor' => null,
            ],
            'meta' => [
                'cross_account' => true,
                'accounts_queried' => $fanout['processed'],
                'accounts_total' => $accountsTotal,
                'accounts_scan_limit' => self::MAX_ACCOUNTS_PER_REQUEST,
                'scan_truncated' => $fanout['truncated'],
            ],
        ];
    }

    /**
     * Beta catalog search across accounts the user may view (local index, substring match).
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, page_info: array<string, mixed>, meta: array<string, mixed>}
     */
    public function listCatalog(User $user, array $filters): array
    {
        $accounts = $this->eligibleAccounts($user);
        $accountsTotal = $accounts->count();
        $searchQuery = isset($filters['query']) && is_string($filters['query']) ? trim($filters['query']) : '';
        $first = isset($filters['first']) ? (int) $filters['first'] : 50;
        $searchSkip = isset($filters['search_skip']) ? (int) $filters['search_skip'] : 0;
        $kits = isset($filters['kits']) && is_string($filters['kits']) ? $filters['kits'] : 'all';
        $activeStatus = isset($filters['active_status']) && is_string($filters['active_status'])
            ? $filters['active_status']
            : 'active';
        $backorderOnly = (bool) ($filters['backorder_only'] ?? false);

        if ($searchQuery === '') {
            return [
                'rows' => [],
                'page_info' => [
                    'has_next_page' => false,
                    'end_cursor' => null,
                ],
                'meta' => [
                    'cross_account' => true,
                    'accounts_queried' => 0,
                    'accounts_total' => $accountsTotal,
                    'scan_truncated' => false,
                ],
            ];
        }

        $accountsById = $accounts->keyBy('id');
        $payload = $this->inventory->searchCatalogIndexForAccounts(
            $accountsById->keys()->all(),
            $searchQuery,
            $first,
            $searchSkip,
            $kits,
            $activeStatus,
            $backorderOnly
        );

        $rows = [];
        foreach ($payload['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $accountId = (int) ($row['client_account_id'] ?? 0);
            $account = $accountsById->get($accountId);
            if ($account === null) {
                continue;
            }
            $rows[] = $this->annotateRow($row, $account);
        }

        return [
            'rows' => array_values($rows),
            'page_info' => $payload['page_info'],
            'meta' => [
                'cross_account' => true,
                'accounts_queried' => $accountsTotal,
                'accounts_total' => $accountsTotal,
                'scan_truncated' => false,
            ],
        ];
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
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function annotateRow(array $row, ClientAccount $account): array
    {
        $row['client_account_id'] = (int) $account->id;
        $row['client_account_company_name'] = (string) $account->company_name;

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, function (array $a, array $b) {
            $oa = (int) ($a['on_hand'] ?? 0);
            $ob = (int) ($b['on_hand'] ?? 0);
            if ($oa !== $ob) {
                return $ob <=> $oa;
            }

            return strcasecmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? ''));
        });

        return $rows;
    }
}
