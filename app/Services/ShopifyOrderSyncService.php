<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyFulfillmentOrder;
use App\Models\ShopifyFulfillmentOrderLineItem;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderLineItem;
use App\Support\ShopifyError;
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
        try {
            return $this->importOpenOrdersGraphql($connection, $api);
        } catch (Throwable $e) {
            if (! ShopifyError::isProtectedOrderAccess($e->getMessage())) {
                throw $e;
            }
            Log::warning('shopify.import.orders_graphql_denied_rest_fallback', [
                'connection_id' => $connection->id,
                'message' => $e->getMessage(),
            ]);

            return $this->importOpenOrdersRest($connection, $api);
        }
    }

    private function importOpenOrdersGraphql(ClientAccountShopifyConnection $connection, ShopifyClient $api): int
    {
        $count = 0;
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $data = $api->graphql(
                <<<'GQL'
query OpenOrders($cursor: String) {
  orders(first: 25, after: $cursor, query: "status:open", sortKey: CREATED_AT, reverse: true) {
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

            $pageInfo = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $cursor = ShopifyClient::nextPageCursor($cursor, $pageInfo, $page, 20);
        } while ($cursor !== null);

        return $count;
    }

    private function importOpenOrdersRest(ClientAccountShopifyConnection $connection, ShopifyClient $api): int
    {
        $count = 0;
        $pageInfo = null;
        for ($page = 1; $page <= 8; $page++) {
            $query = [
                'status' => 'open',
                'limit' => 50,
            ];
            if (is_string($pageInfo) && $pageInfo !== '') {
                $query = [
                    'limit' => 50,
                    'page_info' => $pageInfo,
                ];
            }
            $response = $api->restGet('orders.json', $query);
            $orders = is_array($response['json']['orders'] ?? null) ? $response['json']['orders'] : [];
            foreach ($orders as $order) {
                if (! is_array($order)) {
                    continue;
                }
                if ($this->upsertOrderFromShopifyNode($connection, $order)) {
                    $count++;
                }
            }
            $pageInfo = ShopifyClient::nextRestPageInfo($response['link'] ?? null);
            if ($pageInfo === null) {
                break;
            }
        }

        return $count;
    }

    /**
     * Re-sync all unfulfilled / partially fulfilled orders (manual CRM action).
     */
    public function syncUnfulfilledOrders(ClientAccountShopifyConnection $connection, ?ShopifyClient $api = null): int
    {
        $count = $this->importOpenOrders($connection, $api);
        $connection->last_order_sync_at = now();
        $connection->save();

        return $count;
    }

    /**
     * Re-sync orders created on/after the given date (UTC day). Hard-capped for safety.
     */
    public function syncOrdersCreatedAfter(
        ClientAccountShopifyConnection $connection,
        Carbon $fromDate,
        ?ShopifyClient $api = null,
        int $maxOrders = 500
    ): int {
        $api = $api ?? $this->client->forConnection($connection);
        $maxOrders = max(1, min(1000, $maxOrders));
        $day = $fromDate->copy()->utc()->startOfDay()->toDateString();
        $query = 'created_at:>='.$day;
        $count = 0;
        $cursor = null;
        $page = 0;

        do {
            $page++;
            $data = $api->graphql(
                <<<'GQL'
query OrdersAfterDate($q: String!, $cursor: String) {
  orders(first: 25, after: $cursor, query: $q, sortKey: CREATED_AT, reverse: false) {
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
                if ($count >= $maxOrders) {
                    break 2;
                }
                $id = ShopifyGid::toId((string) (($edge['node']['id'] ?? '') ?: ''));
                if ($id === '') {
                    continue;
                }
                if ($this->refreshOrderByShopifyId($connection, $id) !== null) {
                    $count++;
                }
            }
            $pageInfo = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $cursor = ShopifyClient::nextPageCursor($cursor, $pageInfo, $page, 40);
        } while ($cursor !== null && $count < $maxOrders);

        $connection->last_order_sync_at = now();
        $connection->save();

        return $count;
    }

    /**
     * Resolve a Shopify order by storefront name (#1234 / 1234) and refresh it.
     *
     * @throws \RuntimeException when the order cannot be found
     */
    public function syncOrderByName(
        ClientAccountShopifyConnection $connection,
        string $orderName,
        ?ShopifyClient $api = null
    ): ShopifyOrder {
        $api = $api ?? $this->client->forConnection($connection);
        $normalized = $this->normalizeOrderName($orderName);
        if ($normalized === '') {
            throw new \RuntimeException('Order number is required.');
        }

        $query = 'name:'.$normalized;
        try {
            $data = $api->graphql(
                <<<'GQL'
query OrderByName($q: String!) {
  orders(first: 5, query: $q, sortKey: CREATED_AT, reverse: true) {
    edges { node { id name } }
  }
}
GQL
                ,
                ['q' => $query]
            );
        } catch (Throwable $e) {
            if (! ShopifyError::isProtectedOrderAccess($e->getMessage())) {
                throw $e;
            }
            $rest = $api->restGet('orders.json', [
                'name' => $normalized,
                'status' => 'any',
                'limit' => 5,
            ]);
            $orders = is_array($rest['json']['orders'] ?? null) ? $rest['json']['orders'] : [];
            $matchId = '';
            foreach ($orders as $order) {
                if (! is_array($order)) {
                    continue;
                }
                $name = $this->normalizeOrderName((string) ($order['name'] ?? ''));
                if ($name === $normalized) {
                    $matchId = $this->extractShopifyOrderId($order);
                    break;
                }
            }
            if ($matchId === '' && $orders !== [] && is_array($orders[0])) {
                $matchId = $this->extractShopifyOrderId($orders[0]);
            }
            if ($matchId === '') {
                throw new \RuntimeException('No Shopify order found for '.$normalized.'.');
            }
            $order = $this->refreshOrderByShopifyId($connection, $matchId);
            if ($order === null) {
                throw new \RuntimeException('Could not load Shopify order '.$normalized.'.');
            }
            $connection->last_order_sync_at = now();
            $connection->save();

            return $order;
        }

        $edges = is_array($data['orders']['edges'] ?? null) ? $data['orders']['edges'] : [];
        $matchId = '';
        foreach ($edges as $edge) {
            $node = is_array($edge['node'] ?? null) ? $edge['node'] : [];
            $name = trim((string) ($node['name'] ?? ''));
            if ($this->normalizeOrderName($name) === $normalized) {
                $matchId = ShopifyGid::toId((string) ($node['id'] ?? ''));
                break;
            }
        }
        if ($matchId === '' && $edges !== []) {
            $first = is_array($edges[0]['node'] ?? null) ? $edges[0]['node'] : [];
            $matchId = ShopifyGid::toId((string) ($first['id'] ?? ''));
        }
        if ($matchId === '') {
            throw new \RuntimeException('No Shopify order found for '.$normalized.'.');
        }

        $order = $this->refreshOrderByShopifyId($connection, $matchId);
        if ($order === null) {
            throw new \RuntimeException('Could not refresh Shopify order '.$normalized.'.');
        }

        $connection->last_order_sync_at = now();
        $connection->save();

        return $order;
    }

    public function normalizeOrderName(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        $s = ltrim($s, '#');
        $s = trim($s);
        if ($s === '') {
            return '';
        }

        return '#'.$s;
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

        $node = null;
        $data = [];

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
            $node = $this->fetchOrderRestById($api, $shopifyOrderId);
            if ($node === null) {
                return null;
            }
        }

        if (! isset($node) || ! is_array($node)) {
            $node = is_array($data['order'] ?? null) ? $data['order'] : null;
        }
        if ($node === null) {
            $node = $this->fetchOrderRestById($api, $shopifyOrderId);
        }
        if ($node === null) {
            return null;
        }

        $this->upsertOrderFromShopifyNode($connection, $node);
        $savedId = $this->extractShopifyOrderId($node);

        return ShopifyOrder::query()
            ->where('connection_id', $connection->id)
            ->where('shopify_order_id', $savedId)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOrderRestById(ShopifyClient $api, string $shopifyOrderId): ?array
    {
        $id = ShopifyGid::toId($shopifyOrderId);
        if ($id === '') {
            return null;
        }
        try {
            $response = $api->restGet('orders/'.$id.'.json');
        } catch (Throwable $e) {
            Log::warning('shopify.order.rest_refresh_failed', [
                'order_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
        $order = $response['json']['order'] ?? null;

        return is_array($order) ? $order : null;
    }

    /**
     * REST / GraphQL webhook order payloads (create, update, edited, cancelled).
     * Prefers a GraphQL refresh; falls back to the REST body if GraphQL is denied.
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

        // Order edits are not reflected in REST line_items; prefer GraphQL when it works.
        $fresh = $this->refreshOrderByShopifyId($connection, $orderId, 3);
        if ($fresh !== null) {
            return $fresh;
        }

        // GraphQL may fail (protected customer data / token). REST webhook payload is still usable.
        $restNode = is_array($payload['order'] ?? null) ? $payload['order'] : $payload;
        if ($this->upsertOrderFromShopifyNode($connection, $restNode)) {
            Log::warning('shopify.order.webhook_rest_fallback', [
                'connection_id' => $connection->id,
                'order_id' => $orderId,
                'topic' => $topic,
            ]);

            return ShopifyOrder::query()
                ->where('connection_id', $connection->id)
                ->where('shopify_order_id', $orderId)
                ->first();
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
        $orderId = $this->extractShopifyOrderId($node);
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
                $lineId = ShopifyGid::toId((string) ($lineNode['admin_graphql_api_id'] ?? $lineNode['id'] ?? ''));
                if ($lineId === '') {
                    $lineId = ShopifyGid::numericIdString($lineNode['id'] ?? null);
                }
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
                        'shopify_variant_id' => ShopifyGid::toId((string) (($lineNode['variant']['id'] ?? $lineNode['variant_id'] ?? '') ?: '')),
                        'shopify_product_id' => ShopifyGid::toId((string) (($lineNode['product']['id'] ?? $lineNode['product_id'] ?? '') ?: '')),
                        'sku' => (string) ($lineNode['sku'] ?? ''),
                        'title' => (string) ($lineNode['title'] ?? ''),
                        'variant_title' => (string) ($lineNode['variantTitle'] ?? $lineNode['variant_title'] ?? ''),
                        'quantity' => $qty,
                        'fulfillable_quantity' => $unfulfilled,
                        'fulfilled_quantity' => $fulfilled,
                        'price' => isset($lineNode['originalUnitPriceSet']['shopMoney']['amount'])
                            ? (float) $lineNode['originalUnitPriceSet']['shopMoney']['amount']
                            : (isset($lineNode['price']) ? (float) $lineNode['price'] : null),
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
        try {
            $count = $this->syncRecentlyUpdatedGraphql($connection, $api, $minutes);
        } catch (Throwable $e) {
            if (! ShopifyError::isProtectedOrderAccess($e->getMessage())) {
                throw $e;
            }
            $count = $this->syncRecentlyUpdatedRest($connection, $api, $minutes);
        }

        $connection->last_order_sync_at = now();
        $connection->save();

        return $count;
    }

    private function syncRecentlyUpdatedGraphql(
        ClientAccountShopifyConnection $connection,
        ShopifyClient $api,
        int $minutes
    ): int {
        $from = now('UTC')->subMinutes($minutes)->toIso8601String();
        $query = 'updated_at:>='.$from;
        $count = 0;
        $cursor = null;
        $page = 0;

        do {
            $page++;
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
            $pageInfo = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $cursor = ShopifyClient::nextPageCursor($cursor, $pageInfo, $page, 20);
        } while ($cursor !== null && $count < 200);

        return $count;
    }

    private function syncRecentlyUpdatedRest(
        ClientAccountShopifyConnection $connection,
        ShopifyClient $api,
        int $minutes
    ): int {
        $from = now('UTC')->subMinutes($minutes)->toIso8601String();
        $count = 0;
        $pageInfo = null;
        for ($page = 1; $page <= 8; $page++) {
            $query = [
                'status' => 'any',
                'limit' => 50,
                'updated_at_min' => $from,
            ];
            if (is_string($pageInfo) && $pageInfo !== '') {
                $query = [
                    'limit' => 50,
                    'page_info' => $pageInfo,
                ];
            }
            $response = $api->restGet('orders.json', $query);
            $orders = is_array($response['json']['orders'] ?? null) ? $response['json']['orders'] : [];
            foreach ($orders as $order) {
                if (! is_array($order)) {
                    continue;
                }
                if ($this->upsertOrderFromShopifyNode($connection, $order)) {
                    $count++;
                }
                if ($count >= 200) {
                    return $count;
                }
            }
            $pageInfo = ShopifyClient::nextRestPageInfo($response['link'] ?? null);
            if ($pageInfo === null) {
                break;
            }
        }

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
