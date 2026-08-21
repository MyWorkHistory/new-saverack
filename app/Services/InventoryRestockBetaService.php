<?php

namespace App\Services;

use App\Jobs\EnrichInventoryRestockSnapshotJob;
use App\Jobs\TransferInventoryLocationJob;
use App\Models\InventoryRestockBetaSnapshot;
use App\Models\ShipHeroInventoryProductIndex;
use App\Models\User;
use App\Support\Inventory\RestockBetaCsvParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class InventoryRestockBetaService
{
    public const ENRICHMENT_PENDING = 'pending';

    public const ENRICHMENT_RUNNING = 'running';

    public const ENRICHMENT_COMPLETED = 'completed';

    public const ENRICHMENT_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_TRANSFER_CART = 'transfer_cart';

    public const STATUS_COMPLETE = 'complete';

    /** @var list<string> */
    public const ROW_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_TRANSFER_CART,
        self::STATUS_COMPLETE,
    ];

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
        $skuStatuses = $this->defaultPendingStatuses($enrichedRows);

        InventoryRestockBetaSnapshot::query()->delete();

        $snapshot = InventoryRestockBetaSnapshot::query()->create([
            'uploaded_by_user_id' => $actor !== null ? $actor->id : null,
            'original_filename' => $file->getClientOriginalName(),
            'row_count' => count($enrichedRows),
            'rows' => $enrichedRows,
            'completed_skus' => [],
            'sku_statuses' => $skuStatuses,
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
     * Open restock rows for dashboard preview (pending + transfer cart only).
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

        $open = $this->openWorkRows($snapshot);
        $slice = array_slice($open, 0, max(1, $limit));

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
            'status' => (string) ($row['status'] ?? self::STATUS_PENDING),
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

        return count($this->openWorkRows($snapshot));
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
     * Mark SKU complete (Remove action / legacy complete endpoint).
     *
     * @return array<string, mixed>
     */
    public function completeSku(string $sku): array
    {
        return $this->setSkuStatus($sku, self::STATUS_COMPLETE);
    }

    /**
     * @return array<string, mixed>
     */
    public function setSkuStatus(string $sku, string $status): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('SKU is required.');
        }

        $status = strtolower(trim($status));
        if (! in_array($status, self::ROW_STATUSES, true)) {
            throw new RuntimeException('Invalid restock status.');
        }

        $snapshot = $this->latestSnapshotOrFail();

        $statuses = $this->normalizedSkuStatuses($snapshot);
        $key = mb_strtolower($sku);
        $statuses[$key] = $status;
        $snapshot->sku_statuses = $statuses;

        // Keep legacy completed_skus in sync for older readers.
        $completed = [];
        foreach ($statuses as $skuKey => $skuStatus) {
            if ($skuStatus === self::STATUS_COMPLETE) {
                $completed[] = $skuKey;
            }
        }
        $snapshot->completed_skus = $completed;
        $snapshot->save();

        return $this->toArray($snapshot);
    }

    /**
     * After a restock transfer, update CSV snapshot location labels/qtys so the
     * Restocks table reflects source + destination (not only status).
     *
     * @param  array{
     *   from_location_name?: string|null,
     *   to_location_name?: string|null,
     *   quantity?: int,
     *   source_kind?: string|null,
     *   destination_kind?: string|null,
     *   next_status?: string|null,
     *   from_qty_before?: int|null,
     *   to_qty_before?: int|null
     * }  $transfer
     * @return array<string, mixed>|null Snapshot payload, or null when no snapshot/row.
     */
    public function applyTransferToSku(string $sku, array $transfer): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $snapshot = InventoryRestockBetaSnapshot::query()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();
        if ($snapshot === null) {
            return null;
        }

        $qty = max(0, (int) ($transfer['quantity'] ?? 0));
        $fromName = trim((string) ($transfer['from_location_name'] ?? ''));
        $toName = trim((string) ($transfer['to_location_name'] ?? ''));
        $sourceKind = strtolower(trim((string) ($transfer['source_kind'] ?? 'backstock')));
        $destinationKind = strtolower(trim((string) ($transfer['destination_kind'] ?? 'pick')));
        $nextStatus = isset($transfer['next_status']) ? trim((string) $transfer['next_status']) : '';
        $fromQtyBefore = array_key_exists('from_qty_before', $transfer) && $transfer['from_qty_before'] !== null
            ? max(0, (int) $transfer['from_qty_before'])
            : null;
        $toQtyBefore = array_key_exists('to_qty_before', $transfer) && $transfer['to_qty_before'] !== null
            ? max(0, (int) $transfer['to_qty_before'])
            : null;

        $rows = is_array($snapshot->rows) ? $snapshot->rows : [];
        $key = mb_strtolower($sku);
        $found = false;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (mb_strtolower(trim((string) ($row['sku'] ?? ''))) !== $key) {
                continue;
            }
            $found = true;

            if ($qty > 0 && $fromName !== '' && in_array($sourceKind, ['backstock', 'non_pickable'], true)) {
                $adjusted = $this->adjustLocationListQuantity(
                    (string) ($row['backstock_locations'] ?? ''),
                    $fromName,
                    -$qty,
                    $fromQtyBefore
                );
                $rows[$index]['backstock_locations'] = $adjusted['text'];
                $rows[$index]['backstock_qty'] = max(
                    0,
                    (int) ($row['backstock_qty'] ?? 0) - $qty
                );
            }

            if ($qty > 0 && $toName !== '' && in_array($destinationKind, ['pick', 'pickable'], true)) {
                $adjusted = $this->adjustLocationListQuantity(
                    (string) ($row['pick_location'] ?? ''),
                    $toName,
                    $qty,
                    $toQtyBefore
                );
                $rows[$index]['pick_location'] = $adjusted['text'];
                $rows[$index]['pickable_qty'] = max(
                    0,
                    (int) ($row['pickable_qty'] ?? 0) + $qty
                );
            }

            break;
        }

        if (! $found) {
            return null;
        }

        $snapshot->rows = $rows;

        if ($nextStatus !== '' && in_array(strtolower($nextStatus), self::ROW_STATUSES, true)) {
            $statuses = $this->normalizedSkuStatuses($snapshot);
            $statuses[$key] = strtolower($nextStatus);
            $snapshot->sku_statuses = $statuses;
            $completed = [];
            foreach ($statuses as $skuKey => $skuStatus) {
                if ($skuStatus === self::STATUS_COMPLETE) {
                    $completed[] = $skuKey;
                }
            }
            $snapshot->completed_skus = $completed;
        }

        $snapshot->save();

        return $this->toArray($snapshot);
    }

    /**
     * @return array{text: string, matched: bool, quantity: int}
     */
    public function adjustLocationListQuantity(
        string $list,
        string $locationName,
        int $delta,
        ?int $knownQtyBefore = null
    ): array {
        $locationName = trim($locationName);
        if ($locationName === '' || $delta === 0) {
            return ['text' => trim($list), 'matched' => false, 'quantity' => 0];
        }

        $parts = preg_split('/\s*[,;|]\s*|\n+/', $list) ?: [];
        $parts = array_values(array_filter(array_map(
            static fn ($part): string => trim((string) $part),
            $parts
        ), static fn (string $part): bool => $part !== '' && $part !== '—'));

        $matched = false;
        $resultQty = 0;
        $out = [];
        $needle = mb_strtolower($locationName);

        foreach ($parts as $part) {
            $parsed = $this->parseLocationLabel($part);
            $nameKey = mb_strtolower($parsed['name']);
            $hasExplicitQty = preg_match('/\(\s*QTY\s*:/i', $part) === 1;
            if (! $matched && $nameKey !== '' && (
                $nameKey === $needle
                || str_contains($nameKey, $needle)
                || str_contains($needle, $nameKey)
            )) {
                $matched = true;
                $baseQty = $parsed['quantity'];
                if (! $hasExplicitQty && $knownQtyBefore !== null) {
                    $baseQty = max(0, $knownQtyBefore);
                }
                $nextQty = max(0, $baseQty + $delta);
                $resultQty = $nextQty;
                if ($nextQty > 0) {
                    $out[] = $this->formatLocationLabel($parsed['name'], $nextQty);
                }
                continue;
            }
            $out[] = $hasExplicitQty || $parsed['quantity'] > 0
                ? $this->formatLocationLabel($parsed['name'], $parsed['quantity'])
                : $parsed['name'];
        }

        if (! $matched && $delta > 0) {
            $base = $knownQtyBefore !== null ? max(0, $knownQtyBefore) : 0;
            $resultQty = $base + $delta;
            $out[] = $this->formatLocationLabel($locationName, $resultQty);
            $matched = true;
        }

        return [
            'text' => implode(', ', $out),
            'matched' => $matched,
            'quantity' => $resultQty,
        ];
    }

    /**
     * @return array{name: string, quantity: int}
     */
    private function parseLocationLabel(string $label): array
    {
        $raw = trim($label);
        if ($raw === '') {
            return ['name' => '', 'quantity' => 0];
        }
        if (preg_match('/^(.+?)\s*\(\s*QTY\s*:\s*([-\d,]+)\s*\)\s*$/i', $raw, $m) === 1) {
            $qtyRaw = str_replace(',', '', (string) ($m[2] ?? '0'));

            return [
                'name' => trim((string) ($m[1] ?? '')),
                'quantity' => max(0, (int) $qtyRaw),
            ];
        }

        return ['name' => $raw, 'quantity' => 0];
    }

    private function formatLocationLabel(string $name, int $quantity): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = '—';
        }

        return $name.' (QTY: '.number_format(max(0, $quantity)).')';
    }

    private function latestSnapshotOrFail(): InventoryRestockBetaSnapshot
    {
        $snapshot = InventoryRestockBetaSnapshot::query()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            throw new RuntimeException('No restock snapshot to update.');
        }

        return $snapshot;
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
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function defaultPendingStatuses(array $rows): array
    {
        $statuses = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($row['sku'] ?? '')));
            if ($key !== '') {
                $statuses[$key] = self::STATUS_PENDING;
            }
        }

        return $statuses;
    }

    /**
     * @return array<string, string>
     */
    private function normalizedSkuStatuses(InventoryRestockBetaSnapshot $snapshot): array
    {
        $raw = is_array($snapshot->sku_statuses) ? $snapshot->sku_statuses : [];
        $statuses = [];
        foreach ($raw as $sku => $status) {
            $key = mb_strtolower(trim((string) $sku));
            $normalized = strtolower(trim((string) $status));
            if ($key === '' || ! in_array($normalized, self::ROW_STATUSES, true)) {
                continue;
            }
            $statuses[$key] = $normalized;
        }

        // Legacy completed_skus → complete when sku_statuses missing entries.
        $completed = is_array($snapshot->completed_skus) ? $snapshot->completed_skus : [];
        foreach ($completed as $sku) {
            $key = mb_strtolower(trim((string) $sku));
            if ($key !== '' && ! isset($statuses[$key])) {
                $statuses[$key] = self::STATUS_COMPLETE;
            }
        }

        return $statuses;
    }

    public static function statusLabel(string $status): string
    {
        if ($status === self::STATUS_TRANSFER_CART) {
            return 'Transfer';
        }
        if ($status === self::STATUS_COMPLETE) {
            return 'Complete';
        }

        return 'Pending';
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(InventoryRestockBetaSnapshot $snapshot): array
    {
        $uploadedAt = $snapshot->uploaded_at;
        $allRows = $this->rowsWithStatus($snapshot);
        $openRows = array_values(array_filter(
            $allRows,
            static fn (array $row): bool => ($row['status'] ?? self::STATUS_PENDING) !== self::STATUS_COMPLETE
        ));
        $restockNeededTotal = 0;
        foreach ($openRows as $row) {
            if (isset($row['restock_needed']) && is_numeric($row['restock_needed'])) {
                $restockNeededTotal += (int) $row['restock_needed'];
            }
        }

        return [
            'original_filename' => $snapshot->original_filename,
            'row_count' => (int) $snapshot->row_count,
            'active_row_count' => count($openRows),
            'restock_needed_total' => $restockNeededTotal,
            'uploaded_at' => $uploadedAt !== null ? $uploadedAt->toIso8601String() : null,
            'enrichment_status' => (string) ($snapshot->enrichment_status ?? self::ENRICHMENT_COMPLETED),
            'enrichment_error' => $snapshot->enrichment_error,
            'rows' => $allRows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsWithStatus(InventoryRestockBetaSnapshot $snapshot): array
    {
        $rows = is_array($snapshot->rows) ? $snapshot->rows : [];
        $statuses = $this->normalizedSkuStatuses($snapshot);
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($row['sku'] ?? '')));
            if ($key === '') {
                continue;
            }
            $status = $statuses[$key] ?? self::STATUS_PENDING;
            $row['status'] = $status;
            $row['status_label'] = self::statusLabel($status);
            $transferError = Cache::pull(TransferInventoryLocationJob::RESTOCK_ERROR_CACHE_PREFIX.$key);
            if (is_string($transferError) && trim($transferError) !== '') {
                $row['transfer_error'] = trim($transferError);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Pending + transfer cart rows (excludes complete) for home / open-work counts.
     *
     * @return list<array<string, mixed>>
     */
    private function openWorkRows(InventoryRestockBetaSnapshot $snapshot): array
    {
        return array_values(array_filter(
            $this->rowsWithStatus($snapshot),
            static fn (array $row): bool => ($row['status'] ?? self::STATUS_PENDING) !== self::STATUS_COMPLETE
        ));
    }
}

