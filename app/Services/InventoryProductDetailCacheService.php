<?php

namespace App\Services;

use App\Models\ShipHeroInventoryProductDetailCache;

class InventoryProductDetailCacheService
{
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

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedProduct(int $clientAccountId, string $sku): ?array
    {
        $row = $this->findRow($clientAccountId, $sku);
        if ($row === null || ! is_array($row->product_json)) {
            return null;
        }

        return $row->product_json;
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
        $row->synced_at = now();
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
        if ($mode === 'backorder') {
            $row->backorder_orders_json = $stored;
        } else {
            $row->allocated_orders_json = $stored;
        }
        $row->synced_at = now();
        $row->save();
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
