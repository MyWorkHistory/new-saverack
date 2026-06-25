<?php

namespace App\Services;

use App\Models\ShipHeroInventoryProductDetailCache;
use Illuminate\Support\Carbon;

class InventoryProductDetailCacheService
{
    public const CACHE_TTL_MINUTES = 30;

    public function normalizeSku(string $sku): string
    {
        return mb_strtolower(trim($sku));
    }

    public function clearForClientAccount(int $clientAccountId): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        ShipHeroInventoryProductDetailCache::query()
            ->where('client_account_id', $clientAccountId)
            ->delete();
    }

    public function clearForSku(int $clientAccountId, string $sku): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        $normalized = $this->normalizeSku($sku);
        if ($normalized === '') {
            return;
        }
        ShipHeroInventoryProductDetailCache::query()
            ->where('client_account_id', $clientAccountId)
            ->where('sku_search', $normalized)
            ->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedProduct(int $clientAccountId, string $sku): ?array
    {
        $row = $this->findRow($clientAccountId, $sku);
        if ($row === null || ! is_array($row->product_json)) {
            return null;
        }
        if (! $this->isFresh($this->productSyncedAt($row))) {
            return null;
        }

        return $row->product_json;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getCachedParentKits(int $clientAccountId, string $sku): ?array
    {
        $row = $this->findRow($clientAccountId, $sku);
        if ($row === null || ! is_array($row->parent_kits_json)) {
            return null;
        }
        if (! $this->isFresh($row->parent_kits_synced_at)) {
            return null;
        }

        return $row->parent_kits_json;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getCachedKitComponents(int $clientAccountId, string $sku): ?array
    {
        $row = $this->findRow($clientAccountId, $sku);
        if ($row === null || ! is_array($row->kit_components_json)) {
            return null;
        }
        if (! $this->isFresh($row->kit_components_synced_at)) {
            return null;
        }

        return $row->kit_components_json;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, truncated: bool, message: ?string}|null
     */
    public function getCachedOrders(int $clientAccountId, string $sku, string $mode): ?array
    {
        $row = $this->findRow($clientAccountId, $sku);
        if ($row === null) {
            return null;
        }
        $syncedAt = $mode === 'backorder' ? $row->backorder_orders_synced_at : $row->allocated_orders_synced_at;
        if (! $this->isFresh($syncedAt)) {
            return null;
        }
        $json = $mode === 'backorder' ? $row->backorder_orders_json : $row->allocated_orders_json;
        if (! is_array($json)) {
            return null;
        }

        return [
            'rows' => is_array($json['rows'] ?? null) ? $json['rows'] : [],
            'truncated' => (bool) ($json['truncated'] ?? false),
            'message' => isset($json['message']) && is_string($json['message']) ? $json['message'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public function putProduct(int $clientAccountId, string $sku, array $product): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        $skuTrim = trim($sku);
        if ($skuTrim === '') {
            return;
        }
        $row = $this->findOrNewRow($clientAccountId, $skuTrim);
        $row->product_json = $product;
        $now = now();
        $row->product_synced_at = $now;
        $row->synced_at = $now;
        $row->save();
    }

    /**
     * @param  list<array<string, mixed>>  $kits
     */
    public function putParentKits(int $clientAccountId, string $sku, array $kits): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        $skuTrim = trim($sku);
        if ($skuTrim === '') {
            return;
        }
        $row = $this->findOrNewRow($clientAccountId, $skuTrim);
        $row->parent_kits_json = $kits;
        $row->parent_kits_synced_at = now();
        $row->save();
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function putKitComponents(int $clientAccountId, string $sku, array $components): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        $skuTrim = trim($sku);
        if ($skuTrim === '') {
            return;
        }
        $row = $this->findOrNewRow($clientAccountId, $skuTrim);
        $row->kit_components_json = $components;
        $row->kit_components_synced_at = now();
        $row->save();
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, truncated: bool, message: ?string}  $payload
     */
    public function putOrders(int $clientAccountId, string $sku, string $mode, array $payload): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        $skuTrim = trim($sku);
        if ($skuTrim === '') {
            return;
        }
        $row = $this->findOrNewRow($clientAccountId, $skuTrim);
        $stored = [
            'rows' => $payload['rows'] ?? [],
            'truncated' => (bool) ($payload['truncated'] ?? false),
            'message' => $payload['message'] ?? null,
        ];
        $now = now();
        if ($mode === 'backorder') {
            $row->backorder_orders_json = $stored;
            $row->backorder_orders_synced_at = $now;
        } else {
            $row->allocated_orders_json = $stored;
            $row->allocated_orders_synced_at = $now;
        }
        $row->save();
    }

    private function isFresh($syncedAt): bool
    {
        if ($syncedAt === null || $syncedAt === '') {
            return false;
        }
        if (! $syncedAt instanceof Carbon) {
            try {
                $syncedAt = Carbon::parse($syncedAt);
            } catch (\Throwable $e) {
                return false;
            }
        }

        return $syncedAt->gte(now()->subMinutes(self::CACHE_TTL_MINUTES));
    }

    /**
     * @return Carbon|null
     */
    private function productSyncedAt(ShipHeroInventoryProductDetailCache $row)
    {
        return $row->product_synced_at ?? $row->synced_at;
    }

    private function findRow(int $clientAccountId, string $sku): ?ShipHeroInventoryProductDetailCache
    {
        if ($clientAccountId <= 0) {
            return null;
        }
        $skuTrim = trim($sku);
        if ($skuTrim === '') {
            return null;
        }

        return ShipHeroInventoryProductDetailCache::query()
            ->where('client_account_id', $clientAccountId)
            ->where('sku_search', $this->normalizeSku($skuTrim))
            ->first();
    }

    private function findOrNewRow(int $clientAccountId, string $sku): ShipHeroInventoryProductDetailCache
    {
        $existing = $this->findRow($clientAccountId, $sku);
        if ($existing !== null) {
            return $existing;
        }

        $row = new ShipHeroInventoryProductDetailCache([
            'client_account_id' => $clientAccountId,
            'sku' => $sku,
            'sku_search' => $this->normalizeSku($sku),
        ]);
        $row->save();

        return $row;
    }
}
