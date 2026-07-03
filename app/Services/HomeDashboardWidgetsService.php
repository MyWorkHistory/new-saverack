<?php

namespace App\Services;

use App\Models\ClientAccount;
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
     *     put_away_by_account: list<array<string, mixed>>,
     *     restock_preview: list<array<string, mixed>>
     * }
     */
    public function widgetsForUser(?User $user): array
    {
        if ($user === null) {
            return $this->emptyWidgets();
        }

        return [
            'paused_accounts' => $user->hasPermission('clients.view')
                ? $this->pausedAccounts()
                : [],
            'put_away_by_account' => $user->hasPermission('receiving.view')
                ? $this->putAwayByAccount()
                : [],
            'restock_preview' => $user->hasPermission('inventory.view')
                ? $this->restockBeta->previewActiveRows(5)
                : [],
        ];
    }

    /**
     * @return array{
     *     paused_accounts: list<array<string, mixed>>,
     *     put_away_by_account: list<array<string, mixed>>,
     *     restock_preview: list<array<string, mixed>>
     * }
     */
    private function emptyWidgets(): array
    {
        return [
            'paused_accounts' => [],
            'put_away_by_account' => [],
            'restock_preview' => [],
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
            ->get(['id', 'company_name', 'paused_at'])
            ->map(static fn (ClientAccount $account) => [
                'id' => (int) $account->id,
                'company_name' => (string) $account->company_name,
                'paused_at' => optional($account->paused_at)->toIso8601String(),
            ])
            ->values()
            ->all();
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
