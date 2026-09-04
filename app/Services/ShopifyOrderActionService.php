<?php

namespace App\Services;

use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderLineItem;
use App\Models\ShopifyProductVariant;
use App\Models\User;
use App\Support\ShopifyGid;
use RuntimeException;

class ShopifyOrderActionService
{
    public const SHIPPED_STATUS_LOCK_MESSAGE = 'Cannot change fulfilled order status.';

    public const CANCELLED_STATUS_LOCK_MESSAGE = 'Cannot change cancelled order status.';

    /** @var ShopifyClient */
    private $client;

    /** @var ShopifyOrderSyncService */
    private $sync;

    /** @var ShopifyFulfillmentService */
    private $fulfillments;

    /** @var ShopifyOrderListService */
    private $list;

    /** @var ShopifyProductSyncService */
    private $products;

    public function __construct(
        ShopifyClient $client,
        ShopifyOrderSyncService $sync,
        ShopifyFulfillmentService $fulfillments,
        ShopifyOrderListService $list,
        ShopifyProductSyncService $products
    ) {
        $this->client = $client;
        $this->sync = $sync;
        $this->fulfillments = $fulfillments;
        $this->list = $list;
        $this->products = $products;
    }

    public function assertNotShipped(ShopifyOrder $order): void
    {
        if ($this->list->isFulfilled($order)) {
            throw new RuntimeException(self::SHIPPED_STATUS_LOCK_MESSAGE);
        }
    }

    public function syncOrder(ShopifyOrder $order): ShopifyOrder
    {
        $connection = $order->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }

        $refreshed = null;
        try {
            $refreshed = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
        } catch (\Throwable $e) {
            report($e);
        }

        $target = $refreshed ?? $order;
        $foCount = 0;
        try {
            $foCount = $this->sync->syncFulfillmentOrdersFromRestApi($connection, $target);
        } catch (\Throwable $e) {
            report($e);
        }

        $fresh = $target->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
        if ($fresh === null) {
            throw new RuntimeException('Could not sync order from Shopify.');
        }

        if ($refreshed === null && $foCount === 0 && $fresh->fulfillmentOrders->isEmpty()) {
            throw new RuntimeException(
                'Could not sync order from Shopify. Check the store connection token and that the order still exists in Shopify Admin.'
            );
        }

