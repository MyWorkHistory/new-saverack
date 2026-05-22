<?php

namespace App\Services;

use App\Models\ShipHeroOrderDetailCache;
use Illuminate\Support\Carbon;

class ShipHeroOrderDetailCacheService
{
    public const TTL_MINUTES = 30;

    public function normalizeOrderId(string $orderId): string
    {
        return trim($orderId);
    }

    public function clearForClientAccount(int $clientAccountId): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        ShipHeroOrderDetailCache::query()
            ->where('client_account_id', $clientAccountId)
            ->delete();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedOrder(int $clientAccountId, string $orderId): ?array
    {
        $row = $this->findFreshRow($clientAccountId, $orderId);
        if ($row === null || ! is_array($row->order_json)) {
            return null;
        }

        return $row->order_json;
    }

    public function getCachedAtIso(int $clientAccountId, string $orderId): ?string
    {
        $row = $this->findFreshRow($clientAccountId, $orderId);
        if ($row === null || $row->synced_at === null) {
            return null;
        }

        return $row->synced_at->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function putOrder(int $clientAccountId, string $orderId, array $order): void
    {
        if ($clientAccountId <= 0) {
            return;
        }
        $id = $this->normalizeOrderId($orderId);
        if ($id === '') {
            return;
        }
        $row = $this->findOrNewRow($clientAccountId, $id);
        $row->order_json = $order;
        $row->synced_at = now();
        $row->save();
    }

    private function findFreshRow(int $clientAccountId, string $orderId): ?ShipHeroOrderDetailCache
    {
        if ($clientAccountId <= 0) {
            return null;
        }
        $id = $this->normalizeOrderId($orderId);
        if ($id === '') {
            return null;
        }

        $row = ShipHeroOrderDetailCache::query()
            ->where('client_account_id', $clientAccountId)
            ->where('order_id', $id)
            ->first();

        if ($row === null || $row->synced_at === null) {
            return null;
        }

        $cutoff = Carbon::now()->subMinutes(self::TTL_MINUTES);
        if ($row->synced_at->lt($cutoff)) {
            return null;
        }

        return $row;
    }

    private function findOrNewRow(int $clientAccountId, string $orderId): ShipHeroOrderDetailCache
    {
        $existing = ShipHeroOrderDetailCache::query()
            ->where('client_account_id', $clientAccountId)
            ->where('order_id', $orderId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $row = new ShipHeroOrderDetailCache([
            'client_account_id' => $clientAccountId,
            'order_id' => $orderId,
        ]);
        $row->save();

        return $row;
    }
}
