<?php

namespace App\Services;

use App\Models\ShopifyFulfillment;
use App\Models\ShopifyFulfillmentOrderLineItem;
use App\Models\ShopifyOrder;
use App\Models\User;
use App\Support\ShopifyGid;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShopifyFulfillmentService
{
    /** @var ShopifyClient */
    private $client;

    /** @var ShopifyOrderSyncService */
    private $orders;

    public function __construct(ShopifyClient $client, ShopifyOrderSyncService $orders)
    {
        $this->client = $client;
        $this->orders = $orders;
    }

    /**
     * @param  list<array{fo_line_item_id:string|int, quantity:int}>  $items
     * @return array{fulfillment:ShopifyFulfillment, order:ShopifyOrder}
     */
    public function markShipped(
        ShopifyOrder $order,
        array $items,
        string $trackingCompany = 'UPS',
        string $trackingNumber = 'TEST123456789',
        ?User $actor = null
    ): array {
        $connection = $order->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }

        $trackingCompany = trim($trackingCompany) !== '' ? trim($trackingCompany) : 'UPS';
        $trackingNumber = trim($trackingNumber) !== '' ? trim($trackingNumber) : 'TEST123456789';

        $byFo = [];
        foreach ($items as $item) {
            $foLineId = ShopifyGid::toId((string) ($item['fo_line_item_id'] ?? ''));
            $qty = (int) ($item['quantity'] ?? 0);
            if ($foLineId === '' || $qty <= 0) {
                continue;
            }
            /** @var ShopifyFulfillmentOrderLineItem|null $foLine */
            $foLine = ShopifyFulfillmentOrderLineItem::query()
                ->where('connection_id', $connection->id)
                ->where('shopify_fo_line_item_id', $foLineId)
                ->with('fulfillmentOrder')
                ->first();
            if ($foLine === null || $foLine->fulfillmentOrder === null) {
                throw new RuntimeException('Fulfillment order line not found: '.$foLineId);
            }
            if ($qty > (int) $foLine->remaining_quantity) {
                throw new RuntimeException(
                    'Quantity '.$qty.' exceeds remaining '.$foLine->remaining_quantity.' for FO line '.$foLineId
                );
            }
            $foShopifyId = $foLine->fulfillmentOrder->shopify_fulfillment_order_id;
            if (! isset($byFo[$foShopifyId])) {
                $byFo[$foShopifyId] = [];
            }
            $byFo[$foShopifyId][] = [
                'id' => ShopifyGid::of('FulfillmentOrderLineItem', $foLineId),
                'quantity' => $qty,
            ];
        }

        if ($byFo === []) {
            throw new RuntimeException('Select at least one fulfillable line quantity.');
        }

        $lineItemsByFo = [];
        foreach ($byFo as $foId => $foLines) {
            $lineItemsByFo[] = [
                'fulfillmentOrderId' => ShopifyGid::of('FulfillmentOrder', $foId),
                'fulfillmentOrderLineItems' => $foLines,
            ];
        }

        $api = $this->client->forConnection($connection);
        $data = $api->graphql(
            <<<'GQL'
mutation fulfillmentCreate($fulfillment: FulfillmentInput!) {
  fulfillmentCreate(fulfillment: $fulfillment) {
    fulfillment {
      id
      status
      trackingInfo { company number }
    }
    userErrors { field message }
  }
}
GQL
            ,
            [
                'fulfillment' => [
                    'lineItemsByFulfillmentOrder' => $lineItemsByFo,
                    'trackingInfo' => [
                        'company' => $trackingCompany,
                        'number' => $trackingNumber,
                    ],
                    'notifyCustomer' => false,
                ],
            ]
        );

        $payload = is_array($data['fulfillmentCreate'] ?? null) ? $data['fulfillmentCreate'] : [];
        $errors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($errors !== []) {
            throw new RuntimeException((string) ($errors[0]['message'] ?? 'Fulfillment create failed.'));
        }
        $fulfillmentNode = is_array($payload['fulfillment'] ?? null) ? $payload['fulfillment'] : [];
        $shopifyFulfillmentId = ShopifyGid::toId((string) ($fulfillmentNode['id'] ?? ''));

        $record = DB::transaction(function () use (
            $connection,
            $order,
            $shopifyFulfillmentId,
            $fulfillmentNode,
            $trackingCompany,
            $trackingNumber,
            $items,
            $actor
        ) {
            return ShopifyFulfillment::query()->create([
                'connection_id' => $connection->id,
                'shopify_order_id' => $order->id,
                'shopify_fulfillment_id' => $shopifyFulfillmentId !== '' ? $shopifyFulfillmentId : null,
                'status' => strtolower((string) ($fulfillmentNode['status'] ?? 'success')),
                'tracking_company' => $trackingCompany,
                'tracking_number' => $trackingNumber,
                'line_items_json' => $items,
                'created_by_user_id' => $actor !== null ? (int) $actor->id : null,
                'raw_json' => $fulfillmentNode,
            ]);
        });

        $refreshed = $this->orders->refreshOrderByShopifyId($connection, $order->shopify_order_id)
            ?? $order->fresh(['lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);

        return [
            'fulfillment' => $record,
            'order' => $refreshed,
        ];
    }
}
