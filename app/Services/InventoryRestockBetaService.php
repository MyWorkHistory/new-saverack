<?php

namespace App\Services;

use App\Models\InventoryRestockBetaSnapshot;
use App\Models\ShipHeroInventoryProductIndex;
use App\Models\User;
use App\Support\Inventory\RestockBetaCsvParser;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class InventoryRestockBetaService
{
    /** @var RestockBetaCsvParser */
    private $parser;

    public function __construct(RestockBetaCsvParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * @return array<string, mixed>
     */
    public function importCsv(UploadedFile $file, ?User $actor): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new RuntimeException('Could not read uploaded file.');
        }

        $rows = $this->enrichRowsWithAccounts($this->parser->parseFile($path));
        $uploadedAt = now();

        InventoryRestockBetaSnapshot::query()->delete();

        $snapshot = InventoryRestockBetaSnapshot::query()->create([
            'uploaded_by_user_id' => $actor !== null ? $actor->id : null,
            'original_filename' => $file->getClientOriginalName(),
            'row_count' => count($rows),
            'rows' => $rows,
            'completed_skus' => [],
            'uploaded_at' => $uploadedAt,
        ]);

        return $this->toArray($snapshot);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestSnapshot(): ?array
    {
        $snapshot = InventoryRestockBetaSnapshot::query()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return null;
        }

        return $this->toArray($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public function completeSku(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('SKU is required.');
        }

        $snapshot = InventoryRestockBetaSnapshot::query()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            throw new RuntimeException('No restock snapshot to update.');
        }

        $completed = is_array($snapshot->completed_skus) ? $snapshot->completed_skus : [];
        $skuLower = mb_strtolower($sku);
        $alreadyDone = false;
        foreach ($completed as $completedSku) {
            if (mb_strtolower(trim((string) $completedSku)) === $skuLower) {
                $alreadyDone = true;
                break;
            }
        }
        if (! $alreadyDone) {
            $completed[] = $sku;
        }

        $snapshot->completed_skus = $completed;
        $snapshot->save();

        return $this->toArray($snapshot);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function enrichRowsWithAccounts(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $skuKeys = [];
        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) ($row['sku'] ?? '')));
            if ($key !== '') {
                $skuKeys[$key] = true;
            }
        }

        if ($skuKeys === []) {
            return $rows;
        }

        $accountsBySku = $this->lookupAccountsBySku(array_keys($skuKeys));

        foreach ($rows as $index => $row) {
            $key = mb_strtolower(trim((string) ($row['sku'] ?? '')));
            $match = $accountsBySku[$key] ?? null;
            $rows[$index]['client_account_id'] = $match !== null ? (int) $match['client_account_id'] : null;
            $rows[$index]['account_name'] = $match !== null ? (string) $match['account_name'] : '';
        }

        return $rows;
    }

    /**
     * @param  list<string>  $skuKeys
     * @return array<string, array{client_account_id: int, account_name: string}>
     */
    private function lookupAccountsBySku(array $skuKeys): array
    {
        if ($skuKeys === []) {
            return [];
        }

        $matches = ShipHeroInventoryProductIndex::query()
            ->join('client_accounts', 'client_accounts.id', '=', 'shiphero_inventory_product_index.client_account_id')
            ->whereIn('shiphero_inventory_product_index.sku_search', $skuKeys)
            ->orderBy('client_accounts.company_name')
            ->get([
                'shiphero_inventory_product_index.sku_search',
                'shiphero_inventory_product_index.client_account_id',
                'client_accounts.company_name',
            ]);

        $map = [];
        foreach ($matches as $match) {
            $key = (string) $match->sku_search;
            if ($key === '' || isset($map[$key])) {
                continue;
            }
            $map[$key] = [
                'client_account_id' => (int) $match->client_account_id,
                'account_name' => (string) $match->company_name,
            ];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(InventoryRestockBetaSnapshot $snapshot): array
    {
        $uploadedAt = $snapshot->uploaded_at;
        $activeRows = $this->activeRows($snapshot);
        $restockNeededTotal = 0;
        foreach ($activeRows as $row) {
            if (isset($row['restock_needed']) && is_numeric($row['restock_needed'])) {
                $restockNeededTotal += (int) $row['restock_needed'];
            }
        }

        return [
            'original_filename' => $snapshot->original_filename,
            'row_count' => (int) $snapshot->row_count,
            'active_row_count' => count($activeRows),
            'restock_needed_total' => $restockNeededTotal,
            'uploaded_at' => $uploadedAt !== null ? $uploadedAt->toIso8601String() : null,
            'rows' => $activeRows,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function completedSkuSet(InventoryRestockBetaSnapshot $snapshot): array
    {
        $completed = is_array($snapshot->completed_skus) ? $snapshot->completed_skus : [];
        $set = [];
        foreach ($completed as $sku) {
            $key = mb_strtolower(trim((string) $sku));
            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeRows(InventoryRestockBetaSnapshot $snapshot): array
    {
        $rows = is_array($snapshot->rows) ? $snapshot->rows : [];
        $completed = $this->completedSkuSet($snapshot);
        $active = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($row['sku'] ?? '')));
            if ($key === '' || isset($completed[$key])) {
                continue;
            }
            $active[] = $row;
        }

        return $active;
    }
}
