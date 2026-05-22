<?php

namespace App\Services;

use App\Models\ShipHeroOrderDetailCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ShipHeroOrderDetailCacheService
{
    public const TTL_MINUTES = 30;

    /** @var bool|null */
    private static $tableAvailable;

    public function normalizeOrderId(string $orderId): string
    {
        return trim($orderId);
    }

    public function clearForClientAccount(int $clientAccountId): void
    {
        if (! $this->tableAvailable()) {
            return;
        }
        try {
            ShipHeroOrderDetailCache::query()
                ->where('client_account_id', $clientAccountId)
                ->delete();
        } catch (Throwable $e) {
            $this->logCacheFailure('clear', $e);
        }
    }

    /**
     * @return array{order: array<string, mixed>, cached_at: string|null}|null
     */
    public function getCachedOrderWithMeta(int $clientAccountId, string $orderId): ?array
    {
        if (! $this->tableAvailable()) {
            return null;
        }
        try {
            $row = $this->findFreshRow($clientAccountId, $orderId);
            if ($row === null || ! is_array($row->order_json)) {
                return null;
            }

            return [
                'order' => $row->order_json,
                'cached_at' => $row->synced_at !== null
                    ? $row->synced_at->toIso8601String()
                    : null,
            ];
        } catch (Throwable $e) {
            $this->logCacheFailure('read', $e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedOrder(int $clientAccountId, string $orderId): ?array
    {
        $payload = $this->getCachedOrderWithMeta($clientAccountId, $orderId);

        return $payload !== null ? $payload['order'] : null;
    }

    public function getCachedAtIso(int $clientAccountId, string $orderId): ?string
    {
        $payload = $this->getCachedOrderWithMeta($clientAccountId, $orderId);

        return $payload !== null ? $payload['cached_at'] : null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function putOrder(int $clientAccountId, string $orderId, array $order): void
    {
        if (! $this->tableAvailable()) {
            return;
        }
        try {
            $id = $this->normalizeOrderId($orderId);
            if ($id === '') {
                return;
            }
            $row = $this->findOrNewRow($clientAccountId, $id);
            $row->order_json = $order;
            $row->synced_at = now();
            $row->save();
        } catch (Throwable $e) {
            $this->logCacheFailure('write', $e);
        }
    }

    private function tableAvailable(): bool
    {
        if (self::$tableAvailable !== null) {
            return self::$tableAvailable;
        }
        try {
            self::$tableAvailable = Schema::hasTable('shiphero_order_detail_cache');
        } catch (Throwable $e) {
            self::$tableAvailable = false;
            $this->logCacheFailure('schema', $e);
        }

        return self::$tableAvailable;
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

    private function logCacheFailure(string $operation, Throwable $e): void
    {
        Log::warning('shiphero.order_detail.cache.'.$operation.'_failed', [
            'message' => $e->getMessage(),
        ]);
    }
}
