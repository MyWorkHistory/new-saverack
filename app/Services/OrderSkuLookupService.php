<?php

namespace App\Services;

use App\Models\ClientAccount;
use RuntimeException;
use Throwable;

class OrderSkuLookupService
{
    /** @var ShipHeroOrderService */
    private $orders;

    /** @var ShipHeroInventoryService */
    private $inventory;

    public function __construct(ShipHeroOrderService $orders, ShipHeroInventoryService $inventory)
    {
        $this->orders = $orders;
        $this->inventory = $inventory;
    }

    public function normalizeLookupQuery(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }

        return ltrim($s, '#');
    }

    public function resolveShipHeroCustomerAccountId(ClientAccount $account): string
    {
        $sid = $account->shiphero_customer_account_id;
        if (! is_string($sid) || trim($sid) === '') {
            throw new RuntimeException('This client account has no ShipHero customer account ID.');
        }

        return trim($sid);
    }

    /**
     * @return array<string, mixed>|null  order payload, multiple flag, or null
     */
    public function lookup(int $clientAccountId, string $customerId, string $query): ?array
    {
        $normalized = $this->normalizeLookupQuery($query);
        if ($normalized === '') {
            return null;
        }

        $orderMatch = $this->findExactOrder($customerId, $clientAccountId, $normalized);
        if ($orderMatch !== null) {
            return $orderMatch;
        }

        return $this->findExactSku($customerId, $normalized);
    }

    private function normalizeOrderNumber(string $raw): string
    {
        return strtolower(ltrim(trim($raw), '#'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function orderRowMatchesLookupNumber(array $row, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        $fields = [
            (string) ($row['order_number'] ?? ''),
            (string) ($row['partner_order_id'] ?? ''),
        ];
        if (isset($row['legacy_id']) && $row['legacy_id'] !== null && $row['legacy_id'] !== '') {
            $fields[] = (string) $row['legacy_id'];
        }

        foreach ($fields as $raw) {
            $normalized = $this->normalizeOrderNumber($raw);
            if ($normalized !== '' && $normalized === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null  May include key "multiple" => true for ambiguous matches.
     */
    private function findExactOrder(string $customerId, int $clientAccountId, string $query): ?array
    {
        try {
            $payload = $this->orders->listOrders([
                'customer_account_id' => $customerId,
                'tab' => 'manage',
                'order_number' => $query,
                'first' => 25,
            ]);
        } catch (RuntimeException $e) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $needle = $this->normalizeOrderNumber($query);
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $matches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! $this->orderRowMatchesLookupNumber($row, $needle)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $matches[] = $row;
            }
        }

        if (count($matches) === 0 && count($rows) === 1 && is_array($rows[0])) {
            $only = $rows[0];
            if (trim((string) ($only['id'] ?? '')) !== '') {
                $matches[] = $only;
            }
        }

        if (count($matches) === 0) {
            return null;
        }
        if (count($matches) > 1) {
            return ['multiple' => true];
        }

        $row = $matches[0];

        return [
            'type' => 'order',
            'shiphero_order_id' => (string) $row['id'],
            'order_number' => (string) ($row['order_number'] ?? ''),
            'client_account_id' => $clientAccountId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExactSku(string $customerId, string $query): ?array
    {
        try {
            $product = $this->inventory->getProductDetailBySku($query, null, $customerId);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_array($product)) {
            return null;
        }

        $sku = trim((string) ($product['sku'] ?? ''));
        if ($sku === '' || strcasecmp($sku, $query) !== 0) {
            return null;
        }

        return [
            'type' => 'sku',
            'sku' => $sku,
        ];
    }
}
