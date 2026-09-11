<?php

namespace App\Services;

use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderActivity;
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

    /** @var ShopifyOrderActivityService */
    private $activities;

    public function __construct(
        ShopifyClient $client,
        ShopifyOrderSyncService $sync,
        ShopifyFulfillmentService $fulfillments,
        ShopifyOrderListService $list,
        ShopifyProductSyncService $products,
        ShopifyOrderActivityService $activities
    ) {
        $this->client = $client;
        $this->sync = $sync;
        $this->fulfillments = $fulfillments;
        $this->list = $list;
        $this->products = $products;
        $this->activities = $activities;
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
    public function holdOrder(ShopifyOrder $order, array $reasons, ?User $actor = null): ShopifyOrder
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

        try {
            app(ShopifyOrderActivityService::class)->record(
                $order,
                \App\Models\ShopifyOrderActivity::TYPE_HOLD,
                'Order Put On Hold',
                'Reason: '.implode(', ', $reasons),
                $actor
            );
        } catch (\Throwable $e) {
            report($e);
        }

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

            // Always zero CRM lines after Shopify cancel (sync can restore fulfillable qty).
            return $this->finalizeCrmCancel($refreshed, true);
        }

        return $this->cancelFulfillmentInCrm($order);
    }

    /**
     * Cancel 3PL fulfillment for all items in CRM only (does not call Shopify).
     */
    public function cancelFulfillmentInCrm(ShopifyOrder $order): ShopifyOrder
    {
        $this->assertNotShipped($order);

        return $this->finalizeCrmCancel($order, false);
    }

    /**
     * Zero all unfulfilled line qtys, mark CRM cancel, and record timeline activity.
     */
    private function finalizeCrmCancel(ShopifyOrder $order, bool $cancelledInShopify): ShopifyOrder
    {
        $this->zeroCrmFulfillableQuantities($order);

        $order->crm_fulfillment_cancelled_at = now();
        $order->crm_hold_reasons = [];
        $order->save();

        try {
            $actor = auth()->user();
            $this->activities->record(
                $order,
                ShopifyOrderActivity::TYPE_CANCEL,
                $cancelledInShopify
                    ? 'Order canceled in CRM and Shopify'
                    : 'Order canceled in CRM (fulfillment canceled for all items)',
                null,
                $actor instanceof User ? $actor : null
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
    }

    /**
     * Zero FO remaining + line fulfillable qty so all unfulfilled lines show Cancelled.
     */
    private function zeroCrmFulfillableQuantities(ShopifyOrder $order): void
    {
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
    }

    /**
     * @param  list<int>|null  $deductLineItemIds  Order line item IDs to fulfill + deduct (null = all remaining)
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

        $selectedIds = null;
        if ($deductLineItemIds !== null) {
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', $deductLineItemIds))));
            if ($selectedIds === []) {
                throw new RuntimeException('Select at least one item to fulfill.');
            }
        }

        $items = $this->collectFulfillableFoItems($order, $selectedIds);
        if ($items === [] && $connection !== null && $connection->hasCredentials()) {
            try {
                $this->sync->syncFulfillmentOrdersFromRestApi($connection, $order);
            } catch (\Throwable $e) {
                report($e);
            }
            $order->load(['fulfillmentOrders.lineItems']);
            $items = $this->collectFulfillableFoItems($order, $selectedIds);
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
        $this->applyLocalFulfillmentProgress($orderFresh, $items);
        $orderFresh->crm_fulfillment_cancelled_at = null;
        $orderFresh->crm_hold_reasons = [];
        $orderFresh->save();

        $this->deductInventoryForOrder($orderFresh, $selectedIds);

        $orderOut = $orderFresh->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']);
        $lineCount = $selectedIds !== null ? count($selectedIds) : count($items);
        $detailParts = [$lineCount.' item'.($lineCount === 1 ? '' : 's').' fulfilled'];
        if ($tracking !== '') {
            $detailParts[] = 'Tracking '.$tracking;
        }
        $this->activities->record(
            $orderOut,
            ShopifyOrderActivity::TYPE_FULFILL,
            'Order marked fulfilled',
            implode(' · ', $detailParts),
            $actor
        );

        return [
            'fulfillment' => $result['fulfillment'],
            'order' => $orderOut->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']),
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

        if ($status === ShopifyOrderListService::DISPLAY_DRAFT) {
            $order->crm_hold_reasons = [];
            $order->crm_fulfillment_cancelled_at = null;
            $raw = is_array($order->raw_json) ? $order->raw_json : [];
            $raw['crm_display_hint'] = 'draft';
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
     * @param  list<int>|null  $onlyOrderLineItemIds
     * @return list<array{fo_line_item_id:string, quantity:int, order_line_item_id:?int}>
     */
    private function collectFulfillableFoItems(ShopifyOrder $order, ?array $onlyOrderLineItemIds = null): array
    {
        $allowed = null;
        if ($onlyOrderLineItemIds !== null) {
            $allowed = array_fill_keys(array_map('intval', $onlyOrderLineItemIds), true);
        }

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
                $orderLineId = (int) ($line->shopify_order_line_item_id ?? 0);
                if ($allowed !== null) {
                    if ($orderLineId <= 0 || ! isset($allowed[$orderLineId])) {
                        continue;
                    }
                }
                $items[] = [
                    'fo_line_item_id' => (string) $line->shopify_fo_line_item_id,
                    'quantity' => $remaining,
                    'order_line_item_id' => $orderLineId > 0 ? $orderLineId : null,
                ];
            }
        }

        return $items;
    }

    /**
     * Keep CRM line/FO qty in sync after a (possibly partial) fulfill.
     * Idempotent when Shopify refresh already applied the same progress.
     *
     * @param  list<array{fo_line_item_id:string, quantity:int, order_line_item_id?:?int}>  $fulfilledItems
     */
    private function applyLocalFulfillmentProgress(ShopifyOrder $order, array $fulfilledItems): void
    {
        $order->loadMissing(['lineItems', 'fulfillmentOrders.lineItems']);

        $qtyByFoLine = [];
        $qtyByOrderLine = [];
        foreach ($fulfilledItems as $item) {
            $foId = trim((string) ($item['fo_line_item_id'] ?? ''));
            $qty = (int) ($item['quantity'] ?? 0);
            if ($foId === '' || $qty <= 0) {
                continue;
            }
            $qtyByFoLine[$foId] = ($qtyByFoLine[$foId] ?? 0) + $qty;
            $orderLineId = (int) ($item['order_line_item_id'] ?? 0);
            if ($orderLineId > 0) {
                $qtyByOrderLine[$orderLineId] = ($qtyByOrderLine[$orderLineId] ?? 0) + $qty;
            }
        }

        foreach ($order->fulfillmentOrders as $fo) {
            foreach ($fo->lineItems as $foLine) {
                $foShopifyId = trim((string) ($foLine->shopify_fo_line_item_id ?? ''));
                if ($foShopifyId === '' || ! isset($qtyByFoLine[$foShopifyId])) {
                    continue;
                }
                $fulfilledQty = (int) $qtyByFoLine[$foShopifyId];
                $remaining = (int) $foLine->remaining_quantity;
                if ($remaining <= 0) {
                    // Already synced from Shopify.
                    continue;
                }
                $foLine->remaining_quantity = max(0, $remaining - $fulfilledQty);
                $foLine->save();

                $orderLineId = (int) ($foLine->shopify_order_line_item_id ?? 0);
                if ($orderLineId > 0 && ! isset($qtyByOrderLine[$orderLineId])) {
                    $qtyByOrderLine[$orderLineId] = $fulfilledQty;
                }
            }
        }

        foreach ($order->lineItems as $lineItem) {
            $lineId = (int) $lineItem->id;
            if (! isset($qtyByOrderLine[$lineId])) {
                continue;
            }
            if ($this->list->rawLineStatus($lineItem) === 'fulfilled') {
                continue;
            }
            $add = (int) $qtyByOrderLine[$lineId];
            $qty = max(0, (int) $lineItem->quantity);
            if ($add <= 0) {
                continue;
            }
            $fulfilled = min($qty, (int) $lineItem->fulfilled_quantity + $add);
            $lineItem->fulfilled_quantity = $fulfilled;
            $lineItem->fulfillable_quantity = max(0, $qty - $fulfilled);
            $lineItem->save();
        }

        $order->unsetRelation('lineItems');
        $order->load('lineItems');
        if ($this->list->hasNoPendingLines($order)) {
            $order->fulfillment_status = 'fulfilled';
        } else {
            $status = strtolower(trim((string) $order->fulfillment_status));
            if ($status === '' || $status === 'unfulfilled' || $status === 'fulfilled') {
                $order->fulfillment_status = 'partial';
            }
        }
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
