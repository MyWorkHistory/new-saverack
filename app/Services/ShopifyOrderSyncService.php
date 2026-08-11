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
        customer { firstName lastName email phone }
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
    public function refreshOrderByShopifyId(ClientAccountShopifyConnection $connection, string $shopifyOrderId): ?ShopifyOrder
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
    customer { firstName lastName email phone }
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
     * REST webhook order payloads.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsertOrderFromWebhookPayload(ClientAccountShopifyConnection $connection, array $payload): ?ShopifyOrder
    {
        $orderId = ShopifyGid::toId((string) ($payload['admin_graphql_api_id'] ?? $payload['id'] ?? ''));
        if ($orderId === '') {
            return null;
        }

        // Prefer a GraphQL refresh so FOs stay accurate.
        $fresh = $this->refreshOrderByShopifyId($connection, $orderId);
        if ($fresh !== null) {
            return $fresh;
        }

        // Fallback: map REST fields when GraphQL refresh fails.
        return $this->upsertOrderFromRestPayload($connection, $payload);
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
                    'customer_json' => is_array($node['customer'] ?? null) ? $node['customer'] : null,
                    'shipping_address_json' => is_array($node['shippingAddress'] ?? $node['shipping_address'] ?? null)
                        ? ($node['shippingAddress'] ?? $node['shipping_address'])
                        : null,
                    'payload_hash' => hash('sha256', json_encode($node)),
                    'raw_json' => $node,
                ]
            );

            $lineEdges = $node['lineItems']['edges'] ?? null;
            $lineNodes = [];
            if (is_array($lineEdges)) {
                foreach ($lineEdges as $edge) {
                    if (is_array($edge['node'] ?? null)) {
                        $lineNodes[] = $edge['node'];
                    }
                }
            }

            $seenLineIds = [];
            foreach ($lineNodes as $lineNode) {
                $lineId = ShopifyGid::toId((string) ($lineNode['id'] ?? ''));
                if ($lineId === '') {
                    continue;
                }
                $seenLineIds[] = $lineId;
                $qty = (int) ($lineNode['quantity'] ?? 0);
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

            $this->syncFulfillmentOrders($connection, $order, $node);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertOrderFromRestPayload(ClientAccountShopifyConnection $connection, array $payload): ?ShopifyOrder
    {
        $orderId = ShopifyGid::toId((string) ($payload['id'] ?? ''));
        if ($orderId === '') {
            return null;
        }

        $order = ShopifyOrder::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'shopify_order_id' => $orderId,
            ],
            [
                'name' => (string) ($payload['name'] ?? ''),
                'email' => (string) ($payload['email'] ?? ''),
                'financial_status' => strtolower((string) ($payload['financial_status'] ?? '')),
                'fulfillment_status' => strtolower((string) ($payload['fulfillment_status'] ?? 'unfulfilled')),
                'currency' => (string) ($payload['currency'] ?? ''),
                'total_price' => isset($payload['total_price']) ? (float) $payload['total_price'] : null,
                'shopify_created_at' => $this->parseTime($payload['created_at'] ?? null),
                'shopify_updated_at' => $this->parseTime($payload['updated_at'] ?? null),
                'cancelled_at' => $this->parseTime($payload['cancelled_at'] ?? null),
                'customer_json' => is_array($payload['customer'] ?? null) ? $payload['customer'] : null,
                'shipping_address_json' => is_array($payload['shipping_address'] ?? null) ? $payload['shipping_address'] : null,
                'payload_hash' => hash('sha256', json_encode($payload)),
                'raw_json' => $payload,
            ]
        );

        foreach (($payload['line_items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $lineId = ShopifyGid::toId((string) ($line['id'] ?? ''));
            if ($lineId === '') {
                continue;
            }
            $qty = (int) ($line['quantity'] ?? 0);
            $fulfillable = (int) ($line['fulfillable_quantity'] ?? $qty);
            ShopifyOrderLineItem::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'shopify_line_item_id' => $lineId,
                ],
                [
                    'shopify_order_id' => $order->id,
                    'shopify_variant_id' => isset($line['variant_id']) ? (string) $line['variant_id'] : null,
                    'shopify_product_id' => isset($line['product_id']) ? (string) $line['product_id'] : null,
                    'sku' => (string) ($line['sku'] ?? ''),
                    'title' => (string) ($line['title'] ?? ''),
                    'variant_title' => (string) ($line['variant_title'] ?? ''),
                    'quantity' => $qty,
                    'fulfillable_quantity' => $fulfillable,
                    'fulfilled_quantity' => max(0, $qty - $fulfillable),
                    'price' => isset($line['price']) ? (float) $line['price'] : null,
                    'raw_json' => $line,
                ]
            );
        }

        return $order->fresh();
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
        }
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
