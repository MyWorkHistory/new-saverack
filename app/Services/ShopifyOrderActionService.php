<?php

namespace App\Services;

use App\Models\ShopifyFulfillment;
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

    /** @var ShopifyOrderEditService */
    private $edits;

    public function __construct(
        ShopifyClient $client,
        ShopifyOrderSyncService $sync,
        ShopifyFulfillmentService $fulfillments,
        ShopifyOrderListService $list,
        ShopifyProductSyncService $products,
        ShopifyOrderActivityService $activities,
        ShopifyOrderEditService $edits
    ) {
        $this->client = $client;
        $this->sync = $sync;
        $this->fulfillments = $fulfillments;
        $this->list = $list;
        $this->products = $products;
        $this->activities = $activities;
        $this->edits = $edits;
    }

    public function assertNotShipped(ShopifyOrder $order): void
    {
        if ($this->list->isFulfilled($order)) {
            throw new RuntimeException(self::SHIPPED_STATUS_LOCK_MESSAGE);
        }
    }

    public function syncOrder(ShopifyOrder $order): ShopifyOrder
    {
        if ($order->isCrmSource()) {
            throw new RuntimeException('CRM manual orders are not synced from Shopify.');
        }

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
    public function holdOrder(ShopifyOrder $order, array $reasons, ?User $actor = null, bool $pushShopifyTags = true): ShopifyOrder
    {
        $this->assertNotShipped($order);

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
        $order->cancelled_at = null;
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

        // Manual CRM orders are not in Shopify — never push hold tags.
        // Reopening a cancelled order is CRM-only; do not push tags to Shopify.
        if ($pushShopifyTags && ! $order->isCrmSource()) {
            $order->loadMissing('connection');
            $connection = $order->connection;
            if ($connection !== null && $connection->hasCredentials()) {
                try {
                    $this->pushHoldTags($connection, $order, $reasons);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $order->fresh(['connection.clientAccount', 'lineItems']);
    }

    /**
     * Remove selected CRM hold reasons. Clears on-hold when none remain.
     *
     * @param  list<string>  $reasonsToClear
     */
    public function removeHolds(ShopifyOrder $order, array $reasonsToClear, ?User $actor = null): ShopifyOrder
    {
        $this->assertNotShipped($order);

        $reasonsToClear = array_values(array_unique(array_filter(array_map('trim', $reasonsToClear))));
        if ($reasonsToClear === []) {
            throw new RuntimeException('Select at least one hold to remove.');
        }

        $invalid = array_diff($reasonsToClear, ShopifyOrderListService::HOLD_REASONS);
        if ($invalid !== []) {
            throw new RuntimeException('Invalid hold reason: '.implode(', ', $invalid));
        }

        $current = is_array($order->crm_hold_reasons) ? $order->crm_hold_reasons : [];
        $current = array_values(array_filter(array_map('trim', $current)));
        if ($current === []) {
            throw new RuntimeException('This order has no active holds.');
        }

        $clearSet = array_fill_keys($reasonsToClear, true);
        $remaining = [];
        foreach ($current as $reason) {
            if (! isset($clearSet[$reason])) {
                $remaining[] = $reason;
            }
        }

        $actuallyCleared = array_values(array_filter($current, static function ($reason) use ($clearSet) {
            return isset($clearSet[$reason]);
        }));
        if ($actuallyCleared === []) {
            throw new RuntimeException('Select at least one active hold to remove.');
        }

        $order->crm_hold_reasons = $remaining;
        $order->save();

        try {
            $this->activities->record(
                $order,
                ShopifyOrderActivity::TYPE_HOLD,
                $remaining === [] ? 'Hold Removed' : 'Holds Updated',
                'Removed: '.implode(', ', $actuallyCleared)
                    .($remaining !== [] ? ' · Remaining: '.implode(', ', $remaining) : ''),
                $actor
            );
        } catch (\Throwable $e) {
            report($e);
        }

        if (! $order->isCrmSource()) {
            $order->loadMissing('connection');
            $connection = $order->connection;
            if ($connection !== null && $connection->hasCredentials()) {
                try {
                    $this->removeHoldTags($connection, $order, $actuallyCleared);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $order->fresh(['connection.clientAccount', 'lineItems']);
    }

    public function cancelOrder(ShopifyOrder $order, bool $cancelInShopify = false): ShopifyOrder
    {
        $this->assertNotShipped($order);
        $this->clearIgnoreShopifyCancel($order);

        // Manual CRM orders are not in Shopify.
        if ($cancelInShopify && $order->isCrmSource()) {
            $cancelInShopify = false;
        }

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

        // Manual CRM orders are not in Shopify — fulfill line qtys locally only.
        if ($order->isCrmSource()) {
            return $this->fulfillCrmOrderLocally($order, $actor, $trackingNumber, $deductLineItemIds);
        }

        $order->loadMissing(['lineItems', 'fulfillmentOrders.lineItems', 'connection']);

        $selectedIds = null;
        $selectionKeys = null;
        if ($deductLineItemIds !== null) {
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', $deductLineItemIds))));
            if ($selectedIds === []) {
                throw new RuntimeException('Select at least one item to fulfill.');
            }
            // CRM-added lines are replaced with real Shopify line ids after push/sync.
            $selectionKeys = $this->lineSelectionKeys($order, $selectedIds);
        }

        // Item edits stay in CRM until the order is fulfilled.
        $order = $this->edits->pushPendingItemEditsToShopify($order);

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

        if ($selectionKeys !== null) {
            $selectedIds = $this->resolveLineIdsFromKeys($order, $selectionKeys);
            if ($selectedIds === []) {
                throw new RuntimeException(
                    'Could not match the selected items after syncing edits to Shopify. Sync the order and try again.'
                );
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
            $this->healFoRemainingFromOrderLines($order, $selectedIds);
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
     * Set one item to Cancelled, Backorder, or Fulfilled.
     * Fulfilled sends that item and tracking to Shopify. Cancel and Backorder stay in CRM.
     */
    public function applyLineStatus(
        ShopifyOrder $order,
        ShopifyOrderLineItem $line,
        string $status,
        ?string $trackingNumber = null,
        ?User $actor = null
    ): ShopifyOrder {
        if ((int) $line->shopify_order_id !== (int) $order->id) {
            throw new RuntimeException('Item does not belong to this order.');
        }

        $status = strtolower(trim($status));
        if ($status === 'cancel') {
            $status = 'cancelled';
        }
        if (! in_array($status, ['cancelled', 'backorder', 'fulfilled'], true)) {
            throw new RuntimeException('Unsupported item status.');
        }

        $label = trim((string) ($line->title ?: $line->sku ?: 'Item'));

        if ($status === 'fulfilled') {
            if ($this->list->rawLineStatus($line) === 'fulfilled') {
                throw new RuntimeException('This item is already fulfilled.');
            }
            $this->prepareLineForFulfill($order, $line);
            $order = $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']) ?? $order;
            if ($this->list->isCancelled($order)) {
                $order->crm_fulfillment_cancelled_at = null;
                $order->cancelled_at = null;
                $raw = is_array($order->raw_json) ? $order->raw_json : [];
                $raw['crm_ignore_shopify_cancel'] = true;
                $order->raw_json = $raw;
                $order->save();
            }
            $result = $this->fulfillAllRemaining($order, $actor, $trackingNumber, [(int) $line->id]);
            $order = $result['order'];
            $this->clearLineStatusFlag($order, (int) $line->id);

            return $this->syncOrderStatusFromLines($order);
        }

        if ($status === 'cancelled') {
            $this->cancelLineInCrm($order, $line);
            $this->activities->record(
                $order,
                ShopifyOrderActivity::TYPE_STATUS,
                'Item cancelled',
                $label,
                $actor
            );

            return $this->syncOrderStatusFromLines($order);
        }

        $this->markLineBackorder($order, $line);
        $this->activities->record(
            $order,
            ShopifyOrderActivity::TYPE_STATUS,
            'Item marked backorder',
            $label,
            $actor
        );

        return $this->syncOrderStatusFromLines($order);
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

        if (! $order->isCrmSource()) {
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

        if ($status === ShopifyOrderListService::DISPLAY_FULFILLED) {
            throw new RuntimeException('Use Mark Fulfilled to set Fulfilled status.');
        }

        if ($status === ShopifyOrderListService::DISPLAY_CANCELLED) {
            throw new RuntimeException('Use Cancel Order to set Cancelled status.');
        }

        $previousStatus = $this->list->displayStatus($order);
        $recoveringFromCancel = $previousStatus === ShopifyOrderListService::DISPLAY_CANCELLED
            || $order->cancelled_at !== null
            || $order->crm_fulfillment_cancelled_at !== null;

        if ($recoveringFromCancel) {
            $this->restoreCancelledOrderToPending($order);
            $order = $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']) ?? $order;
        }

        if ($status === ShopifyOrderListService::DISPLAY_ON_HOLD) {
            $held = $this->holdOrder($order, $holdReasons, null, ! $recoveringFromCancel);
            // holdOrder records its own timeline; also note recovery when leaving cancelled.
            if ($recoveringFromCancel && $previousStatus === ShopifyOrderListService::DISPLAY_CANCELLED) {
                $this->recordDisplayStatusChange(
                    $held,
                    $previousStatus,
                    $this->list->displayStatus($held)
                );
            }

            return $held;
        }

        $this->assertNotShipped($order);

        if ($status === ShopifyOrderListService::DISPLAY_READY) {
            $order->crm_hold_reasons = [];
            $order->crm_fulfillment_cancelled_at = null;
            $order->cancelled_at = null;
            $raw = is_array($order->raw_json) ? $order->raw_json : [];
            // Explicit override so Backorder does not stick after clearing the hint.
            $raw['crm_display_hint'] = 'ready_to_ship';
            $order->raw_json = $raw;
            $order->save();

            return $this->finishDisplayStatusChange($order, $previousStatus);
        }

        if ($status === ShopifyOrderListService::DISPLAY_DRAFT) {
            $order->crm_hold_reasons = [];
            $order->crm_fulfillment_cancelled_at = null;
            $order->cancelled_at = null;
            $raw = is_array($order->raw_json) ? $order->raw_json : [];
            $raw['crm_display_hint'] = 'draft';
            $order->raw_json = $raw;
            $order->save();

            return $this->finishDisplayStatusChange($order, $previousStatus);
        }

        if ($status === ShopifyOrderListService::DISPLAY_BACKORDER) {
            $order->crm_hold_reasons = [];
            $order->crm_fulfillment_cancelled_at = null;
            $order->cancelled_at = null;
            $raw = is_array($order->raw_json) ? $order->raw_json : [];
            $raw['crm_display_hint'] = 'backorder';
            $order->raw_json = $raw;
            $order->save();

            return $this->finishDisplayStatusChange($order, $previousStatus);
        }

        throw new RuntimeException('Unsupported status.');
    }

    /**
     * Clear CRM/Shopify cancel flags and restore every unfulfilled line to Pending.
     * Does not call Shopify. Later syncs must not put the order back on Cancelled.
     */
    private function restoreCancelledOrderToPending(ShopifyOrder $order): void
    {
        $order->loadMissing(['lineItems', 'fulfillmentOrders.lineItems']);

        $hasPending = false;
        $hasFulfilled = false;
        foreach ($order->lineItems as $lineItem) {
            $raw = is_array($lineItem->raw_json) ? $lineItem->raw_json : [];
            $qty = $this->restoredLineQuantity($lineItem, $raw, $order);
            $fulfilled = max(0, (int) $lineItem->fulfilled_quantity);
            if ($qty > 0 && $fulfilled > $qty) {
                $fulfilled = $qty;
            }
            unset($raw['crm_line_cancelled']);
            if ($qty > 0) {
                $raw['crm_original_quantity'] = $qty;
                $raw['crm_quantity_locked'] = true;
            }
            $lineItem->raw_json = $raw;
            $lineItem->quantity = $qty;
            $lineItem->fulfilled_quantity = $fulfilled;
            $lineItem->fulfillable_quantity = $qty > $fulfilled ? $qty - $fulfilled : 0;
            $lineItem->save();
            if ((int) $lineItem->fulfillable_quantity > 0) {
                $hasPending = true;
            }
            if ($fulfilled > 0 && $fulfilled >= $qty) {
                $hasFulfilled = true;
            }
        }

        $order->load('lineItems');

        foreach ($order->fulfillmentOrders as $fo) {
            $status = strtolower(trim((string) $fo->status));
            if (in_array($status, ['cancelled', 'closed', 'incomplete'], true)) {
                $fo->status = 'open';
                $fo->save();
            }
            foreach ($fo->lineItems as $foLine) {
                $total = max(0, (int) ($foLine->total_quantity ?? 0));
                $orderLineId = (int) ($foLine->shopify_order_line_item_id ?? 0);
                $remaining = $total;
                if ($orderLineId > 0) {
                    $match = $order->lineItems->firstWhere('id', $orderLineId);
                    if ($match !== null) {
                        $remaining = max(0, (int) $match->fulfillable_quantity);
                        if ($total <= 0) {
                            $total = max($total, (int) $match->quantity);
                        }
                    }
                } else {
                    $sid = trim((string) ($foLine->shopify_line_item_id ?? ''));
                    if ($sid !== '') {
                        $match = $order->lineItems->firstWhere('shopify_line_item_id', $sid);
                        if ($match !== null) {
                            $remaining = max(0, (int) $match->fulfillable_quantity);
                            if ($total <= 0) {
                                $total = max($total, (int) $match->quantity);
                            }
                        }
                    }
                }
                if ($total <= 0 && $remaining > 0) {
                    $foLine->total_quantity = $remaining;
                } elseif ($total > 0) {
                    $foLine->total_quantity = $total;
                }
                $foLine->remaining_quantity = $remaining;
                $foLine->save();
            }
        }

        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        $raw['crm_ignore_shopify_cancel'] = true;
        $order->raw_json = $raw;
        $order->crm_fulfillment_cancelled_at = null;
        $order->cancelled_at = null;
        if ($hasPending && $hasFulfilled) {
            $order->fulfillment_status = 'partial';
        } elseif ($hasPending) {
            $order->fulfillment_status = 'unfulfilled';
        }
        $order->save();
    }

    /**
     * Shopify cancel often stores quantity 0. Recover the original so the line can be Pending.
     *
     * @param  array<string, mixed>  $raw
     */
    private function restoredLineQuantity(ShopifyOrderLineItem $lineItem, array $raw, ShopifyOrder $order): int
    {
        $qty = max(0, (int) $lineItem->quantity);
        $candidates = [
            (int) ($raw['crm_original_quantity'] ?? 0),
            (int) ($raw['quantity'] ?? 0),
        ];
        $current = (int) ($raw['currentQuantity'] ?? $raw['current_quantity'] ?? 0);
        if ($current > 0) {
            $candidates[] = $current;
        }
        foreach ($candidates as $candidate) {
            if ($candidate > $qty) {
                $qty = $candidate;
            }
        }
        if ($qty > 0) {
            return $qty;
        }

        foreach ($order->fulfillmentOrders as $fo) {
            foreach ($fo->lineItems as $foLine) {
                $matchesId = (int) ($foLine->shopify_order_line_item_id ?? 0) === (int) $lineItem->id;
                $matchesShopify = trim((string) ($foLine->shopify_line_item_id ?? '')) !== ''
                    && (string) $foLine->shopify_line_item_id === (string) $lineItem->shopify_line_item_id;
                if (! $matchesId && ! $matchesShopify) {
                    continue;
                }
                $total = max((int) ($foLine->total_quantity ?? 0), (int) ($foLine->remaining_quantity ?? 0));
                if ($total > $qty) {
                    $qty = $total;
                }
            }
        }

        return $qty > 0 ? $qty : 1;
    }

    private function clearIgnoreShopifyCancel(ShopifyOrder $order): void
    {
        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        if (empty($raw['crm_ignore_shopify_cancel'])) {
            return;
        }
        unset($raw['crm_ignore_shopify_cancel']);
        $order->raw_json = $raw;
        $order->save();
    }

    /**
     * Persist timeline when CRM display status actually changes.
     */
    private function finishDisplayStatusChange(ShopifyOrder $order, string $previousStatus): ShopifyOrder
    {
        $fresh = $order->fresh(['connection.clientAccount', 'lineItems']);
        if ($fresh === null) {
            return $order;
        }

        $newStatus = $this->list->displayStatus($fresh);
        if ($previousStatus !== $newStatus) {
            $detail = 'Previously: '.$this->list->displayStatusLabel($previousStatus);
            if ($previousStatus === ShopifyOrderListService::DISPLAY_CANCELLED) {
                $detail .= ' · Items restored to Pending';
            }
            $this->recordDisplayStatusChange($fresh, $previousStatus, $newStatus, $detail);
        }

        return $fresh;
    }

    private function recordDisplayStatusChange(
        ShopifyOrder $order,
        string $fromStatus,
        string $toStatus,
        ?string $detail = null
    ): void {
        $fromLabel = $this->list->displayStatusLabel($fromStatus);
        $toLabel = $this->list->displayStatusLabel($toStatus);
        $type = $toStatus === ShopifyOrderListService::DISPLAY_READY
            ? ShopifyOrderActivity::TYPE_READY
            : ShopifyOrderActivity::TYPE_STATUS;

        try {
            $actor = auth()->user();
            $this->activities->record(
                $order,
                $type,
                'Status Updated to: '.$toLabel,
                $detail !== null && $detail !== '' ? $detail : 'Previously: '.$fromLabel,
                $actor instanceof User ? $actor : null,
                null,
                [
                    'from' => $fromStatus,
                    'to' => $toStatus,
                ]
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Fulfill a CRM-only order without calling Shopify (no FOs / remote fulfillment).
     *
     * @param  list<int>|null  $deductLineItemIds
     * @return array{fulfillment:ShopifyFulfillment, order:ShopifyOrder}
     */
    private function fulfillCrmOrderLocally(
        ShopifyOrder $order,
        ?User $actor,
        ?string $trackingNumber,
        ?array $deductLineItemIds
    ): array {
        $order->loadMissing(['lineItems', 'connection']);

        $selectedIds = null;
        if ($deductLineItemIds !== null) {
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', $deductLineItemIds))));
            if ($selectedIds === []) {
                throw new RuntimeException('Select at least one item to fulfill.');
            }
        }

        $fulfilledPayload = [];
        $fulfilledCount = 0;
        foreach ($order->lineItems as $lineItem) {
            /** @var ShopifyOrderLineItem $lineItem */
            $lineId = (int) $lineItem->id;
            if ($selectedIds !== null && ! in_array($lineId, $selectedIds, true)) {
                continue;
            }
            if ($this->list->rawLineStatus($lineItem) === 'fulfilled') {
                continue;
            }
            $qty = max(0, (int) $lineItem->quantity);
            $already = max(0, (int) $lineItem->fulfilled_quantity);
            $remain = max(0, (int) $lineItem->fulfillable_quantity);
            if ($remain <= 0) {
                $remain = max(0, $qty - $already);
            }
            if ($remain <= 0 || $qty <= 0) {
                continue;
            }
            $lineItem->fulfilled_quantity = min($qty, $already + $remain);
            $lineItem->fulfillable_quantity = max(0, $qty - (int) $lineItem->fulfilled_quantity);
            $lineItem->save();
            $fulfilledPayload[] = [
                'order_line_item_id' => $lineId,
                'quantity' => $remain,
                'sku' => (string) ($lineItem->sku ?? ''),
            ];
            $fulfilledCount++;
        }

        if ($fulfilledPayload === []) {
            throw new RuntimeException('No fulfillable quantities remain on this CRM order.');
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
        $order->crm_fulfillment_cancelled_at = null;
        $order->crm_hold_reasons = [];
        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        unset($raw['crm_display_hint']);
        $order->raw_json = $raw;
        $order->save();

        $tracking = trim((string) ($trackingNumber ?? ''));
        $fulfillment = ShopifyFulfillment::query()->create([
            'connection_id' => (int) $order->connection_id,
            'shopify_order_id' => (int) $order->id,
            'shopify_fulfillment_id' => null,
            'status' => 'success',
            'tracking_company' => $tracking !== '' ? 'UPS' : '',
            'tracking_number' => $tracking,
            'line_items_json' => $fulfilledPayload,
            'created_by_user_id' => $actor !== null ? (int) $actor->id : null,
            'raw_json' => ['crm' => true, 'source' => 'crm'],
        ]);

        $this->deductInventoryForOrder($order, $selectedIds);

        $orderOut = $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
        $detailParts = [$fulfilledCount.' item'.($fulfilledCount === 1 ? '' : 's').' fulfilled'];
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
            'fulfillment' => $fulfillment,
            'order' => $orderOut,
        ];
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
                $resolved = $this->resolveOrderLineForFoLine($order, $line);
                $orderLine = $resolved['line'];
                $orderLineId = $resolved['id'];
                if ($orderLine !== null && $this->list->rawLineStatus($orderLine) === 'cancelled') {
                    continue;
                }
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
     * @return array{line:?ShopifyOrderLineItem, id:int}
     */
    private function resolveOrderLineForFoLine(ShopifyOrder $order, $foLine): array
    {
        $orderLineId = (int) ($foLine->shopify_order_line_item_id ?? 0);
        $orderLine = $orderLineId > 0 ? $order->lineItems->firstWhere('id', $orderLineId) : null;
        $foShopifyLineId = trim((string) ($foLine->shopify_line_item_id ?? ''));

        if ($orderLine === null && $foShopifyLineId !== '') {
            $orderLine = $order->lineItems->firstWhere('shopify_line_item_id', $foShopifyLineId);
            if ($orderLine !== null) {
                $orderLineId = (int) $orderLine->id;
                if ((int) ($foLine->shopify_order_line_item_id ?? 0) !== $orderLineId) {
                    $foLine->shopify_order_line_item_id = $orderLineId;
                    $foLine->save();
                }
            }
        }

        return [
            'line' => $orderLine,
            'id' => $orderLineId,
        ];
    }

    /**
     * Stable fingerprints for selected lines so we can rematch after Shopify push/sync.
     *
     * @param  list<int>  $lineIds
     * @return list<array{shopify_line_item_id:string, shopify_variant_id:string, sku:string, quantity:int}>
     */
    private function lineSelectionKeys(ShopifyOrder $order, array $lineIds): array
    {
        $order->loadMissing('lineItems');
        $keys = [];
        foreach ($lineIds as $id) {
            $line = $order->lineItems->firstWhere('id', (int) $id);
            if ($line === null) {
                continue;
            }
            $sid = trim((string) ($line->shopify_line_item_id ?? ''));
            if ($sid !== '' && strpos($sid, 'crm-line-') === 0) {
                $sid = '';
            }
            $keys[] = [
                'shopify_line_item_id' => $sid,
                'shopify_variant_id' => trim((string) ($line->shopify_variant_id ?? '')),
                'sku' => trim((string) ($line->sku ?? '')),
                'quantity' => (int) $line->quantity,
            ];
        }

        return $keys;
    }

    /**
     * @param  list<array{shopify_line_item_id:string, shopify_variant_id:string, sku:string, quantity:int}>  $keys
     * @return list<int>
     */
    private function resolveLineIdsFromKeys(ShopifyOrder $order, array $keys): array
    {
        $order->loadMissing('lineItems');
        $used = [];
        $ids = [];

        foreach ($keys as $key) {
            if (! is_array($key)) {
                continue;
            }
            $match = null;
            $wantSid = trim((string) ($key['shopify_line_item_id'] ?? ''));
            if ($wantSid !== '') {
                foreach ($order->lineItems as $line) {
                    $lineId = (int) $line->id;
                    if (isset($used[$lineId])) {
                        continue;
                    }
                    if (trim((string) ($line->shopify_line_item_id ?? '')) === $wantSid) {
                        $match = $line;
                        break;
                    }
                }
            }

            if ($match === null) {
                $wantVariant = trim((string) ($key['shopify_variant_id'] ?? ''));
                $wantSku = trim((string) ($key['sku'] ?? ''));
                foreach ($order->lineItems as $line) {
                    $lineId = (int) $line->id;
                    if (isset($used[$lineId])) {
                        continue;
                    }
                    $sid = trim((string) ($line->shopify_line_item_id ?? ''));
                    if ($sid !== '' && strpos($sid, 'crm-line-') === 0) {
                        continue;
                    }
                    $variant = trim((string) ($line->shopify_variant_id ?? ''));
                    $sku = trim((string) ($line->sku ?? ''));
                    if ($wantVariant !== '' && $variant === $wantVariant) {
                        if ($wantSku === '' || $sku === $wantSku) {
                            $match = $line;
                            break;
                        }
                    }
                    if ($wantVariant === '' && $wantSku !== '' && $sku === $wantSku) {
                        $match = $line;
                        break;
                    }
                }
            }

            if ($match !== null) {
                $used[(int) $match->id] = true;
                $ids[] = (int) $match->id;
            }
        }

        return $ids;
    }

    /**
     * After CRM qty edits / adds, Shopify FO remaining can lag. Align FO remaining
     * to the order line fulfillable qty so partial fulfill can still run.
     *
     * @param  list<int>|null  $onlyOrderLineItemIds
     */
    private function healFoRemainingFromOrderLines(ShopifyOrder $order, ?array $onlyOrderLineItemIds = null): void
    {
        $order->loadMissing(['lineItems', 'fulfillmentOrders.lineItems']);
        $allowed = null;
        if ($onlyOrderLineItemIds !== null) {
            $allowed = array_fill_keys(array_map('intval', $onlyOrderLineItemIds), true);
        }

        foreach ($order->lineItems as $lineItem) {
            $lineId = (int) $lineItem->id;
            if ($allowed !== null && ! isset($allowed[$lineId])) {
                continue;
            }
            if ($this->list->rawLineStatus($lineItem) !== 'pending') {
                continue;
            }
            $need = max(0, (int) $lineItem->fulfillable_quantity);
            if ($need <= 0) {
                continue;
            }

            $matched = false;
            foreach ($order->fulfillmentOrders as $fo) {
                $status = strtolower(trim((string) ($fo->status ?? '')));
                if (in_array($status, ['closed', 'cancelled', 'incomplete'], true)) {
                    continue;
                }
                foreach ($fo->lineItems as $foLine) {
                    $resolved = $this->resolveOrderLineForFoLine($order, $foLine);
                    if ((int) $resolved['id'] !== $lineId) {
                        continue;
                    }
                    $matched = true;
                    if ((int) $foLine->remaining_quantity < $need) {
                        $foLine->remaining_quantity = $need;
                        if ((int) $foLine->total_quantity < $need) {
                            $foLine->total_quantity = $need;
                        }
                        $foLine->save();
                    }
                }
            }

            if (! $matched) {
                // No FO row yet for a CRM-added / edited line: create a local open FO line
                // so Shopify fulfillment can still target it after sync assigns real FO ids.
                continue;
            }
        }
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

    /**
     * @param  list<string>  $reasons
     */
    private function removeHoldTags($connection, ShopifyOrder $order, array $reasons): void
    {
        $holdTags = array_values(array_unique(array_filter(array_map(
            static function ($r) {
                return 'crm-hold:'.trim((string) $r);
            },
            $reasons
        ))));
        if ($holdTags === []) {
            return;
        }

        $orderId = trim((string) $order->shopify_order_id);
        if ($orderId === '') {
            return;
        }

        $gid = ShopifyGid::of('Order', $orderId);
        $api = $this->client->forConnection($connection);
        $data = $api->graphql(
            <<<'GQL'
mutation tagsRemove($id: ID!, $tags: [String!]!) {
  tagsRemove(id: $id, tags: $tags) {
    node { ... on Order { id } }
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

        $payload = is_array($data['tagsRemove'] ?? null) ? $data['tagsRemove'] : [];
        $errors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($errors !== []) {
            throw new RuntimeException((string) ($errors[0]['message'] ?? 'Could not remove hold tags.'));
        }
    }

    private function cancelLineInCrm(ShopifyOrder $order, ShopifyOrderLineItem $line): void
    {
        $displayQty = max(1, (int) $line->quantity);
        $raw = is_array($line->raw_json) ? $line->raw_json : [];
        $raw['crm_line_cancelled'] = true;
        $raw['crm_original_quantity'] = max($displayQty, (int) ($raw['crm_original_quantity'] ?? 0));
        unset($raw['crm_line_status']);
        $line->quantity = $displayQty;
        $line->fulfilled_quantity = 0;
        $line->fulfillable_quantity = 0;
        $line->raw_json = $raw;
        $line->save();
        $this->setFoRemainingForLine($order, $line, 0);
    }

    private function markLineBackorder(ShopifyOrder $order, ShopifyOrderLineItem $line): void
    {
        $raw = is_array($line->raw_json) ? $line->raw_json : [];
        $qty = max(1, (int) $line->quantity, (int) ($raw['crm_original_quantity'] ?? 0));
        $fulfilled = max(0, (int) $line->fulfilled_quantity);
        if ($fulfilled > $qty) {
            $fulfilled = $qty;
        }
        unset($raw['crm_line_cancelled']);
        $raw['crm_line_status'] = 'backorder';
        $raw['crm_original_quantity'] = $qty;
        $line->quantity = $qty;
        $line->fulfilled_quantity = $fulfilled;
        $line->fulfillable_quantity = max(0, $qty - $fulfilled);
        $line->raw_json = $raw;
        $line->save();
        $this->setFoRemainingForLine($order, $line, (int) $line->fulfillable_quantity);
    }

    private function prepareLineForFulfill(ShopifyOrder $order, ShopifyOrderLineItem $line): void
    {
        $raw = is_array($line->raw_json) ? $line->raw_json : [];
        $qty = max(1, (int) $line->quantity, (int) ($raw['crm_original_quantity'] ?? 0));
        $fulfilled = max(0, (int) $line->fulfilled_quantity);
        if ($fulfilled >= $qty && $this->list->rawLineStatus($line) === 'fulfilled') {
            throw new RuntimeException('This item is already fulfilled.');
        }
        unset($raw['crm_line_cancelled'], $raw['crm_line_status']);
        $raw['crm_original_quantity'] = $qty;
        $line->quantity = $qty;
        $line->fulfilled_quantity = min($fulfilled, $qty);
        $line->fulfillable_quantity = max(0, $qty - (int) $line->fulfilled_quantity);
        $line->raw_json = $raw;
        $line->save();
        $this->setFoRemainingForLine($order, $line, (int) $line->fulfillable_quantity);
    }

    private function clearLineStatusFlag(ShopifyOrder $order, int $lineId): void
    {
        $order->loadMissing('lineItems');
        $line = $order->lineItems->firstWhere('id', $lineId);
        if ($line === null) {
            return;
        }
        $raw = is_array($line->raw_json) ? $line->raw_json : [];
        if (! isset($raw['crm_line_status']) && empty($raw['crm_line_cancelled'])) {
            return;
        }
        unset($raw['crm_line_status'], $raw['crm_line_cancelled']);
        $line->raw_json = $raw;
        $line->save();
    }

    private function setFoRemainingForLine(ShopifyOrder $order, ShopifyOrderLineItem $line, int $remaining): void
    {
        $order->loadMissing('fulfillmentOrders.lineItems');
        foreach ($order->fulfillmentOrders as $fo) {
            foreach ($fo->lineItems as $foLine) {
                $matchesLocal = (int) ($foLine->shopify_order_line_item_id ?? 0) === (int) $line->id;
                $sid = trim((string) ($foLine->shopify_line_item_id ?? ''));
                $matchesShopify = $sid !== '' && $sid === trim((string) ($line->shopify_line_item_id ?? ''));
                if (! $matchesLocal && ! $matchesShopify) {
                    continue;
                }
                $foLine->remaining_quantity = max(0, $remaining);
                $foLine->save();
            }
        }
    }

    /**
     * Roll item statuses up to the order: all cancelled, all fulfilled, or any backorder.
     */
    private function syncOrderStatusFromLines(ShopifyOrder $order): ShopifyOrder
    {
        $order = $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems']) ?? $order;
        $statuses = [];
        foreach ($order->lineItems as $line) {
            $statuses[] = $this->list->lineDisplayStatus($order, $line);
        }

        $allCancelled = $statuses !== [] && count(array_filter($statuses, static function ($status) {
            return $status !== 'cancelled';
        })) === 0;
        $allFulfilled = $statuses !== [] && count(array_filter($statuses, static function ($status) {
            return $status !== 'fulfilled';
        })) === 0;
        $anyBackorder = in_array('backorder', $statuses, true);

        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        if ($allCancelled) {
            if ($order->crm_fulfillment_cancelled_at === null) {
                $order->crm_fulfillment_cancelled_at = now();
            }
            $order->crm_hold_reasons = [];
            if (strtolower(trim((string) ($raw['crm_display_hint'] ?? ''))) === 'backorder') {
                unset($raw['crm_display_hint']);
            }
            $status = strtolower(trim((string) $order->fulfillment_status));
            if ($status === 'fulfilled') {
                $order->fulfillment_status = 'unfulfilled';
            }
        } elseif ($allFulfilled) {
            $order->fulfillment_status = 'fulfilled';
            $order->crm_fulfillment_cancelled_at = null;
            $order->cancelled_at = null;
            $order->crm_hold_reasons = [];
            $raw['crm_ignore_shopify_cancel'] = true;
            if (strtolower(trim((string) ($raw['crm_display_hint'] ?? ''))) === 'backorder') {
                unset($raw['crm_display_hint']);
            }
        } elseif ($anyBackorder) {
            $order->crm_fulfillment_cancelled_at = null;
            $order->cancelled_at = null;
            $order->crm_hold_reasons = [];
            $raw['crm_ignore_shopify_cancel'] = true;
            $raw['crm_display_hint'] = 'backorder';
            $status = strtolower(trim((string) $order->fulfillment_status));
            if ($status === '' || $status === 'fulfilled') {
                $order->fulfillment_status = 'partial';
            }
        } else {
            if ($order->crm_fulfillment_cancelled_at !== null && ! $allCancelled) {
                $order->crm_fulfillment_cancelled_at = null;
                $order->cancelled_at = null;
                $raw['crm_ignore_shopify_cancel'] = true;
            }
            if (strtolower(trim((string) ($raw['crm_display_hint'] ?? ''))) === 'backorder') {
                $raw['crm_display_hint'] = 'ready_to_ship';
            }
        }

        $order->raw_json = $raw;
        $order->save();

        return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
    }
}
