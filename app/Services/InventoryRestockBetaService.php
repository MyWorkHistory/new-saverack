<?php

namespace App\Services;

use App\Jobs\EnrichInventoryRestockSnapshotJob;
use App\Models\InventoryRestockBetaSnapshot;
use App\Models\ShipHeroInventoryProductIndex;
use App\Models\User;
use App\Support\Inventory\RestockBetaCsvParser;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class InventoryRestockBetaService
{
    public const ENRICHMENT_PENDING = 'pending';

    public const ENRICHMENT_RUNNING = 'running';

    public const ENRICHMENT_COMPLETED = 'completed';

    public const ENRICHMENT_FAILED = 'failed';

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

        $rows = $this->parser->parseFile($path);
        $uploadedAt = now();
        $enrichedRows = $this->enrichRowsWithAccounts($rows);

        InventoryRestockBetaSnapshot::query()->delete();

        $snapshot = InventoryRestockBetaSnapshot::query()->create([
            'uploaded_by_user_id' => $actor !== null ? $actor->id : null,
            'original_filename' => $file->getClientOriginalName(),
            'row_count' => count($enrichedRows),
            'rows' => $enrichedRows,
            'completed_skus' => [],
            'enrichment_status' => self::ENRICHMENT_COMPLETED,
            'enrichment_error' => null,
            'uploaded_at' => $uploadedAt,
        ]);

        return $this->toArray($snapshot);
    }

    public function runEnrichmentForSnapshot(int $snapshotId): void
    {
        $snapshot = InventoryRestockBetaSnapshot::query()->find($snapshotId);
        if ($snapshot === null) {
            return;
        }

        if ($snapshot->enrichment_status === self::ENRICHMENT_COMPLETED) {
            return;
        }

        $snapshot->enrichment_status = self::ENRICHMENT_RUNNING;
        $snapshot->enrichment_error = null;
        $snapshot->save();

        try {
            $rows = is_array($snapshot->rows) ? $snapshot->rows : [];
            $snapshot->rows = $this->enrichRowsWithAccounts($rows);
            $snapshot->enrichment_status = self::ENRICHMENT_COMPLETED;
            $snapshot->enrichment_error = null;
            $snapshot->save();
        } catch (\Throwable $e) {
            $snapshot->enrichment_status = self::ENRICHMENT_FAILED;
            $snapshot->enrichment_error = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Restock enrichment failed.';
            $snapshot->save();

            throw $e;
        }
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

        if ($this->snapshotNeedsInlineEnrichment($snapshot)) {
            if ($snapshot->enrichment_status === self::ENRICHMENT_COMPLETED) {
                $snapshot->enrichment_status = self::ENRICHMENT_PENDING;
                $snapshot->enrichment_error = null;
                $snapshot->save();
            }
            try {
                $this->runEnrichmentForSnapshot((int) $snapshot->id);
            } catch (\Throwable $e) {
                // Status and error are persisted by runEnrichmentForSnapshot.
            }
            $snapshot->refresh();
        }

        return $this->toArray($snapshot);
    }

    /**
     * Active restock rows for dashboard preview (no inline enrichment).
     *
     * @return list<array<string, mixed>>
     */
    public function previewActiveRows(int $limit = 5): array
    {
        $snapshot = InventoryRestockBetaSnapshot::query()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return [];
        }

        $active = $this->activeRows($snapshot);
        $slice = array_slice($active, 0, max(1, $limit));

        return array_map(static fn (array $row) => [
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'client_account_id' => isset($row['client_account_id']) ? (int) $row['client_account_id'] : null,
            'restock_needed' => isset($row['restock_needed']) && is_numeric($row['restock_needed'])
                ? (int) $row['restock_needed']
                : null,
            'image_url' => is_string($row['image_url'] ?? null) && $row['image_url'] !== ''
                ? (string) $row['image_url']
                : null,
        ], $slice);
    }

    public function activeRowCount(): int
    {
        $snapshot = InventoryRestockBetaSnapshot::query()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return 0;
        }

        return count($this->activeRows($snapshot));
    }

    private function snapshotNeedsInlineEnrichment(InventoryRestockBetaSnapshot $snapshot): bool
    {
        $status = (string) ($snapshot->enrichment_status ?? '');
        if (in_array($status, [self::ENRICHMENT_PENDING, self::ENRICHMENT_RUNNING, self::ENRICHMENT_FAILED], true)) {
            return true;
        }

        $rows = is_array($snapshot->rows) ? $snapshot->rows : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            if (! array_key_exists('image_url', $row) || ! array_key_exists('warehouse_id', $row)) {
                return true;
            }
        }

        return false;
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

    public function restockQueueConnection(): ?string
    {
        $connection = trim((string) config('services.shiphero.restock_queue_connection', ''));
        if ($connection === '') {
            return null;
        }

        return $connection;
    }

    private function dispatchEnrichment(InventoryRestockBetaSnapshot $snapshot): void
    {
        $mode = strtolower(trim((string) config('services.shiphero.restock_dispatch_mode', 'after_response')));
        if ($mode === 'queue') {
            EnrichInventoryRestockSnapshotJob::dispatch($snapshot->id);

            return;
        }

        if ($mode === 'sync') {
            $this->runEnrichmentForSnapshot((int) $snapshot->id);

            return;
        }

        EnrichInventoryRestockSnapshotJob::dispatchAfterResponse($snapshot->id);
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
            $rows[$index]['image_url'] = $match !== null ? ($match['image_url'] ?? null) : null;
            $rows[$index]['warehouse_id'] = $match !== null ? ($match['warehouse_id'] ?? null) : null;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $skuKeys
     * @return array<string, array{client_account_id: int, account_name: string, image_url: string|null, warehouse_id: string|null}>
     */
    private function lookupAccountsBySku(array $skuKeys): array
    {
        if ($skuKeys === []) {
            return [];
        }

        $matches = ShipHeroInventoryProductIndex::query()
            ->join('client_accounts', 'client_accounts.id', '=', 'shiphero_inventory_product_index.client_account_id')
            ->whereIn('shiphero_inventory_product_index.sku_search', $skuKeys)
            ->orderByDesc('shiphero_inventory_product_index.synced_at')
            ->orderBy('client_accounts.company_name')
            ->get([
                'shiphero_inventory_product_index.sku_search',
                'shiphero_inventory_product_index.client_account_id',
                'shiphero_inventory_product_index.image_url',
                'shiphero_inventory_product_index.warehouse_id',
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
                'image_url' => is_string($match->image_url) && $match->image_url !== ''
                    ? $match->image_url
                    : null,
                'warehouse_id' => is_string($match->warehouse_id) && $match->warehouse_id !== ''
                    ? $match->warehouse_id
                    : null,
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
            'enrichment_status' => (string) ($snapshot->enrichment_status ?? self::ENRICHMENT_COMPLETED),
            'enrichment_error' => $snapshot->enrichment_error,
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
