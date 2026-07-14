<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ShipHeroInventoryProductIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregates inventory-index oversold (backorder qty) rows by CRM client account.
 */
class InventoryOutOfStockByAccountService
{
    /**
     * @return array{
     *     accounts: list<array{account_id: int, account_name: string, account_status: string, orders_count: int, sku_count: int}>,
     *     total_count: int,
     *     total_sku_count: int
     * }
     */
    public function summarize(?int $limit = null): array
    {
        if (! Schema::hasTable('shiphero_inventory_product_index')) {
            return [
                'accounts' => [],
                'total_count' => 0,
                'total_sku_count' => 0,
            ];
        }

        $rows = ShipHeroInventoryProductIndex::query()
            ->from('shiphero_inventory_product_index as idx')
            ->join('client_accounts as ca', 'ca.id', '=', 'idx.client_account_id')
            ->where('idx.backorder', '>', 0)
            ->where('idx.product_active', true)
            ->groupBy('idx.client_account_id', 'ca.company_name', 'ca.status')
            ->orderByDesc(DB::raw('SUM(idx.backorder)'))
            ->orderBy('ca.company_name')
            ->select([
                'idx.client_account_id as account_id',
                'ca.company_name as account_name',
                'ca.status as account_status',
                DB::raw('SUM(idx.backorder) as backorder_units'),
                DB::raw('COUNT(DISTINCT idx.sku) as sku_count'),
            ])
            ->get();

        $accounts = [];
        $totalUnits = 0;
        $totalSkus = 0;

        foreach ($rows as $row) {
            $units = (int) round((float) ($row->backorder_units ?? 0));
            $skus = (int) ($row->sku_count ?? 0);
            if ($units <= 0) {
                continue;
            }

            $accounts[] = [
                'account_id' => (int) $row->account_id,
                'account_name' => (string) ($row->account_name ?? ''),
                'account_status' => (string) ($row->account_status ?? ClientAccount::STATUS_ACTIVE),
                // Reuse orders_count so OrdersAccountSectionPanel can render without custom mapping.
                'orders_count' => $units,
                'sku_count' => $skus,
            ];
            $totalUnits += $units;
            $totalSkus += $skus;
        }

        if ($limit !== null && $limit > 0) {
            $accounts = array_slice($accounts, 0, $limit);
        }

        return [
            'accounts' => array_values($accounts),
            'total_count' => $totalUnits,
            'total_sku_count' => $totalSkus,
        ];
    }
}
