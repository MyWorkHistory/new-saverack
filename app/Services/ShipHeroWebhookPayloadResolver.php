<?php

namespace App\Services;

use App\Models\ClientAccount;

class ShipHeroWebhookPayloadResolver
{
    /** @var list<string> */
    private const ORDER_ID_KEYS = [
        'order_uuid',
        'order_id',
        'order_legacy_id',
        'id',
    ];

    /** @var list<string> */
    private const CUSTOMER_ID_KEYS = [
        'customer_account_id',
        'account_id',
        'account_uuid',
    ];

    /** @var ShipHeroOrderService */
    private $orders;

    public function __construct(ShipHeroOrderService $orders)
    {
        $this->orders = $orders;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventType(array $payload): string
    {
        $type = trim((string) ($payload['webhook_type'] ?? $payload['event_type'] ?? $payload['type'] ?? ''));

        return $type !== '' ? $type : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractOrderId(array $payload): string
    {
        foreach (self::ORDER_ID_KEYS as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $nested = $payload['order'] ?? null;
        if (is_array($nested)) {
            foreach (self::ORDER_ID_KEYS as $key) {
                $value = trim((string) ($nested[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function extractSkus(array $payload): array
    {
        $skus = [];

        $inventory = $payload['inventory'] ?? null;
        if (is_array($inventory)) {
            foreach ($inventory as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sku = trim((string) ($row['sku'] ?? ''));
                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }
        }

        $topSku = trim((string) ($payload['sku'] ?? ''));
        if ($topSku !== '') {
            $skus[] = $topSku;
        }

        return array_values(array_unique($skus));
    }

    public function isInventoryWebhookType(string $eventType): bool
    {
        return in_array(trim($eventType), ShipHeroWebhookRegistrationService::INVENTORY_WEBHOOK_NAMES, true);
    }

    public function isOrderWebhookType(string $eventType): bool
    {
        return in_array(trim($eventType), ShipHeroWebhookRegistrationService::ORDER_WEBHOOK_NAMES, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveClientAccount(array $payload): ?ClientAccount
    {
        foreach ($this->customerIdCandidates($payload) as $customerId) {
            $account = $this->findAccountByShipHeroCustomerId($customerId);
            if ($account !== null) {
                return $account;
            }
        }

        $orderId = $this->extractOrderId($payload);
        if ($orderId === '') {
            return null;
        }

        $accounts = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('id')
            ->get(['id', 'shiphero_customer_account_id']);

        foreach ($accounts as $account) {
            $customerId = trim((string) $account->shiphero_customer_account_id);
            if ($customerId === '') {
                continue;
            }
            if ($this->orders->orderExistsForCustomer($orderId, $customerId)) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function customerIdCandidates(array $payload): array
    {
        $candidates = [];
        foreach (self::CUSTOMER_ID_KEYS as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        $nested = $payload['order'] ?? null;
        if (is_array($nested)) {
            foreach (self::CUSTOMER_ID_KEYS as $key) {
                $value = trim((string) ($nested[$key] ?? ''));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    private function findAccountByShipHeroCustomerId(string $customerId): ?ClientAccount
    {
        return ClientAccount::resolveByShipHeroCustomerId($customerId);
    }
}