        return $fresh;
    }

    /**
     * @param  list<string>  $reasons
     */
    public function holdOrder(ShopifyOrder $order, array $reasons): ShopifyOrder
    {
        $this->assertNotShipped($order);
        if ($order->cancelled_at !== null) {
            throw new RuntimeException(self::CANCELLED_STATUS_LOCK_MESSAGE);
        }

        $reasons = array_values(array_filter(array_map('trim', $reasons)));
        if ($reasons === []) {
            throw new RuntimeException('Select at least one hold reason.');
        }

        $invalid = array_diff($reasons, ShopifyOrderListService::HOLD_REASONS);
        if ($invalid !== []) {
            throw new RuntimeException('Invalid hold reason: '.implode(', ', $invalid));
        }

        // Persist CRM hold first — Shopify tag sync must not block the CRM action.
        // Clearing CRM-only cancel lets status recover from Cancelled → On Hold.
        $order->crm_hold_reasons = $reasons;
        $order->crm_fulfillment_cancelled_at = null;
        $order->save();

        $order->loadMissing('connection');
        $connection = $order->connection;
        if ($connection !== null && $connection->hasCredentials()) {
            try {
                $this->pushHoldTags($connection, $order, $reasons);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $order->fresh(['connection.clientAccount', 'lineItems']);
    }

    public function cancelOrder(ShopifyOrder $order, bool $cancelInShopify = false): ShopifyOrder
    {
        $this->assertNotShipped($order);

        if ($cancelInShopify) {
            $connection = $order->connection;
            if ($connection === null || ! $connection->hasCredentials()) {
                throw new RuntimeException('Shopify connection credentials missing.');
            }

            $gid = ShopifyGid::of('Order', (string) $order->shopify_order_id);
            $api = $this->client->forConnection($connection);
            $data = $api->graphql(
                <<<'GQL'
mutation orderCancel($orderId: ID!, $reason: OrderCancelReason!, $notifyCustomer: Boolean, $refundMethod: OrderCancelRefundMethodInput!, $restock: Boolean!) {
  orderCancel(orderId: $orderId, reason: $reason, notifyCustomer: $notifyCustomer, refundMethod: $refundMethod, restock: $restock) {
    job { id done }
    orderCancelUserErrors { field message code }
    userErrors { field message }
  }
}
GQL
                ,
                [
                    'orderId' => $gid,
                    'reason' => 'OTHER',
                    'notifyCustomer' => false,
                    'refundMethod' => [
                        'originalPaymentMethodsRefund' => false,
                    ],
                    'restock' => true,
                ]
            );

            $payload = is_array($data['orderCancel'] ?? null) ? $data['orderCancel'] : [];
            $cancelErrors = is_array($payload['orderCancelUserErrors'] ?? null) ? $payload['orderCancelUserErrors'] : [];
            if ($cancelErrors === []) {
                $cancelErrors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
            }
            if ($cancelErrors !== []) {
                throw new RuntimeException((string) ($cancelErrors[0]['message'] ?? 'Order cancel failed.'));
            }

            $refreshed = null;
            for ($attempt = 0; $attempt < 4; $attempt++) {
                if ($attempt > 0) {
                    usleep(400000);
                }
                $refreshed = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
                if ($refreshed !== null && $refreshed->cancelled_at !== null) {
                    break;
                }
            }
            if ($refreshed === null) {
                throw new RuntimeException('Order cancelled in Shopify but local sync failed.');
            }

            $refreshed->crm_fulfillment_cancelled_at = now();
            $refreshed->crm_hold_reasons = [];
            $refreshed->save();

            return $refreshed->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
        }

        return $this->cancelFulfillmentInCrm($order);
    }

    /**
     * Cancel 3PL fulfillment for all items in CRM only (does not call Shopify).
     */
    public function cancelFulfillmentInCrm(ShopifyOrder $order): ShopifyOrder
    {
        $this->assertNotShipped($order);

        $order->loadMissing(['lineItems', 'fulfillmentOrders.lineItems']);

        foreach ($order->fulfillmentOrders as $fo) {
            foreach ($fo->lineItems as $line) {
                $line->remaining_quantity = 0;
                $line->save();
            }
        }
        foreach ($order->lineItems as $lineItem) {
            $lineItem->fulfillable_quantity = 0;
            $lineItem->save();
        }

        $order->crm_fulfillment_cancelled_at = now();
        $order->crm_hold_reasons = [];
        $order->save();

        return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
    }

    /**
     * @param  list<int>|null  $deductLineItemIds  Order line item IDs to deduct inventory for (null = all remaining)
     * @return array{fulfillment:\App\Models\ShopifyFulfillment|null, order:ShopifyOrder}
     */
    public function fulfillAllRemaining(
        ShopifyOrder $order,
        ?User $actor = null,
        ?string $trackingNumber = null,
        ?array $deductLineItemIds = null
    ): array {
        $this->assertNotShipped($order);

        if ($this->list->isCancelled($order)) {
            throw new RuntimeException('Cannot fulfill a cancelled order.');
        }

        $connection = $order->connection;
        if ($connection !== null && $connection->hasCredentials()) {
            try {
                $synced = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
                if ($synced !== null) {
                    $order = $synced;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->assertNotShipped($order);
        if ($this->list->isCancelled($order)) {
            throw new RuntimeException('Cannot fulfill a cancelled order.');
        }

        $order->loadMissing(['lineItems', 'fulfillmentOrders.lineItems', 'connection']);

        $items = $this->collectFulfillableFoItems($order);
        if ($items === [] && $connection !== null && $connection->hasCredentials()) {
            try {
                $this->sync->syncFulfillmentOrdersFromRestApi($connection, $order);
            } catch (\Throwable $e) {
                report($e);
            }
            $order->load(['fulfillmentOrders.lineItems']);
            $items = $this->collectFulfillableFoItems($order);
        }

        if ($items === []) {
            throw new RuntimeException(
                'No fulfillable quantities remain on this order. Sync the order from Shopify and try again.'
            );
        }

        $tracking = trim((string) ($trackingNumber ?? ''));
        $result = $this->fulfillments->markShipped(
            $order,
            $items,
            'UPS',
            $tracking,
            $actor
        );

        $orderFresh = $result['order'];
        $orderFresh->crm_fulfillment_cancelled_at = null;
        $orderFresh->crm_hold_reasons = [];
        $orderFresh->save();

        $this->deductInventoryForOrder($orderFresh, $deductLineItemIds);

        return [
            'fulfillment' => $result['fulfillment'],
            'order' => $orderFresh->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']),
        ];
    }

    /**
     * Re-ship selected order line items: keep order #, mark those lines pending, leave Fulfilled.
     *
     * @param  list<int>  $lineItemIds
     */
    public function reshipOrder(ShopifyOrder $order, array $lineItemIds): ShopifyOrder
    {
        if (! $this->list->isFulfilled($order)) {
            throw new RuntimeException('Re-Ship is only available for fulfilled orders.');
        }

        $lineItemIds = array_values(array_unique(array_filter(array_map('intval', $lineItemIds))));
        if ($lineItemIds === []) {
            throw new RuntimeException('Select at least one item to re-ship.');
        }

        $order->loadMissing(['lineItems', 'fulfillmentOrders.lineItems']);
        $selected = $order->lineItems->whereIn('id', $lineItemIds);
        if ($selected->isEmpty()) {
            throw new RuntimeException('No matching line items found.');
        }

        foreach ($selected as $lineItem) {
            /** @var ShopifyOrderLineItem $lineItem */
            $qty = max(1, (int) $lineItem->quantity);
            $lineItem->fulfilled_quantity = 0;
            $lineItem->fulfillable_quantity = $qty;
            $lineItem->save();

            foreach ($order->fulfillmentOrders as $fo) {
                foreach ($fo->lineItems as $foLine) {
                    $matchesLocal = (int) ($foLine->shopify_order_line_item_id ?? 0) === (int) $lineItem->id;
                    $matchesShopify = trim((string) ($foLine->shopify_line_item_id ?? '')) !== ''
                        && trim((string) $foLine->shopify_line_item_id) === trim((string) ($lineItem->shopify_line_item_id ?? ''));
                    $raw = is_array($foLine->raw_json) ? $foLine->raw_json : [];
                    $matchSku = trim((string) ($raw['sku'] ?? '')) !== ''
                        && trim((string) ($raw['sku'] ?? '')) === trim((string) ($lineItem->sku ?? ''));
                    if ($matchesLocal || $matchesShopify || $matchSku) {
                        $foLine->remaining_quantity = $qty;
                        $foLine->save();
                    }
                }
            }
        }

        $order->fulfillment_status = 'unfulfilled';
        $order->crm_fulfillment_cancelled_at = null;
        $order->crm_hold_reasons = [];
        $order->save();

        return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
    }

    public function reprocessOrder(ShopifyOrder $order): ShopifyOrder
    {
        $this->assertNotShipped($order);

        $order->crm_hold_reasons = [];
        $order->crm_fulfillment_cancelled_at = null;
        $order->save();

        $connection = $order->connection;
        if ($connection !== null && $connection->hasCredentials()) {
            $refreshed = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
            if ($refreshed !== null) {
                $refreshed->crm_hold_reasons = [];
                $refreshed->crm_fulfillment_cancelled_at = null;
                $refreshed->save();

                return $refreshed->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
            }
        }

        return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
    }

    /**
     * Apply a CRM display-status change (not Fulfilled — that uses fulfill flow).
     *
     * @param  list<string>  $holdReasons
     */
    public function applyDisplayStatus(ShopifyOrder $order, string $status, array $holdReasons = []): ShopifyOrder
    {
        $status = strtolower(trim($status));
        if ($status === 'shipped') {
            $status = ShopifyOrderListService::DISPLAY_FULFILLED;
        }

        if ($this->list->isFulfilled($order)) {
            throw new RuntimeException(self::SHIPPED_STATUS_LOCK_MESSAGE);
        }

        if ($order->cancelled_at !== null) {
            throw new RuntimeException(self::CANCELLED_STATUS_LOCK_MESSAGE);
        }

        if ($status === ShopifyOrderListService::DISPLAY_FULFILLED) {
            throw new RuntimeException('Use Mark Fulfilled to set Fulfilled status.');
        }

        if ($status === ShopifyOrderListService::DISPLAY_ON_HOLD) {
            return $this->holdOrder($order, $holdReasons);
        }

        $this->assertNotShipped($order);

        if ($status === ShopifyOrderListService::DISPLAY_READY) {
            $order->crm_hold_reasons = [];
            $order->crm_fulfillment_cancelled_at = null;
            $raw = is_array($order->raw_json) ? $order->raw_json : [];
            // Explicit override so Backorder does not stick after clearing the hint.
            $raw['crm_display_hint'] = 'ready_to_ship';
            $order->raw_json = $raw;
            $order->save();

            return $order->fresh(['connection.clientAccount', 'lineItems']);
        }

        if ($status === ShopifyOrderListService::DISPLAY_BACKORDER) {
            $order->crm_hold_reasons = [];
            $order->crm_fulfillment_cancelled_at = null;
            $raw = is_array($order->raw_json) ? $order->raw_json : [];
            $raw['crm_display_hint'] = 'backorder';
            $order->raw_json = $raw;
            $order->save();

            return $order->fresh(['connection.clientAccount', 'lineItems']);
        }

        throw new RuntimeException('Unsupported status.');
    }

    /**
     * @return list<array{fo_line_item_id:string, quantity:int}>
     */
    private function collectFulfillableFoItems(ShopifyOrder $order): array
    {
        $items = [];
        foreach ($order->fulfillmentOrders as $fo) {
            $status = strtolower(trim((string) ($fo->status ?? '')));
            if (in_array($status, ['closed', 'cancelled', 'incomplete'], true)) {
                continue;
            }
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

        return $items;
    }

    /**
     * @param  list<int>|null  $deductLineItemIds
     */
    private function deductInventoryForOrder(ShopifyOrder $order, ?array $deductLineItemIds): void
    {
        $order->loadMissing(['lineItems', 'connection']);
        $connection = $order->connection;
        if ($connection === null) {
            return;
        }

        $lines = $order->lineItems;
        if ($deductLineItemIds !== null) {
            $ids = array_map('intval', $deductLineItemIds);
            $lines = $lines->whereIn('id', $ids);
        }

        foreach ($lines as $lineItem) {
            $sku = trim((string) ($lineItem->sku ?? ''));
            $qty = max(0, (int) $lineItem->quantity);
            if ($sku === '' || $qty <= 0) {
                continue;
            }

            $variant = ShopifyProductVariant::query()
                ->with('connection')
                ->where('connection_id', $connection->id)
                ->where('sku', $sku)
                ->first();
            if ($variant === null) {
                continue;
            }

            $itemId = trim((string) ($variant->shopify_inventory_item_id ?? ''));
            if ($itemId === '') {
                continue;
            }

            $enabledLocations = ShopifyLocation::query()
                ->where('connection_id', $connection->id)
                ->where('sync_inventory', true)
                ->pluck('shopify_location_id')
                ->map(static function ($id) {
                    return (string) $id;
                })
                ->all();

            $levels = ShopifyInventoryLevel::query()
                ->where('connection_id', $connection->id)
                ->where('shopify_inventory_item_id', $itemId)
                ->get();

            $pushLevels = [];
            foreach ($levels as $level) {
                $locId = (string) $level->shopify_location_id;
                if ($enabledLocations !== [] && ! in_array($locId, $enabledLocations, true)) {
                    continue;
                }
                $available = max(0, (int) $level->available - $qty);
                $level->available = $available;
                $level->crm_set_at = now();
                $level->save();
                $pushLevels[] = [
                    'location_id' => $locId,
                    'available' => $available,
                ];
            }

            if ($pushLevels !== []) {
                try {
                    $this->products->pushInventoryToShopify($variant, $pushLevels);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * @param  list<string>  $reasons
     */
    private function pushHoldTags($connection, ShopifyOrder $order, array $reasons): void
    {
        $holdTags = array_values(array_unique(array_filter(array_map(
            static fn (string $r) => 'crm-hold:'.$r,
            $reasons
        ))));
        if ($holdTags === []) {
            return;
        }

        $orderId = trim((string) $order->shopify_order_id);
        if ($orderId === '') {
            throw new RuntimeException('Shopify order id missing for hold tag sync.');
        }

        $gid = ShopifyGid::of('Order', $orderId);
        $api = $this->client->forConnection($connection);

        // Confirm the order is visible to this shop token before mutating.
        $probe = $api->graphql(
            <<<'GQL'
query OrderExists($id: ID!) {
  order(id: $id) { id }
}
GQL
            ,
            ['id' => $gid]
        );
        if (! is_array($probe['order'] ?? null) || trim((string) ($probe['order']['id'] ?? '')) === '') {
            throw new RuntimeException(
                'Shopify order '.$orderId.' is not reachable with this store connection (Order does not exist).'
            );
        }

        // tagsAdd appends; avoids wiping existing merchant tags via orderUpdate.
        $data = $api->graphql(
            <<<'GQL'
mutation tagsAdd($id: ID!, $tags: [String!]!) {
  tagsAdd(id: $id, tags: $tags) {
    node { ... on Order { id tags } }
    userErrors { field message }
  }
}
GQL
            ,
            [
                'id' => $gid,
                'tags' => $holdTags,
            ]
        );

        $payload = is_array($data['tagsAdd'] ?? null) ? $data['tagsAdd'] : [];
        $errors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($errors !== []) {
            throw new RuntimeException((string) ($errors[0]['message'] ?? 'Could not update order tags.'));
        }
    }
}
