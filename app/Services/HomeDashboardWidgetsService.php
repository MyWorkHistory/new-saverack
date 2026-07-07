<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\PutAwayReceivingSnapshot;
use App\Models\PutAwayReceivingSnapshotRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HomeDashboardWidgetsService
{
    /** @var InventoryRestockBetaService */
    private $restockBeta;

    public function __construct(InventoryRestockBetaService $restockBeta)
    {
        $this->restockBeta = $restockBeta;
    }

    /**
     * @return array{
     *     paused_accounts: list<array<string, mixed>>,
     *     pending_new_accounts: list<array<string, mixed>>,
     *     pending_asn_preview: list<array<string, mixed>>,
     *     put_away_by_account: list<array<string, mixed>>,
     *     restock_preview: list<array<string, mixed>>,
     *     restock_active_count: int
     * }
     */
    public function widgetsForUser(?User $user): array
    {
        if ($user === null) {
            return $this->emptyWidgets();
        }

        $canViewClients = $user->hasPermission('clients.view');
        $canViewInventory = $user->hasPermission('inventory.view');

        return [
            'paused_accounts' => $canViewClients ? $this->pausedAccounts() : [],
            'pending_new_accounts' => $canViewClients ? $this->pendingNewAccounts() : [],
            'pending_asn_preview' => $canViewClients ? $this->pendingAsnPreview() : [],
            'put_away_by_account' => $user->hasPermission('receiving.view')
                ? $this->putAwayByAccount()
                : [],
            'restock_preview' => $canViewInventory
                ? $this->restockBeta->previewActiveRows(5)
                : [],
            'restock_active_count' => $canViewInventory
                ? $this->restockBeta->activeRowCount()
                : 0,
        ];
    }

    /**
     * @return array{
     *     paused_accounts: list<array<string, mixed>>,
     *     pending_new_accounts: list<array<string, mixed>>,
     *     pending_asn_preview: list<array<string, mixed>>,
     *     put_away_by_account: list<array<string, mixed>>,
     *     restock_preview: list<array<string, mixed>>,
     *     restock_active_count: int
     * }
     */
    private function emptyWidgets(): array
    {
        return [
            'paused_accounts' => [],
            'pending_new_accounts' => [],
            'pending_asn_preview' => [],
            'put_away_by_account' => [],
            'restock_preview' => [],
            'restock_active_count' => 0,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pausedAccounts(): array
    {
        return ClientAccount::query()
            ->where('status', ClientAccount::STATUS_PAUSED)
            ->orderByDesc('paused_at')
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'paused_at', 'pause_reason'])
            ->map(static fn (ClientAccount $account) => [
                'id' => (int) $account->id,
                'company_name' => (string) $account->company_name,
                'paused_at' => optional($account->paused_at)->toIso8601String(),
                'pause_reason' => ClientAccount::pauseReasonLabel($account->pause_reason),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingNewAccounts(): array
    {
        return ClientAccount::query()
            ->where('status', ClientAccount::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->orderBy('company_name')
            ->limit(4)
            ->get(['id', 'company_name', 'created_at'])
            ->map(static fn (ClientAccount $account) => [
                'id' => (int) $account->id,
                'company_name' => (string) $account->company_name,
                'created_at' => optional($account->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingAsnPreview(): array
    {
        $rows = ClientAccountAsn::query()
            ->from('client_account_asns as asns')
            ->join('client_accounts', 'client_accounts.id', '=', 'asns.client_account_id')
            ->where('asns.status', ClientAccountAsn::STATUS_PENDING)
            ->groupBy('asns.client_account_id', 'client_accounts.company_name')
            ->orderByDesc(DB::raw('SUM(asns.expected_qty)'))
            ->orderBy('client_accounts.company_name')
            ->limit(4)
            ->select([
                'asns.client_account_id as account_id',
                'client_accounts.company_name as account_name',
                DB::raw('SUM(asns.total_pallets) as total_pallets'),
                DB::raw('SUM(asns.total_boxes) as total_boxes'),
                DB::raw('SUM(asns.expected_qty) as total_items'),
                DB::raw('COUNT(*) as asn_count'),
            ])
            ->get();

        return $rows->map(static function ($row) {
            $pallets = (int) $row->total_pallets;
            $boxes = (int) $row->total_boxes;
            $asnCount = (int) $row->asn_count;

            return [
                'account_id' => (int) $row->account_id,
                'account_name' => (string) $row->account_name,
                'unit_label' => self::formatAsnUnitLabel($pallets, $boxes, $asnCount),
                'total_items' => (int) $row->total_items,
            ];
        })->values()->all();
    }

    private static function formatAsnUnitLabel(int $pallets, int $boxes, int $asnCount): string
    {
        if ($pallets > 0) {
            return $pallets.' Pallet'.($pallets === 1 ? '' : 's');
        }
        if ($boxes > 0) {
            return $boxes.' '.($boxes === 1 ? 'Carton' : 'Cartons');
        }
        if ($asnCount > 0) {
            return $asnCount.' Shipment'.($asnCount === 1 ? '' : 's');
        }

        return '—';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function putAwayByAccount(): array
    {
        $snapshot = PutAwayReceivingSnapshot::query()
            ->orderByDesc('computed_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return [];
        }

        $rows = PutAwayReceivingSnapshotRow::query()
            ->from('put_away_receiving_snapshot_rows as rows')
            ->join('client_accounts', 'client_accounts.id', '=', 'rows.client_account_id')
            ->where('rows.put_away_receiving_snapshot_id', $snapshot->id)
            ->where('rows.receiving_qty', '>', 0)
            ->groupBy('rows.client_account_id', 'client_accounts.company_name')
            ->orderByDesc(DB::raw('SUM(rows.receiving_qty)'))
            ->orderBy('client_accounts.company_name')
            ->select([
                'rows.client_account_id as account_id',
                'client_accounts.company_name as account_name',
                DB::raw('SUM(rows.receiving_qty) as total_qty'),
            ])
            ->get();

        return $rows->map(static fn ($row) => [
            'account_id' => (int) $row->account_id,
            'account_name' => (string) $row->account_name,
            'total_qty' => (int) $row->total_qty,
        ])->values()->all();
    }
}
