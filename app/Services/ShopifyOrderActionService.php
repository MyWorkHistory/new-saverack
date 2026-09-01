<?php

namespace App\Services;

use App\Models\ShopifyOrder;
use App\Models\User;
use App\Support\ShopifyGid;
use RuntimeException;

class ShopifyOrderActionService
{
    /** @var ShopifyClient */
    private $client;

    /** @var ShopifyOrderSyncService */
    private $sync;

    /** @var ShopifyFulfillmentService */
    private $fulfillments;

    public function __construct(
        ShopifyClient $client,
        ShopifyOrderSyncService $sync,
        ShopifyFulfillmentService $fulfillments
    ) {
        $this->client = $client;
        $this->sync = $sync;
        $this->fulfillments = $fulfillments;
    }

    public function syncOrder(ShopifyOrder $order): ShopifyOrder
    {
        $connection = $order->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }

        $refreshed = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
        if ($refreshed === null) {
            throw new RuntimeException('Could not sync order from Shopify.');
        }

        return $refreshed->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
    }

    /**
     * @param  list<string>  $reasons
     */
    public function holdOrder(ShopifyOrder $order, array $reasons): ShopifyOrder
    {
        $reasons = array_values(array_filter(array_map('trim', $reasons)));
        if ($reasons === []) {
            throw new RuntimeException('Select at least one hold reason.');
        }

        $invalid = array_diff($reasons, ShopifyOrderListService::HOLD_REASONS);
        if ($invalid !== []) {
            throw new RuntimeException('Invalid hold reason: '.implode(', ', $invalid));
        }

        $connection = $order->connection;
        if ($connection !== null && $connection->hasCredentials()) {
            $this->pushHoldTags($connection, $order, $reasons);
        }

        $order->crm_hold_reasons = $reasons;
        $order->save();

        return $order->fresh(['connection.clientAccount', 'lineItems']);
    }

    public function cancelOrder(ShopifyOrder $order): ShopifyOrder
    {
        $connection = $order->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }

        $gid = ShopifyGid::of('Order', (string) $order->shopify_order_id);
        $api = $this->client->forConnection($connection);
        $data = $api->graphql(
            <<<'GQL'
mutation orderCancel($orderId: ID!, $reason: OrderCancelReason!, $notifyCustomer: Boolean, $refund: Boolean, $restock: Boolean) {
  orderCancel(orderId: $orderId, reason: $reason, notifyCustomer: $notifyCustomer, refund: $refund, restock: $restock) {
    order { id cancelledAt }
    userErrors { field message }
  }
}
GQL
            ,
            [
                'orderId' => $gid,
                'reason' => 'OTHER',
                'notifyCustomer' => false,
                'refund' => false,
                'restock' => true,
            ]
        );

        $payload = is_array($data['orderCancel'] ?? null) ? $data['orderCancel'] : [];
        $errors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($errors !== []) {
            throw new RuntimeException((string) ($errors[0]['message'] ?? 'Order cancel failed.'));
        }

        $refreshed = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
        if ($refreshed === null) {
            throw new RuntimeException('Order cancelled in Shopify but local sync failed.');
        }

        return $refreshed->fresh(['connection.clientAccount', 'lineItems']);
    }

    /**
     * @return array{fulfillment:\App\Models\ShopifyFulfillment, order:ShopifyOrder}
     */
    public function fulfillAllRemaining(ShopifyOrder $order, ?User $actor = null): array
    {
        $order->loadMissing('fulfillmentOrders.lineItems');

        $items = [];
        foreach ($order->fulfillmentOrders as $fo) {
            foreach ($fo->lineItems as $line) {
                $remaining = (int) $line->remaining_quantity;
                if ($remaining <= 0) {
                    continue;
                }
                $items[] = [
                    'fo_line_item_id' => (string) $line->shopify_fo_line_item_id,
                    'quantity' => $remaining,
                ];
            }
        }

        if ($items === []) {
            throw new RuntimeException('No fulfillable quantities remain on this order.');
        }

        return $this->fulfillments->markShipped($order, $items, 'UPS', 'TEST123456789', $actor);
    }

    /**
     * @param  list<string>  $reasons
     */
    private function pushHoldTags($connection, ShopifyOrder $order, array $reasons): void
    {
        $existingTags = [];
        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        if (isset($raw['tags']) && is_array($raw['tags'])) {
            $existingTags = $raw['tags'];
        } elseif (isset($raw['tags']) && is_string($raw['tags'])) {
            $existingTags = array_map('trim', explode(',', $raw['tags']));
        }

        $holdTags = array_map(fn (string $r) => 'crm-hold:'.$r, $reasons);
        $merged = array_values(array_unique(array_filter(array_merge(
            $existingTags,
            $holdTags
        ))));

        $gid = ShopifyGid::of('Order', (string) $order->shopify_order_id);
        $api = $this->client->forConnection($connection);
        $data = $api->graphql(
            <<<'GQL'
mutation orderUpdate($input: OrderInput!) {
  orderUpdate(input: $input) {
    order { id tags }
    userErrors { field message }
  }
}
GQL
            ,
            [
                'input' => [
                    'id' => $gid,
                    'tags' => $merged,
                ],
            ]
        );

        $payload = is_array($data['orderUpdate'] ?? null) ? $data['orderUpdate'] : [];
        $errors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($errors !== []) {
            throw new RuntimeException((string) ($errors[0]['message'] ?? 'Could not update order tags.'));
        }
    }
}
