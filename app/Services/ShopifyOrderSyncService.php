<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyFulfillmentOrder;
use App\Models\ShopifyFulfillmentOrderLineItem;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderLineItem;
use App\Support\ShopifyGid;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyOrderSyncService
{
    /** @var ShopifyClient */
    private $client;

    public function __construct(ShopifyClient $client)
    {
        $this->client = $client;
    }

    public function importOpenOrders(ClientAccountShopifyConnection $connection, ?ShopifyClient $api = null): int
    {
        $api = $api ?? $this->client->forConnection($connection);
        $count = 0;
        $cursor = null;

        do {
            $data = $api->graphql(
                <<<'GQL'
query OpenOrders($cursor: String) {
  orders(first: 25, after: $cursor, query: "fulfillment_status:unfulfilled OR fulfillment_status:partial") {
    pageInfo { hasNextPage endCursor }
    edges {
      node {
        id
        name
        email
        displayFinancialStatus
        displayFulfillmentStatus
        currencyCode
        totalPriceSet { shopMoney { amount } }
        createdAt
        updatedAt
        cancelledAt
        shippingAddress {
          name firstName lastName address1 address2 city province country zip phone
        }
        lineItems(first: 100) {
          edges {
            node {
              id
              sku
              title
              variantTitle
              quantity
              currentQuantity
              unfulfilledQuantity
              originalUnitPriceSet { shopMoney { amount } }
              variant { id }
              product { id }
            }
          }
        }
        fulfillmentOrders(first: 20) {
          edges {
            node {
              id
              status
              requestStatus
              assignedLocation { location { id } }
              lineItems(first: 100) {
                edges {
                  node {
                    id
                    totalQuantity
                    remainingQuantity
                    lineItem { id }
                  }
                }
              }
            }
          }
        }
      }
    }
  }
}
GQL
                ,
                ['cursor' => $cursor]
            );

            $conn = is_array($data['orders'] ?? null) ? $data['orders'] : [];
            foreach (($conn['edges'] ?? []) as $edge) {
                $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($node === null) {
                    continue;
                }
                if ($this->upsertOrderFromShopifyNode($connection, $node)) {
                    $count++;
                }
            }

            $page = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $hasNext = (bool) ($page['hasNextPage'] ?? false);
            $cursor = $hasNext ? ($page['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return $count;
    }

    /**
     * Fetch a single order by Shopify ID (numeric or GID) and upsert.
     */
    public function refreshOrderByShopifyId(
        ClientAccountShopifyConnection $connection,
        string $shopifyOrderId,
        int $attempts = 1
    ): ?ShopifyOrder {
        $attempts = max(1, min(5, $attempts));
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                sleep(min(2, $i));
            }
            $last = $this->refreshOrderByShopifyIdOnce($connection, $shopifyOrderId);
            if ($last !== null) {
                return $last;
            }
        }

        return $last;
    }

    public function refreshOrderByShopifyIdOnce(ClientAccountShopifyConnection $connection, string $shopifyOrderId): ?ShopifyOrder
    {
        $api = $this->client->forConnection($connection);
        $gid = str_starts_with($shopifyOrderId, 'gid://')
            ? $shopifyOrderId
            : ShopifyGid::of('Order', $shopifyOrderId);

        try {
            $data = $api->graphql(
                <<<'GQL'
query OrderById($id: ID!) {
  order(id: $id) {
    id
    name
    email
    displayFinancialStatus
    displayFulfillmentStatus
    currencyCode
    totalPriceSet { shopMoney { amount } }
    createdAt
    updatedAt
    cancelledAt
    shippingAddress {
      name firstName lastName address1 address2 city province country zip phone
    }
    lineItems(first: 100) {
      edges {
        node {
          id
          sku
          title
          variantTitle
          quantity
          currentQuantity
          unfulfilledQuantity
          originalUnitPriceSet { shopMoney { amount } }
          variant { id }
          product { id }
        }
      }
    }
    fulfillmentOrders(first: 20) {
      edges {
        node {
          id
          status
          requestStatus
          assignedLocation { location { id } }
          lineItems(first: 100) {
            edges {
              node {
                id
                totalQuantity
                remainingQuantity
                lineItem { id }
              }
            }
          }
        }
      }
    }
  }
}
GQL
                ,
                ['id' => $gid]
            );
        } catch (Throwable $e) {
            Log::warning('shopify.order.refresh_failed', [
                'connection_id' => $connection->id,
                'order_id' => $shopifyOrderId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $node = is_array($data['order'] ?? null) ? $data['order'] : null;
        if ($node === null) {
            return null;
        }

        $this->upsertOrderFromShopifyNode($connection, $node);

        return ShopifyOrder::query()
            ->where('connection_id', $connection->id)
            ->where('shopify_order_id', ShopifyGid::toId((string) ($node['id'] ?? '')))
            ->first();
    }

    /**
     * REST / GraphQL webhook order payloads (create, update, edited, cancelled).
     * Always refreshes via GraphQL — REST order.line_items ignore Admin order edits.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsertOrderFromWebhookPayload(
        ClientAccountShopifyConnection $connection,
        array $payload,
        string $topic = ''
    ): ?ShopifyOrder {
        $orderId = $this->extractShopifyOrderId($payload);
        if ($orderId === '') {
            throw new \RuntimeException(
                'Shopify order webhook missing order id (topic='.($topic !== '' ? $topic : 'unknown').').'
            );
        }

        if ($this->topicLooksLikeDelete($topic)) {
            $this->deleteOrderByShopifyId($connection, $orderId);

            return null;
        }

        // Order edits are not reflected in REST line_items; retry GraphQL so Shopify has committed the edit.
        $fresh = $this->refreshOrderByShopifyId($connection, $orderId, 3);
        if ($fresh !== null) {
            return $fresh;
        }

        throw new \RuntimeException(
            'Shopify GraphQL refresh failed for order '.$orderId.' (topic='.($topic !== '' ? $topic : 'unknown').').'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractShopifyOrderId(array $payload): string
    {
        $edit = is_array($payload['order_edit'] ?? null) ? $payload['order_edit'] : null;
        if (is_array($edit)) {
            $fromEdit = ShopifyGid::toId((string) ($edit['order_id'] ?? ''));
            if ($fromEdit !== '') {
                return $fromEdit;
            }
        }

        $nested = is_array($payload['order'] ?? null) ? $payload['order'] : null;
        if (is_array($nested)) {
            $fromNested = ShopifyGid::toId((string) ($nested['admin_graphql_api_id'] ?? $nested['id'] ?? ''));
            if ($fromNested !== '') {
                return $fromNested;
            }
        }

        foreach (['admin_graphql_api_id', 'order_id', 'id'] as $key) {
            if (! isset($payload[$key]) || is_array($payload[$key])) {
                continue;
            }
            $id = ShopifyGid::toId((string) $payload[$key]);
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    public function topicLooksLikeDelete(string $topic): bool
    {
        $topic = strtolower(str_replace('_', '/', trim($topic)));

        return $topic === 'orders/delete';
    }

    public function deleteOrderByShopifyId(ClientAccountShopifyConnection $connection, string $shopifyOrderId): bool
    {
        $orderId = ShopifyGid::toId($shopifyOrderId);
        if ($orderId === '') {
            return false;
        }

        $order = ShopifyOrder::query()
            ->where('connection_id', $connection->id)
            ->where('shopify_order_id', $orderId)
            ->first();
        if ($order === null) {
            return false;
        }

        $order->delete();

        return true;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function upsertOrderFromShopifyNode(ClientAccountShopifyConnection $connection, array $node): bool
    {
        $orderId = ShopifyGid::toId((string) ($node['id'] ?? ''));
        if ($orderId === '') {
            return false;
        }

        return DB::transaction(function () use ($connection, $node, $orderId) {
            $total = $node['totalPriceSet']['shopMoney']['amount'] ?? $node['total_price'] ?? null;

            /** @var ShopifyOrder $order */
            $order = ShopifyOrder::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'shopify_order_id' => $orderId,
                ],
                [
                    'name' => (string) ($node['name'] ?? ''),
                    'email' => (string) ($node['email'] ?? ''),
                    'financial_status' => strtolower((string) ($node['displayFinancialStatus'] ?? $node['financial_status'] ?? '')),
                    'fulfillment_status' => strtolower((string) ($node['displayFulfillmentStatus'] ?? $node['fulfillment_status'] ?? 'unfulfilled')),
                    'currency' => (string) ($node['currencyCode'] ?? $node['currency'] ?? ''),
                    'total_price' => $total !== null && $total !== '' ? (float) $total : null,
                    'shopify_created_at' => $this->parseTime($node['createdAt'] ?? $node['created_at'] ?? null),
                    'shopify_updated_at' => $this->parseTime($node['updatedAt'] ?? $node['updated_at'] ?? null),
                    'cancelled_at' => $this->parseTime($node['cancelledAt'] ?? $node['cancelled_at'] ?? null),
                    'customer_json' => $this->customerJsonFromOrderNode($node),
                    'shipping_address_json' => is_array($node['shippingAddress'] ?? $node['shipping_address'] ?? null)
                        ? ($node['shippingAddress'] ?? $node['shipping_address'])
                        : null,
                    'payload_hash' => hash('sha256', json_encode($node)),
                    'raw_json' => $node,
                ]
            );

            $lineNodes = $this->extractLineItemNodes($node);
            $hasLineSnapshot = array_key_exists('lineItems', $node) || array_key_exists('line_items', $node);

            $seenLineIds = [];
            foreach ($lineNodes as $lineNode) {
                $lineId = ShopifyGid::toId((string) ($lineNode['id'] ?? ''));
                if ($lineId === '') {
                    continue;
                }
                $seenLineIds[] = $lineId;
                // Order edits leave original `quantity` unchanged; currentQuantity is the live qty.
                $qty = (int) ($lineNode['currentQuantity']
                    ?? $lineNode['current_quantity']
                    ?? $lineNode['quantity']
                    ?? 0);
                $unfulfilled = (int) ($lineNode['unfulfilledQuantity'] ?? $lineNode['fulfillable_quantity'] ?? $qty);
                $fulfilled = max(0, $qty - $unfulfilled);
                ShopifyOrderLineItem::query()->updateOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'shopify_line_item_id' => $lineId,
                    ],
                    [
                        'shopify_order_id' => $order->id,
                        'shopify_variant_id' => ShopifyGid::toId((string) (($lineNode['variant']['id'] ?? '') ?: '')),
                        'shopify_product_id' => ShopifyGid::toId((string) (($lineNode['product']['id'] ?? '') ?: '')),
                        'sku' => (string) ($lineNode['sku'] ?? ''),
                        'title' => (string) ($lineNode['title'] ?? ''),
                        'variant_title' => (string) ($lineNode['variantTitle'] ?? $lineNode['variant_title'] ?? ''),
                        'quantity' => $qty,
                        'fulfillable_quantity' => $unfulfilled,
                        'fulfilled_quantity' => $fulfilled,
                        'price' => isset($lineNode['originalUnitPriceSet']['shopMoney']['amount'])
                            ? (float) $lineNode['originalUnitPriceSet']['shopMoney']['amount']
                            : null,
                        'raw_json' => $lineNode,
                    ]
                );
            }

            if ($hasLineSnapshot) {
                $prune = ShopifyOrderLineItem::query()
                    ->where('connection_id', $connection->id)
                    ->where('shopify_order_id', $order->id);
                if ($seenLineIds !== []) {
                    $prune->whereNotIn('shopify_line_item_id', $seenLineIds);
                }
                $prune->delete();
            }

            $this->syncFulfillmentOrders($connection, $order, $node);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function extractLineItemNodes(array $node): array
    {
        $lineEdges = $node['lineItems']['edges'] ?? null;
        if (is_array($lineEdges)) {
            $out = [];
            foreach ($lineEdges as $edge) {
                if (is_array($edge['node'] ?? null)) {
                    $out[] = $edge['node'];
                }
            }

            return $out;
        }

        if (isset($node['line_items']) && is_array($node['line_items'])) {
            $out = [];
            foreach ($node['line_items'] as $line) {
                if (is_array($line)) {
                    $out[] = $line;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $orderNode
     */
    private function syncFulfillmentOrders(
        ClientAccountShopifyConnection $connection,
        ShopifyOrder $order,
        array $orderNode
    ): void {
        $foEdges = $orderNode['fulfillmentOrders']['edges'] ?? null;
        if (! is_array($foEdges)) {
            return;
        }

        $seenFoIds = [];
        foreach ($foEdges as $edge) {
            $fo = is_array($edge['node'] ?? null) ? $edge['node'] : null;
            if ($fo === null) {
                continue;
            }
            $foId = ShopifyGid::toId((string) ($fo['id'] ?? ''));
            if ($foId === '') {
                continue;
            }
            $locationId = ShopifyGid::toId((string) (($fo['assignedLocation']['location']['id'] ?? '') ?: ''));

            $seenFoIds[] = $foId;
            $foRow = ShopifyFulfillmentOrder::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'shopify_fulfillment_order_id' => $foId,
                ],
                [
                    'shopify_order_id' => $order->id,
                    'status' => strtolower((string) ($fo['status'] ?? '')),
                    'request_status' => strtolower((string) ($fo['requestStatus'] ?? '')),
                    'shopify_location_id' => $locationId !== '' ? $locationId : null,
                    'raw_json' => $fo,
                ]
            );

            $seenFoLineIds = [];
            foreach (($fo['lineItems']['edges'] ?? []) as $lineEdge) {
                $line = is_array($lineEdge['node'] ?? null) ? $lineEdge['node'] : null;
                if ($line === null) {
                    continue;
                }
                $foLineId = ShopifyGid::toId((string) ($line['id'] ?? ''));
                if ($foLineId === '') {
                    continue;
                }
                $orderLineShopifyId = ShopifyGid::toId((string) (($line['lineItem']['id'] ?? '') ?: ''));
                $orderLineRowId = null;
                if ($orderLineShopifyId !== '') {
                    $orderLineRowId = ShopifyOrderLineItem::query()
                        ->where('connection_id', $connection->id)
                        ->where('shopify_line_item_id', $orderLineShopifyId)
                        ->value('id');
                }

                $seenFoLineIds[] = $foLineId;
                ShopifyFulfillmentOrderLineItem::query()->updateOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'shopify_fo_line_item_id' => $foLineId,
                    ],
                    [
                        'shopify_fulfillment_order_id' => $foRow->id,
                        'shopify_order_line_item_id' => $orderLineRowId,
                        'shopify_line_item_id' => $orderLineShopifyId !== '' ? $orderLineShopifyId : null,
                        'total_quantity' => (int) ($line['totalQuantity'] ?? 0),
                        'remaining_quantity' => (int) ($line['remainingQuantity'] ?? 0),
                        'raw_json' => $line,
                    ]
                );
            }

            $foLinePrune = ShopifyFulfillmentOrderLineItem::query()
                ->where('connection_id', $connection->id)
                ->where('shopify_fulfillment_order_id', $foRow->id);
            if ($seenFoLineIds !== []) {
                $foLinePrune->whereNotIn('shopify_fo_line_item_id', $seenFoLineIds);
            }
            $foLinePrune->delete();
        }

        $foPrune = ShopifyFulfillmentOrder::query()
            ->where('connection_id', $connection->id)
            ->where('shopify_order_id', $order->id);
        if ($seenFoIds !== []) {
            $foPrune->whereNotIn('shopify_fulfillment_order_id', $seenFoIds);
        }
        $foPrune->delete();
    }

    /**
     * Reconcile recently updated orders (backup for missed webhooks).
     */
    public function syncRecentlyUpdated(ClientAccountShopifyConnection $connection, int $minutes = 15): int
    {
        $api = $this->client->forConnection($connection);
        $minutes = max(5, min(120, $minutes));
        $from = now('UTC')->subMinutes($minutes)->toIso8601String();
        $query = 'updated_at:>='.$from;
        $count = 0;
        $cursor = null;

        do {
            $data = $api->graphql(
                <<<'GQL'
query RecentOrders($q: String!, $cursor: String) {
  orders(first: 25, after: $cursor, query: $q, sortKey: UPDATED_AT, reverse: true) {
    pageInfo { hasNextPage endCursor }
    edges { node { id } }
  }
}
GQL
                ,
                ['q' => $query, 'cursor' => $cursor]
            );
            $conn = is_array($data['orders'] ?? null) ? $data['orders'] : [];
            foreach (($conn['edges'] ?? []) as $edge) {
                $id = ShopifyGid::toId((string) (($edge['node']['id'] ?? '') ?: ''));
                if ($id === '') {
                    continue;
                }
                if ($this->refreshOrderByShopifyId($connection, $id) !== null) {
                    $count++;
                }
            }
            $page = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $hasNext = (bool) ($page['hasNextPage'] ?? false);
            $cursor = $hasNext ? ($page['endCursor'] ?? null) : null;
        } while ($cursor !== null && $count < 200);

        $connection->last_order_sync_at = now();
        $connection->save();

        return $count;
    }

    /**
     * Prefer explicit customer payload when present (e.g. webhooks / future read_customers).
     * Otherwise build a minimal blob from order email + shipping address (no read_customers needed).
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function customerJsonFromOrderNode(array $node): ?array
    {
        if (is_array($node['customer'] ?? null)) {
            return $node['customer'];
        }

        $shipping = $node['shippingAddress'] ?? $node['shipping_address'] ?? null;
        $email = trim((string) ($node['email'] ?? ''));
        if (! is_array($shipping) && $email === '') {
            return null;
        }

        $first = is_array($shipping) ? trim((string) ($shipping['firstName'] ?? $shipping['first_name'] ?? '')) : '';
        $last = is_array($shipping) ? trim((string) ($shipping['lastName'] ?? $shipping['last_name'] ?? '')) : '';
        $name = is_array($shipping) ? trim((string) ($shipping['name'] ?? '')) : '';
        if ($name === '') {
            $name = trim($first.' '.$last);
        }

        $out = array_filter([
            'email' => $email !== '' ? $email : null,
            'first_name' => $first !== '' ? $first : null,
            'last_name' => $last !== '' ? $last : null,
            'display_name' => $name !== '' ? $name : null,
            'phone' => is_array($shipping) && trim((string) ($shipping['phone'] ?? '')) !== ''
                ? trim((string) $shipping['phone'])
                : null,
        ], static function ($v) {
            return $v !== null && $v !== '';
        });

        return $out === [] ? null : $out;
    }

    private function parseTime($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value);
        } catch (Throwable $e) {
            return null;
        }
    }
}
