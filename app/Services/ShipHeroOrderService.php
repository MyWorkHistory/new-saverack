<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ShipHeroOrderService
{
    /** @var ShipHeroClient */
    protected $client;

    public function __construct(ShipHeroClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listOrders(array $filters): array
    {
        $customerAccountId = trim((string) ($filters['customer_account_id'] ?? ''));
        if ($customerAccountId === '') {
            throw new RuntimeException('customer_account_id is required.');
        }

        $first = (int) ($filters['first'] ?? 20);
        $first = max(1, min(100, $first));
        $after = isset($filters['after']) ? trim((string) $filters['after']) : null;
        $after = $after !== '' ? $after : null;

        $vars = [
            'customer_account_id' => $customerAccountId,
            'first' => $first,
            'after' => $after,
            'order_date_from' => $this->nullableIso($filters['order_date_from'] ?? null),
            'order_date_to' => $this->nullableIso($filters['order_date_to'] ?? null),
            'updated_from' => null,
            'updated_to' => null,
            'has_hold' => null,
            'has_backorder' => null,
            'ready_to_ship' => null,
            'fulfillment_status' => null,
            'order_number' => null,
            'partner_order_id' => null,
            'fraud_hold' => null,
            'operator_hold' => null,
            'address_hold' => null,
            'payment_hold' => null,
        ];

        $tab = strtolower(trim((string) ($filters['tab'] ?? 'manage')));
        if ($tab === 'on_hold') {
            $vars['has_hold'] = true;
        } elseif ($tab === 'awaiting') {
            $vars['ready_to_ship'] = true;
            $vars['fulfillment_status'] = 'unfulfilled';
        } elseif ($tab === 'backorder') {
            // ShipHero uses `has_backorder`, not fulfillment_status = "backorder" (see public API schema).
            $vars['has_backorder'] = true;
        } elseif ($tab === 'shipped') {
            // ShipHero typically uses "fulfilled" for shipped/completed orders; "shipped" often returns nothing.
            $vars['fulfillment_status'] = 'fulfilled';
            $orderFrom = $filters['order_date_from'] ?? null;
            $orderTo = $filters['order_date_to'] ?? null;
            $hasOrderWindow = is_string($orderFrom) && trim($orderFrom) !== ''
                && is_string($orderTo) && trim($orderTo) !== '';
            if (! $hasOrderWindow) {
                // With no order-date window, narrow by last activity so the query is bounded (ShipHero defaults otherwise).
                $vars['updated_from'] = Carbon::now()->subDays(180)->startOfDay()->toIso8601String();
                $vars['updated_to'] = Carbon::now()->endOfDay()->toIso8601String();
            }
        }
        if (isset($filters['fulfillment_status']) && is_string($filters['fulfillment_status'])) {
            $status = trim($filters['fulfillment_status']);
            if ($status !== '') {
                $vars['fulfillment_status'] = $status;
            }
        }
        if (array_key_exists('ready_to_ship', $filters) && is_bool($filters['ready_to_ship'])) {
            $vars['ready_to_ship'] = $filters['ready_to_ship'];
        }

        $holdNeedle = strtolower(trim((string) ($filters['hold_reason'] ?? '')));
        if ($tab === 'on_hold' && $holdNeedle !== '') {
            switch ($holdNeedle) {
                case 'fraud':
                    $vars['fraud_hold'] = true;
                    break;
                case 'address':
                    $vars['address_hold'] = true;
                    break;
                case 'operator':
                    $vars['operator_hold'] = true;
                    break;
                case 'payment':
                    $vars['payment_hold'] = true;
                    break;
            }
        }

        $orderNumber = trim((string) ($filters['order_number'] ?? ''));
        $orderNumber = ltrim($orderNumber, '#');
        if ($orderNumber !== '') {
            $this->applyOrderNumberLookupGraphScope($vars, $orderNumber);
        }

        $graphql = <<<'GQL'
query ShipHeroOrders(
  $customer_account_id: String!,
  $order_date_from: ISODateTime,
  $order_date_to: ISODateTime,
  $updated_from: ISODateTime,
  $updated_to: ISODateTime,
  $has_hold: Boolean,
  $has_backorder: Boolean,
  $ready_to_ship: Boolean,
  $fulfillment_status: String,
  $order_number: String,
  $partner_order_id: String,
  $fraud_hold: Boolean,
  $operator_hold: Boolean,
  $address_hold: Boolean,
  $payment_hold: Boolean,
  $first: Int!,
  $after: String
) {
  orders(
    customer_account_id: $customer_account_id,
    order_date_from: $order_date_from,
    order_date_to: $order_date_to,
    updated_from: $updated_from,
    updated_to: $updated_to,
    has_hold: $has_hold,
    has_backorder: $has_backorder,
    ready_to_ship: $ready_to_ship,
    fulfillment_status: $fulfillment_status,
    order_number: $order_number,
    partner_order_id: $partner_order_id,
    fraud_hold: $fraud_hold,
    operator_hold: $operator_hold,
    address_hold: $address_hold,
    payment_hold: $payment_hold
  ) {
    request_id
    complexity
    data(first: $first, after: $after) {
      edges {
        cursor
        node {
          id
          legacy_id
          order_number
          shop_name
          fulfillment_status
          order_date
          required_ship_date
          profile
          source
          email
          shipping_address {
            first_name
            last_name
            country
          }
          shipping_lines {
            carrier
            method
          }
          line_items(first: 1) {
            edges {
              node {
                sku
              }
            }
          }
          holds {
            fraud_hold
            address_hold
            shipping_method_hold
            operator_hold
            payment_hold
            client_hold
          }
        }
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
  }
}
GQL;

        $json = $this->client->query($graphql, $vars);
        $parsed = $this->parseShipHeroOrdersConnection($json);
        $rows = $parsed['rows'];
        $pageInfo = $parsed['pageInfo'];

        if ($orderNumber !== '' && $rows === []) {
            $varsPartner = $vars;
            $varsPartner['order_number'] = null;
            $varsPartner['partner_order_id'] = $orderNumber;
            $jsonPartner = $this->client->query($graphql, $varsPartner);
            $parsedPartner = $this->parseShipHeroOrdersConnection($jsonPartner);
            if ($parsedPartner['rows'] !== []) {
                $rows = $parsedPartner['rows'];
                $pageInfo = $parsedPartner['pageInfo'];
            }
        }

        if ($orderNumber !== '' && $rows === [] && strpos($orderNumber, '#') !== 0) {
            $varsHash = $vars;
            $varsHash['order_number'] = '#'.$orderNumber;
            $varsHash['partner_order_id'] = null;
            $jsonHash = $this->client->query($graphql, $varsHash);
            $parsedHash = $this->parseShipHeroOrdersConnection($jsonHash);
            if ($parsedHash['rows'] !== []) {
                $rows = $parsedHash['rows'];
                $pageInfo = $parsedHash['pageInfo'];
            }
        }

        $upstreamCount = count($rows);
        $rows = $this->applyListFilters($rows, $filters);
        if ($upstreamCount > 0 && count($rows) === 0) {
            Log::warning('shiphero.orders.list.post_filter_dropped_all', [
                'customer_account_id' => $customerAccountId,
                'tab' => $tab,
                'upstream_rows' => $upstreamCount,
            ]);
        }

        return [
            'rows' => $rows,
            'pagination' => [
                'has_next_page' => (bool) ($pageInfo['hasNextPage'] ?? false),
                'end_cursor' => isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
                    ? $pageInfo['endCursor']
                    : null,
            ],
        ];
    }

    /**
     * When searching by order #, ShipHero ANDs filters; drop queue/tab constraints and date/update windows
     * so one order can be found. Storefront ids often live on partner_order_id instead of order_number.
     *
     * @param  array<string, mixed>  $vars
     */
    private function applyOrderNumberLookupGraphScope(array &$vars, string $orderNumber): void
    {
        $vars['order_number'] = $orderNumber;
        $vars['partner_order_id'] = null;
        $vars['order_date_from'] = null;
        $vars['order_date_to'] = null;
        $vars['updated_from'] = null;
        $vars['updated_to'] = null;
        $vars['has_hold'] = null;
        $vars['has_backorder'] = null;
        $vars['ready_to_ship'] = null;
        $vars['fulfillment_status'] = null;
        $vars['fraud_hold'] = null;
        $vars['operator_hold'] = null;
        $vars['address_hold'] = null;
        $vars['payment_hold'] = null;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, pageInfo: array<string, mixed>}
     */
    private function parseShipHeroOrdersConnection(array $json): array
    {
        $data = data_get($json, 'data.orders.data');
        if (! is_array($data)) {
            throw new RuntimeException('ShipHero did not return orders data.');
        }

        $edges = is_array($data['edges'] ?? null) ? $data['edges'] : [];
        $rows = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
            if ($node === null) {
                continue;
            }
            $rows[] = $this->normalizeOrderRow($node, $edge['cursor'] ?? null);
        }

        $pageInfo = is_array($data['pageInfo'] ?? null) ? $data['pageInfo'] : [];

        return [
            'rows' => $rows,
            'pageInfo' => $pageInfo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId, string $customerAccountId): array
    {
        $id = trim($orderId);
        $customer = trim($customerAccountId);
        if ($id === '' || $customer === '') {
            throw new RuntimeException('Order id and customer account id are required.');
        }

        $node = null;
        $resolvedId = $id;
        Log::info('shiphero.order_detail.header_lookup.start', [
            'order_id' => $id,
            'customer_account_id' => $customer,
        ]);
        foreach ($this->buildOrderIdCandidates($id) as $candidateId) {
            $node = $this->fetchOrderHeaderNode($customer, $candidateId);
            if ($node !== null) {
                $resolvedId = $candidateId;
                break;
            }
        }
        if ($node === null) {
            throw new RuntimeException('Order not found in ShipHero.');
        }

        $relayId = (string) ($node['id'] ?? $resolvedId);
        $lineItems = [];
        try {
            Log::info('shiphero.order_detail.line_items.start', [
                'order_id' => $id,
                'relay_id' => $relayId,
                'customer_account_id' => $customer,
            ]);
            $lineItems = $this->fetchOrderLineItems($customer, $relayId);
        } catch (\Throwable $e) {
            Log::warning('shiphero.order_detail.line_items.failed', [
                'order_id' => $id,
                'relay_id' => $relayId,
                'customer_account_id' => $customer,
                'exception' => $e->getMessage(),
            ]);
            $lineItems = [];
        }

        // Order history is intentionally skipped for speed; the order detail page
        // should still show items + order/shipping/billing details.
        $history = [];

        Log::info('shiphero.order_detail.normalize.done', [
            'order_id' => $id,
            'relay_id' => $relayId,
            'line_items_count' => count($lineItems),
            'history_count' => count($history),
        ]);
        return $this->normalizeOrderDetail($node, $lineItems, $history);
    }

    /**
     * Resolve ShipHero order relay id for mutations, or throw if not found.
     */
    private function resolveOrderRelayIdForMutations(string $orderId, string $customerAccountId): string
    {
        $id = trim($orderId);
        $customer = trim($customerAccountId);
        if ($id === '' || $customer === '') {
            throw new RuntimeException('Order id and customer account id are required.');
        }
        foreach ($this->buildOrderIdCandidates($id) as $candidateId) {
            $node = $this->fetchOrderHeaderNode($customer, $candidateId);
            if (! is_array($node)) {
                continue;
            }
            $relay = trim((string) ($node['id'] ?? ''));
            if ($relay !== '') {
                return $relay;
            }
            $c = trim((string) $candidateId);
            if ($c !== '') {
                return $c;
            }
        }

        throw new RuntimeException('Order not found in ShipHero.');
    }

    /**
     * Mark order fulfilled at ShipHero. Prefer whole-order status; on failure, retry line-by-line.
     *
     * Does not run shipment_create / inventory_remove; administrative status change only (see ShipHero docs).
     */
    public function markOrderFulfilled(string $orderId, string $customerAccountId, ?string $reason = null): void
    {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $reasonText = ($reason !== null && trim($reason) !== '') ? trim($reason) : 'Marked fulfilled via Save Rack CRM.';
        $customer = trim($customerAccountId);
        $data = [
            'order_id' => $relayId,
            'fulfillment_status' => 'fulfilled',
            'reason' => $reasonText,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateFulfillmentStatus($data: UpdateOrderFulfillmentStatusInput!) {
  order_update_fulfillment_status(data: $data) {
    request_id
    complexity
  }
}
GQL;
        try {
            $this->client->query($graphql, ['data' => $data]);
        } catch (RuntimeException $e) {
            Log::warning('shiphero.order.mark_fulfilled.whole_order_failed', [
                'order_id' => $orderId,
                'relay_id' => $relayId,
                'message' => $e->getMessage(),
            ]);
            $this->markOrderFulfilledViaLineItems($relayId, $customer);
        }
    }

    /**
     * Fallback when order_update_fulfillment_status is rejected (e.g. partial state).
     */
    private function markOrderFulfilledViaLineItems(string $relayId, string $customerAccountId): void
    {
        $lineItems = $this->fetchOrderLineItems($customerAccountId, $relayId);
        $updates = [];
        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $lid = trim((string) ($item['id'] ?? ''));
            if ($lid === '') {
                continue;
            }
            $updates[] = [
                'id' => $lid,
                'fulfillment_status' => 'fulfilled',
                'quantity_pending_fulfillment' => 0,
            ];
        }
        if ($updates === []) {
            throw new RuntimeException('Could not mark order fulfilled: no line items to update.');
        }
        $customer = trim($customerAccountId);
        $data = [
            'order_id' => $relayId,
            'line_items' => $updates,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateLineItems($data: UpdateLineItemsInput!) {
  order_update_line_items(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    /**
     * Update pending fulfillment quantity for a single line item (quantity to ship).
     */
    public function updateOrderLineItemPendingFulfillment(
        string $orderId,
        string $customerAccountId,
        string $lineItemRelayId,
        float $quantityPendingFulfillment
    ): void {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $lineId = trim($lineItemRelayId);
        if ($lineId === '') {
            throw new RuntimeException('Line item id is required.');
        }
        $data = [
            'order_id' => $relayId,
            'line_items' => [
                [
                    'id' => $lineId,
                    'quantity_pending_fulfillment' => $quantityPendingFulfillment,
                ],
            ],
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateLineItemsPending($data: UpdateLineItemsInput!) {
  order_update_line_items(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    /**
     * Remove a line item from the order.
     *
     * ShipHero supports line changes via the order_update_line_items mutation; this sends
     * quantity zero for the line id (common pattern per ShipHero community guidance—rejections
     * should surface as RuntimeException from the GraphQL client).
     */
    public function removeOrderLineItem(string $orderId, string $customerAccountId, string $lineItemRelayId): void
    {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $lineId = trim($lineItemRelayId);
        if ($lineId === '') {
            throw new RuntimeException('Line item id is required.');
        }
        $data = [
            'order_id' => $relayId,
            'line_items' => [
                [
                    'id' => $lineId,
                    'quantity' => 0,
                ],
            ],
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateLineItemsRemove($data: UpdateLineItemsInput!) {
  order_update_line_items(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    public function updateOrderPackingNote(
        string $orderId,
        string $customerAccountId,
        ?string $packingNote
    ): void {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $data = [
            'order_id' => $relayId,
            'packing_note' => $packingNote !== null ? (string) $packingNote : '',
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdatePackingNote($data: UpdateOrderInput!) {
  order_update(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    public function cancelOrderInShipHero(
        string $orderId,
        string $customerAccountId,
        ?string $reason = null,
        bool $voidOnPlatform = false,
        bool $force = false
    ): void {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $reasonText = ($reason !== null && trim($reason) !== '') ? trim($reason) : 'Canceled via Save Rack CRM.';
        $data = [
            'order_id' => $relayId,
            'reason' => $reasonText,
            'void_on_platform' => $voidOnPlatform,
            'force' => $force,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderCancel($data: CancelOrderInput!) {
  order_cancel(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    public function updateOrderShippingAddress(
        string $orderId,
        string $customerAccountId,
        array $address,
        bool $skipAddressValidation = false
    ): void {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $shippingAddress = [
            'first_name' => (string) ($address['first_name'] ?? ''),
            'last_name' => (string) ($address['last_name'] ?? ''),
            'company' => (string) ($address['company'] ?? ''),
            'address1' => (string) ($address['address1'] ?? ''),
            'address2' => (string) ($address['address2'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'state' => (string) ($address['state'] ?? ''),
            'zip' => (string) ($address['zip'] ?? ''),
            'country' => (string) ($address['country'] ?? ''),
            'email' => (string) ($address['email'] ?? ''),
            'phone' => (string) ($address['phone'] ?? ''),
        ];
        $data = [
            'order_id' => $relayId,
            'shipping_address' => $shippingAddress,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        if ($skipAddressValidation) {
            $data['skip_address_validation'] = true;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdate($data: UpdateOrderInput!) {
  order_update(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    public function updateOrderShippingLines(
        string $orderId,
        string $customerAccountId,
        string $carrier,
        string $method
    ): void {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $node = $this->fetchOrderHeaderNode($customer, $relayId);
        if (! is_array($node)) {
            throw new RuntimeException('Order not found in ShipHero.');
        }
        $shippingLine = $this->resolveShippingLine($node['shipping_lines'] ?? null);
        $title = trim((string) ($shippingLine['title'] ?? ''));
        if ($title === '') {
            $title = 'Shipping';
        }
        $price = isset($shippingLine['price']) ? (string) $shippingLine['price'] : '0';
        if ($price === '') {
            $price = '0';
        }
        $data = [
            'order_id' => $relayId,
            'shipping_lines' => [
                'title' => $title,
                'price' => $price,
                'carrier' => $carrier,
                'method' => $method,
            ],
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateShippingLines($data: UpdateOrderInput!) {
  order_update(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    public function updateOrderAllowPartial(string $orderId, string $customerAccountId, bool $allowPartial): void
    {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $data = [
            'order_id' => $relayId,
            'allow_partial' => $allowPartial,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateAllowPartial($data: UpdateOrderInput!) {
  order_update(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    /**
     * @param  list<string>  $tags
     */
    public function updateOrderTags(string $orderId, string $customerAccountId, array $tags): void
    {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $normalized = [];
        foreach ($tags as $t) {
            if (! is_string($t)) {
                continue;
            }
            $s = trim($t);
            if ($s !== '') {
                $normalized[] = $s;
            }
        }
        $normalized = array_values(array_unique($normalized));
        $data = [
            'order_id' => $relayId,
            'tags' => $normalized,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateTags($data: UpdateOrderInput!) {
  order_update(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    /**
     * Clear ShipHero holds that can be toggled via UpdateOrderHoldsInput (excludes shipping_method_hold).
     */
    public function clearOrderHolds(string $orderId, string $customerAccountId): void
    {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $data = [
            'order_id' => $relayId,
            'payment_hold' => false,
            'operator_hold' => false,
            'fraud_hold' => false,
            'address_hold' => false,
            'client_hold' => false,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateHolds($data: UpdateOrderHoldsInput!) {
  order_update_holds(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    /**
     * True when the order has active holds but none of them can be cleared via
     * {@see order_update_holds} (ShipHero excludes shipping_method_hold from UpdateOrderHoldsInput).
     *
     * @param  array<string, mixed>|null  $holds
     */
    public function orderHoldsOnlyShippingMethodHoldActive($holds): bool
    {
        $h = $this->normalizeOrderHoldsForApi($holds);
        if (! $this->orderHoldsArrayHasActive($h)) {
            return false;
        }
        foreach (['fraud_hold', 'address_hold', 'payment_hold', 'client_hold', 'operator_hold'] as $key) {
            if (! empty($h[$key])) {
                return false;
            }
        }

        return ! empty($h['shipping_method_hold']);
    }

    /**
     * Set holds to true for keys present in $flags (ShipHero UpdateOrderHoldsInput).
     * Only known hold keys are sent; omitted keys are left unchanged upstream.
     *
     * @param  array<string, bool>  $flags
     */
    public function setOrderHoldsTrue(string $orderId, string $customerAccountId, array $flags): void
    {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $allowed = ['fraud_hold', 'address_hold', 'payment_hold', 'client_hold', 'operator_hold'];
        $data = [
            'order_id' => $relayId,
        ];
        foreach ($allowed as $key) {
            if (! empty($flags[$key])) {
                $data[$key] = true;
            }
        }
        if (count($data) <= 1) {
            throw new RuntimeException('Select at least one hold type to apply.');
        }
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderSetHolds($data: UpdateOrderHoldsInput!) {
  order_update_holds(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    /**
     * @return array<string, bool>
     */
    public function getOrderHoldsNormalized(string $orderId, string $customerAccountId): array
    {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $node = $this->fetchOrderHeaderNode($customerAccountId, $relayId);
        if (! is_array($node)) {
            throw new RuntimeException('Order not found in ShipHero.');
        }

        return $this->normalizeOrderHoldsForApi($node['holds'] ?? null);
    }

    public function updateRequireSignatureAndGiftNote(
        string $orderId,
        string $customerAccountId,
        bool $requireSignature,
        ?string $giftNote
    ): void {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $data = [
            'order_id' => $relayId,
            'require_signature' => $requireSignature,
            'gift_note' => $giftNote !== null ? (string) $giftNote : '',
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderUpdateSigGift($data: UpdateOrderInput!) {
  order_update(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    /**
     * @param  list<array{sku: string, quantity: int, product_name?: string|null}>  $lineItems
     */
    public function addOrderLineItems(string $orderId, string $customerAccountId, array $lineItems): void
    {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $rows = [];
        foreach ($lineItems as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $qty = (int) ($row['quantity'] ?? 0);
            if ($qty < 1) {
                $qty = 1;
            }
            $name = isset($row['product_name']) ? trim((string) $row['product_name']) : '';
            $entry = [
                'sku' => $sku,
                'partner_line_item_id' => 'crm-'.Str::uuid()->toString(),
                'quantity' => $qty,
                'price' => '0.00',
            ];
            if ($name !== '') {
                $entry['product_name'] = $name;
            }
            $rows[] = $entry;
        }
        if ($rows === []) {
            throw new RuntimeException('No valid line items to add.');
        }
        $data = [
            'order_id' => $relayId,
            'line_items' => $rows,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderAddLineItems($data: AddLineItemsInput!) {
  order_add_line_items(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    public function addOrderAttachment(
        string $orderId,
        string $customerAccountId,
        string $url,
        ?string $filename = null,
        ?string $fileType = null,
        ?string $description = null
    ): void {
        $relayId = $this->resolveOrderRelayIdForMutations($orderId, $customerAccountId);
        $customer = trim($customerAccountId);
        $u = trim($url);
        if ($u === '') {
            throw new RuntimeException('Attachment URL is required.');
        }
        $data = [
            'order_id' => $relayId,
            'url' => $u,
        ];
        if ($customer !== '') {
            $data['customer_account_id'] = $customer;
        }
        if ($filename !== null && trim($filename) !== '') {
            $data['filename'] = trim($filename);
        }
        if ($fileType !== null && trim($fileType) !== '') {
            $data['file_type'] = trim($fileType);
        }
        if ($description !== null && trim($description) !== '') {
            $data['description'] = trim($description);
        }
        $graphql = <<<'GQL'
mutation ShipHeroOrderAddAttachment($data: OrderAddAttachmentInput!) {
  order_add_attachment(data: $data) {
    request_id
    complexity
  }
}
GQL;
        $this->client->query($graphql, ['data' => $data]);
    }

    /**
     * Raw diagnostic for order-detail header query variants.
     *
     * @return array<string, mixed>
     */
    public function debugOrderDetailRaw(string $orderId, string $customerAccountId, string $variant = 'core'): array
    {
        $id = trim($orderId);
        $customer = trim($customerAccountId);
        if ($id === '' || $customer === '') {
            throw new RuntimeException('Order id and customer account id are required.');
        }

        $candidateId = $this->buildOrderIdCandidates($id)[0];
        $query = $this->debugHeaderQueryByVariant($variant);
        $variables = [
            'ids' => [$candidateId],
            'customer_account_id' => $customer,
        ];

        $raw = $this->client->queryRawDiagnostic($query, $variables);
        $body = (string) ($raw['body'] ?? '');

        return [
            'variant' => $variant,
            'candidate_id' => $candidateId,
            'query' => $query,
            'variables' => $variables,
            'shiphero_http_status' => (int) ($raw['status'] ?? 0),
            'shiphero_body_length' => strlen($body),
            'shiphero_body_preview' => mb_substr($body, 0, 500),
        ];
    }

    /**
     * Best-effort fallback using list API rows when detail API fails.
     *
     * @return array<string, mixed>|null
     */
    public function findOrderSummaryById(string $orderId, string $customerAccountId): ?array
    {
        $id = trim($orderId);
        $customer = trim($customerAccountId);
        if ($id === '' || $customer === '') {
            return null;
        }

        foreach (['manage', 'awaiting', 'on_hold', 'shipped'] as $tab) {
            try {
                $payload = $this->listOrders([
                    'customer_account_id' => $customer,
                    'tab' => $tab,
                    'first' => 100,
                ]);
            } catch (\Throwable $e) {
                continue;
            }

            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rowId = trim((string) ($row['id'] ?? ''));
                $rowLegacy = isset($row['legacy_id']) ? trim((string) $row['legacy_id']) : '';
                $rowOrderNumber = trim((string) ($row['order_number'] ?? ''));
                if ($rowId === $id || ($rowLegacy !== '' && $rowLegacy === $id) || ($rowOrderNumber !== '' && $rowOrderNumber === $id)) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOrderHeaderNode(string $customerAccountId, string $id): ?array
    {
        $graphql = <<<'GQL'
query ShipHeroOrderHeader($id: String!) {
  order(id: $id) {
    request_id
    complexity
    data {
      id
      legacy_id
      order_number
      partner_order_id
      shop_name
      fulfillment_status
      order_date
      required_ship_date
      profile
      source
      email
      subtotal
      total_tax
      total_price
      total_discounts
      gift_invoice
      allow_partial
      require_signature
      packing_note
      gift_note
      tags
      attachments(first: 30) {
        edges {
          node {
            id
            url
            filename
            description
            file_type
            created_at
          }
        }
      }
      shipping_address {
        first_name
        last_name
        company
        address1
        address2
        city
        state
        state_code
        zip
        country
        country_code
        email
        phone
      }
      billing_address {
        address1
        address2
        city
        state
        zip
        country
      }
      shipping_lines {
        title
        carrier
        method
        price
      }
      holds {
        fraud_hold
        address_hold
        shipping_method_hold
        operator_hold
        payment_hold
        client_hold
      }
    }
  }
}
GQL;

        $json = $this->client->query($graphql, [
            'id' => $id,
        ]);
        $node = data_get($json, 'data.order.data');
        if (! is_array($node)) {
            return null;
        }

        return $node;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOrderLineItems(string $customerAccountId, string $id): array
    {
        $items = [];
        $after = null;
        $safety = 0;
        $maxPages = 6;
        $perPage = 50;
        do {
            $graphql = <<<'GQL'
query ShipHeroOrderLineItems($id: String!, $first: Int!, $after: String) {
  order(id: $id) {
    data {
      id
      line_items(first: $first, after: $after) {
        edges {
          node {
            id
            sku
            product_id
            price
            quantity
            quantity_allocated
            quantity_pending_fulfillment
            backorder_quantity
            fulfillment_status
            product_name
            custom_options
          }
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
  }
}
GQL;
            $json = $this->client->query($graphql, [
                'id' => $id,
                'first' => $perPage,
                'after' => $after,
            ]);
            $edges = data_get($json, 'data.order.data.line_items.edges');
            $edges = is_array($edges) ? $edges : [];
            foreach ($edges as $edge) {
                if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                    continue;
                }
                $line = $edge['node'];
                $items[] = [
                    'id' => (string) ($line['id'] ?? ''),
                    'sku' => (string) ($line['sku'] ?? ''),
                    'product_id' => (string) ($line['product_id'] ?? ''),
                    'name' => (string) ($line['product_name'] ?? ''),
                    'price' => is_numeric($line['price'] ?? null) ? (float) $line['price'] : null,
                    'quantity' => (float) ($line['quantity'] ?? 0),
                    'quantity_allocated' => (float) ($line['quantity_allocated'] ?? 0),
                    'quantity_pending_fulfillment' => (float) ($line['quantity_pending_fulfillment'] ?? 0),
                    'backorder_quantity' => (float) ($line['backorder_quantity'] ?? 0),
                    'fulfillment_status' => is_string($line['fulfillment_status'] ?? null)
                        ? trim($line['fulfillment_status'])
                        : '',
                    'custom_options' => is_string($line['custom_options'] ?? null) ? $line['custom_options'] : null,
                    'image_url' => null,
                ];
            }
            $pageInfo = data_get($json, 'data.order.data.line_items.pageInfo');
            $hasNext = (bool) (is_array($pageInfo) ? ($pageInfo['hasNextPage'] ?? false) : false);
            $endCursor = is_array($pageInfo) ? ($pageInfo['endCursor'] ?? null) : null;
            $after = is_string($endCursor) && $endCursor !== '' ? $endCursor : null;
            $safety++;
        } while ($hasNext && $after !== null && $safety < $maxPages);

        if ($hasNext && $after !== null) {
            Log::warning('shiphero.order_detail.line_items.pagination_truncated', [
                'order_id' => $id,
                'customer_account_id' => $customerAccountId,
                'max_pages' => $maxPages,
                'loaded_items' => count($items),
            ]);
        }

        $items = $this->attachLineItemImages($items, $customerAccountId);

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOrderHistory(string $customerAccountId, string $id): array
    {
        $graphql = <<<'GQL'
query ShipHeroOrderHistory($id: String!) {
  order(id: $id) {
    data {
      id
      order_history {
        created_at
        information
        user_id
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, [
                'id' => $id,
            ]);
            $raw = data_get($json, 'data.order.data.order_history');
            return is_array($raw) ? $raw : [];
        } catch (\Throwable $e) {
            // History is supplemental; do not fail detail page on this call.
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function normalizeOrderRow(array $node, $cursor): array
    {
        $shippingLine = $this->resolveShippingLine($node['shipping_lines'] ?? null);
        $shippingAddress = is_array($node['shipping_address'] ?? null) ? $node['shipping_address'] : [];
        $holdsApi = $this->normalizeOrderHoldsForApi($node['holds'] ?? null);
        $fn = trim((string) ($shippingAddress['first_name'] ?? ''));
        $ln = trim((string) ($shippingAddress['last_name'] ?? ''));
        $recipient = trim($fn.' '.$ln);
        if ($recipient === '') {
            $recipient = '—';
        }

        return [
            'id' => (string) ($node['id'] ?? ''),
            'legacy_id' => is_numeric($node['legacy_id'] ?? null) ? (int) $node['legacy_id'] : null,
            'cursor' => is_string($cursor) ? $cursor : null,
            'status' => $this->normalizeFulfillmentStatus($node),
            'raw_fulfillment_status' => (string) ($node['fulfillment_status'] ?? ''),
            'raw_status' => (string) ($node['status'] ?? ''),
            'raw_profile' => (string) ($node['profile'] ?? ''),
            'hold_reason' => $this->extractHoldReason($node),
            'holds' => $holdsApi,
            'has_active_hold' => $this->orderHoldsArrayHasActive($holdsApi),
            'recipient_name' => $recipient,
            'order_number' => (string) ($node['order_number'] ?? ''),
            'order_date' => $this->nullableIso($node['order_date'] ?? null),
            'required_ship_date' => $this->nullableIso($node['required_ship_date'] ?? null),
            'account' => (string) ($node['shop_name'] ?? ''),
            'country' => (string) ($shippingAddress['country'] ?? ''),
            'shipping_carrier' => (string) ($shippingLine['carrier'] ?? ''),
            'method' => (string) ($shippingLine['method'] ?? ''),
            'email' => (string) ($node['email'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function normalizeOrderDetail(array $node, array $items, array $history = []): array
    {
        $shippingLine = $this->resolveShippingLine($node['shipping_lines'] ?? null);

        $holdsApi = $this->normalizeOrderHoldsForApi($node['holds'] ?? null);

        $lineTitle = trim((string) ($shippingLine['title'] ?? ''));
        if ($lineTitle === '') {
            $lineTitle = 'Shipping';
        }
        $linePrice = isset($shippingLine['price']) ? (string) $shippingLine['price'] : '0';
        if ($linePrice === '') {
            $linePrice = '0';
        }

        return [
            'id' => (string) ($node['id'] ?? ''),
            'legacy_id' => is_numeric($node['legacy_id'] ?? null) ? (int) $node['legacy_id'] : null,
            'order_number' => (string) ($node['order_number'] ?? ''),
            'partner_order_id' => (string) ($node['partner_order_id'] ?? ''),
            'status' => $this->normalizeFulfillmentStatus($node),
            'raw_fulfillment_status' => trim((string) ($node['fulfillment_status'] ?? '')),
            'hold_reason' => $this->extractHoldReason($node),
            'holds' => $holdsApi,
            'has_active_hold' => $this->orderHoldsArrayHasActive($holdsApi),
            'not_ready_subtitle' => $this->buildNotReadyToShipHoldSubtitle($holdsApi),
            'order_date' => $this->nullableIso($node['order_date'] ?? null),
            'required_ship_date' => $this->nullableIso($node['required_ship_date'] ?? null),
            'account' => (string) ($node['shop_name'] ?? ''),
            'email' => (string) ($node['email'] ?? ''),
            'shipping_carrier' => (string) ($shippingLine['carrier'] ?? ''),
            'method' => (string) ($shippingLine['method'] ?? ''),
            'shipping_line' => [
                'title' => $lineTitle,
                'carrier' => (string) ($shippingLine['carrier'] ?? ''),
                'method' => (string) ($shippingLine['method'] ?? ''),
                'price' => $linePrice,
            ],
            'shipping_cost' => $this->nullableNumber(
                $shippingLine['shipping_cost'] ?? ($shippingLine['price'] ?? null)
            ),
            'subtotal' => is_numeric($node['subtotal'] ?? null) ? (float) $node['subtotal'] : null,
            'total_tax' => is_numeric($node['total_tax'] ?? null) ? (float) $node['total_tax'] : null,
            'total_discounts' => is_numeric($node['total_discounts'] ?? null) ? (float) $node['total_discounts'] : null,
            'total_price' => is_numeric($node['total_price'] ?? null) ? (float) $node['total_price'] : null,
            'gift_invoice' => (bool) ($node['gift_invoice'] ?? false),
            'allow_partial' => (bool) ($node['allow_partial'] ?? false),
            'require_signature' => (bool) ($node['require_signature'] ?? false),
            'packing_note' => is_string($node['packing_note'] ?? null) ? $node['packing_note'] : null,
            'gift_note' => is_string($node['gift_note'] ?? null) ? $node['gift_note'] : null,
            'tags' => $this->normalizeOrderTags($node['tags'] ?? null),
            'attachments' => $this->normalizeOrderAttachments($node['attachments'] ?? null),
            'shipping_address' => $this->normalizeOrderAddressForApi($node['shipping_address'] ?? null),
            'billing_address' => is_array($node['billing_address'] ?? null) ? $node['billing_address'] : null,
            'items' => $items,
            'history' => array_values(array_filter(array_map(function ($row) {
                if (! is_array($row)) {
                    return null;
                }
                return [
                    'created_at' => $this->nullableIso($row['created_at'] ?? null),
                    'information' => (string) ($row['information'] ?? ''),
                    'user_id' => (string) ($row['user_id'] ?? ''),
                ];
            }, $history))),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function normalizeOrderHoldsForApi($holds): array
    {
        $defaults = [
            'fraud_hold' => false,
            'address_hold' => false,
            'shipping_method_hold' => false,
            'operator_hold' => false,
            'payment_hold' => false,
            'client_hold' => false,
        ];
        if (! is_array($holds)) {
            return $defaults;
        }
        foreach (array_keys($defaults) as $key) {
            if (array_key_exists($key, $holds)) {
                $defaults[$key] = ! empty($holds[$key]);
            }
        }

        return $defaults;
    }

    /**
     * @param  array<string, bool>  $holds
     */
    private function orderHoldsArrayHasActive(array $holds): bool
    {
        foreach ($holds as $active) {
            if ($active === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $holds
     */
    private function buildNotReadyToShipHoldSubtitle(array $holds): string
    {
        $parts = [];
        if (! empty($holds['client_hold'])) {
            $parts[] = 'Order has client hold.';
        }
        if (! empty($holds['payment_hold'])) {
            $parts[] = 'Order has payment hold.';
        }
        if (! empty($holds['operator_hold'])) {
            $parts[] = 'Order has operator hold.';
        }
        if (! empty($holds['address_hold'])) {
            $parts[] = 'Order has address hold.';
        }
        if (! empty($holds['fraud_hold'])) {
            $parts[] = 'Order has fraud hold.';
        }
        if (! empty($holds['shipping_method_hold'])) {
            $parts[] = 'Order has shipping method hold.';
        }

        return $parts === [] ? '' : implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function buildOrderIdCandidates(string $orderId): array
    {
        $candidates = [];
        $push = static function (array &$target, string $value): void {
            $v = trim($value);
            if ($v !== '' && ! in_array($v, $target, true)) {
                $target[] = $v;
            }
        };

        $push($candidates, $orderId);
        if (ctype_digit($orderId)) {
            $push($candidates, (string) ((int) $orderId));
        }

        $decoded = base64_decode($orderId, true);
        if (is_string($decoded) && preg_match('/^Order:(\d+)$/i', trim($decoded), $m) === 1) {
            $push($candidates, (string) $m[1]);
        }

        return $candidates;
    }

    private function nullableIso($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v !== '' ? $v : null;
    }

    private function nullableNumber($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param mixed $tags
     * @return list<string>
     */
    private function normalizeOrderTags($tags): array
    {
        if (! is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $t) {
            if (! is_string($t)) {
                continue;
            }
            $s = trim($t);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param mixed $attachments
     * @return list<array<string, mixed>>
     */
    private function normalizeOrderAttachments($attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }
        $edges = $attachments['edges'] ?? null;
        if (! is_array($edges)) {
            return [];
        }
        $out = [];
        foreach ($edges as $edge) {
            if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                continue;
            }
            $n = $edge['node'];
            $out[] = [
                'id' => (string) ($n['id'] ?? ''),
                'url' => (string) ($n['url'] ?? ''),
                'filename' => (string) ($n['filename'] ?? ''),
                'description' => (string) ($n['description'] ?? ''),
                'file_type' => (string) ($n['file_type'] ?? ''),
                'created_at' => $this->nullableIso($n['created_at'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $address
     * @return array<string, string>
     */
    private function normalizeOrderAddressForApi($address): array
    {
        $a = is_array($address) ? $address : [];

        return [
            'first_name' => (string) ($a['first_name'] ?? ''),
            'last_name' => (string) ($a['last_name'] ?? ''),
            'company' => (string) ($a['company'] ?? ''),
            'address1' => (string) ($a['address1'] ?? ''),
            'address2' => (string) ($a['address2'] ?? ''),
            'city' => (string) ($a['city'] ?? ''),
            'state' => (string) ($a['state'] ?? ''),
            'state_code' => (string) ($a['state_code'] ?? ''),
            'zip' => (string) ($a['zip'] ?? ''),
            'country' => (string) ($a['country'] ?? ''),
            'country_code' => (string) ($a['country_code'] ?? ''),
            'email' => (string) ($a['email'] ?? ''),
            'phone' => (string) ($a['phone'] ?? ''),
        ];
    }

    /**
     * @param mixed $shippingLines
     * @return array<string, mixed>
     */
    private function resolveShippingLine($shippingLines): array
    {
        if (is_array($shippingLines) && $this->isAssoc($shippingLines)) {
            return $shippingLines;
        }
        if (! is_array($shippingLines)) {
            return [];
        }

        $fallback = [];
        foreach ($shippingLines as $line) {
            if (! is_array($line)) {
                continue;
            }
            if ($fallback === []) {
                $fallback = $line;
            }
            $carrier = trim((string) ($line['carrier'] ?? ''));
            $method = trim((string) ($line['method'] ?? ''));
            if ($carrier !== '' || $method !== '') {
                return $line;
            }
        }

        return $fallback;
    }

    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Prefer ShipHero fulfillment_status as canonical order status.
     *
     * @param array<string, mixed> $node
     */
    private function normalizeFulfillmentStatus(array $node): string
    {
        $status = trim((string) ($node['fulfillment_status'] ?? ''));
        if ($this->isPlausibleOrderStatus($status)) {
            return $status;
        }

        $fallback = trim((string) ($node['status'] ?? ''));
        if ($this->isPlausibleOrderStatus($fallback)) {
            return $fallback;
        }

        return '';
    }

    private function isPlausibleOrderStatus(string $value): bool
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return false;
        }

        // Reject obvious non-status profile/shop labels like "Antonia".
        if (! preg_match('/(ship|hold|pend|await|fulfill|ready|back|cancel|open|close|partial|deliver|test|fraud|payment|address|operator|user|inventory|oos|stock)/', $v)) {
            return false;
        }

        return true;
    }

    private function debugHeaderQueryByVariant(string $variant): string
    {
        $v = strtolower(trim($variant));
        if ($v === 'minimal') {
            return <<<'GQL'
query ShipHeroOrderHeaderDebugMinimal($ids: [String], $customer_account_id: String!) {
  orders(ids: $ids, customer_account_id: $customer_account_id) {
    request_id
    complexity
    data(first: 1) {
      edges {
        node {
          id
          legacy_id
          order_number
          fulfillment_status
        }
      }
    }
  }
}
GQL;
        }

        if ($v === 'pricing') {
            return <<<'GQL'
query ShipHeroOrderHeaderDebugPricing($ids: [String], $customer_account_id: String!) {
  orders(ids: $ids, customer_account_id: $customer_account_id) {
    request_id
    complexity
    data(first: 1) {
      edges {
        node {
          id
          legacy_id
          order_number
          subtotal
          total_tax
          total_price
          total_discounts
          gift_invoice
          allow_partial
          require_signature
        }
      }
    }
  }
}
GQL;
        }

        if ($v === 'addresses') {
            return <<<'GQL'
query ShipHeroOrderHeaderDebugAddresses($ids: [String], $customer_account_id: String!) {
  orders(ids: $ids, customer_account_id: $customer_account_id) {
    request_id
    complexity
    data(first: 1) {
      edges {
        node {
          id
          legacy_id
          order_number
          shipping_address {
            name
            address1
            address2
            city
            state
            zip
            country
          }
          billing_address {
            name
            address1
            address2
            city
            state
            zip
            country
          }
          shipping_lines {
            carrier
            method
            shipping_cost
          }
        }
      }
    }
  }
}
GQL;
        }

        return <<<'GQL'
query ShipHeroOrderHeaderDebugCore($ids: [String], $customer_account_id: String!) {
  orders(ids: $ids, customer_account_id: $customer_account_id) {
    request_id
    complexity
    data(first: 1) {
      edges {
        node {
          id
          legacy_id
          order_number
          partner_order_id
          shop_name
          fulfillment_status
          order_date
          required_ship_date
          profile
          source
          email
          packing_note
        }
      }
    }
  }
}
GQL;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function applyListFilters(array $rows, array $filters): array
    {
        $tab = strtolower(trim((string) ($filters['tab'] ?? 'manage')));
        $from = $this->normalizeDateBoundary($filters['order_date_from'] ?? null, true);
        $to = $this->normalizeDateBoundary($filters['order_date_to'] ?? null, false);
        $skipStatusTabFilter = $this->tabUsesShipHeroNativeListScope($tab);
        $lookupNeedle = trim((string) ($filters['order_number'] ?? ''));
        $lookupNeedle = ltrim($lookupNeedle, '#');
        $skipTabScopeForOrderLookup = ($lookupNeedle !== '');

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            $holdReason = strtolower(trim((string) ($filters['hold_reason'] ?? '')));
            if (! $skipStatusTabFilter && ! $skipTabScopeForOrderLookup && ! $this->statusMatchesTab($status, $tab)) {
                continue;
            }
            if ($tab === 'on_hold' && $holdReason !== '') {
                if (! $this->rowMatchesHoldReasonFilter($row, $holdReason)) {
                    continue;
                }
            }
            // ShipHero can keep `has_hold` on historical rows after the order is shipped/fulfilled.
            if ($tab === 'on_hold' && ! $skipTabScopeForOrderLookup && $this->orderRowIsFulfilledOrShipped($row)) {
                continue;
            }
            if (! $skipTabScopeForOrderLookup && ! $this->rowInDateRange($row, $from, $to)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Tabs where the ShipHero `orders` query already applies the queue filter (has_hold,
     * ready_to_ship + fulfillment_status, etc.). Post-filtering on normalized status is unsafe
     * because ShipHero often omits the substring "hold" from fulfillment_status while still
     * returning rows for has_hold: true — which previously dropped every row.
     */
    private function tabUsesShipHeroNativeListScope(string $tab): bool
    {
        return $tab === 'on_hold'
            || $tab === 'awaiting'
            || $tab === 'backorder'
            || $tab === 'shipped';
    }

    /**
     * Match UI hold-reason filter: any of several simultaneous holds on one order must match the selected filter.
     *
     * @param  array<string, mixed>  $row
     */
    private function rowMatchesHoldReasonFilter(array $row, string $needleLc): bool
    {
        if ($needleLc === '') {
            return true;
        }
        $labelLc = strtolower(trim((string) ($row['hold_reason'] ?? '')));
        $hayLc = strtolower(trim(
            (string) ($row['raw_fulfillment_status'] ?? '')
            .' '.(string) ($row['raw_status'] ?? '')
            .' '.(string) ($row['raw_profile'] ?? '')
        ));
        $combinedLc = trim($labelLc !== '' ? $labelLc.' '.$hayLc : $hayLc);
        if ($combinedLc === '') {
            return false;
        }

        $segments = [];
        if ($labelLc !== '') {
            foreach (explode(',', $labelLc) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk !== '') {
                    $segments[] = $chunk;
                }
            }
        }
        $segments[] = $combinedLc;

        foreach (array_unique($segments) as $segmentLc) {
            if ($this->holdSegmentMatchesNeedle($segmentLc, $needleLc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string  $segmentLc  already lowercased
     */
    private function holdSegmentMatchesNeedle(string $segmentLc, string $needleLc): bool
    {
        if ($segmentLc === '') {
            return false;
        }
        switch ($needleLc) {
            case 'fraud':
                return (bool) preg_match('/\bfraud\b/', $segmentLc);
            case 'address':
                return (bool) preg_match('/\baddress\b/', $segmentLc);
            case 'operator':
                return (bool) preg_match('/\boperator\b/', $segmentLc);
            case 'payment':
                return (bool) preg_match('/\bpayment\b/', $segmentLc);
            case 'user':
                return str_contains($segmentLc, 'user hold')
                    || str_contains($segmentLc, 'user_hold')
                    || (bool) preg_match('/\buser\s+hold\b/', $segmentLc);
            case 'shipping':
                return str_contains($segmentLc, 'shipping method')
                    || str_contains($segmentLc, 'shipping_method');
            default:
                return str_contains($segmentLc, $needleLc);
        }
    }

    /**
     * True when fulfillment (or raw fields) indicates the order is done shipping, not an open hold queue item.
     *
     * @param  array<string, mixed>  $row
     */
    private function orderRowIsFulfilledOrShipped(array $row): bool
    {
        foreach (['status', 'raw_fulfillment_status', 'raw_status'] as $key) {
            $normalized = strtolower(trim((string) ($row[$key] ?? '')));
            if ($normalized === '') {
                continue;
            }
            if ($normalized === 'shipped'
                || $normalized === 'fulfilled'
                || $normalized === 'complete'
                || str_starts_with($normalized, 'shipped')) {
                return true;
            }
        }

        return false;
    }

    private function statusMatchesTab(string $status, string $tab): bool
    {
        $normalized = strtolower(trim($status));
        if ($tab === 'on_hold') {
            return str_contains($normalized, 'hold');
        }
        if ($tab === 'shipped') {
            return $normalized === 'shipped'
                || $normalized === 'fulfilled'
                || $normalized === 'complete'
                || str_starts_with($normalized, 'shipped');
        }
        if ($tab === 'awaiting') {
            return ! str_contains($normalized, 'hold')
                && ! ($normalized === 'shipped'
                    || $normalized === 'fulfilled'
                    || $normalized === 'complete'
                    || str_starts_with($normalized, 'shipped'));
        }
        if ($tab === 'backorder') {
            return str_contains($normalized, 'back')
                || str_contains($normalized, 'out of stock');
        }

        return true;
    }

    /**
     * Canonical hold labels from ShipHero `Order.holds` (preferred over parsing free-text status).
     *
     * @param  mixed  $holds
     */
    private function extractHoldReasonFromHolds($holds): ?string
    {
        if (! is_array($holds)) {
            return null;
        }
        $labels = [];
        if (! empty($holds['fraud_hold'])) {
            $labels[] = 'Fraud Hold';
        }
        if (! empty($holds['payment_hold'])) {
            $labels[] = 'Payment Hold';
        }
        if (! empty($holds['address_hold'])) {
            $labels[] = 'Address Hold';
        }
        if (! empty($holds['operator_hold'])) {
            $labels[] = 'Operator Hold';
        }
        if (! empty($holds['client_hold'])) {
            $labels[] = 'User Hold';
        }
        if (! empty($holds['shipping_method_hold'])) {
            $labels[] = 'Shipping Method Hold';
        }

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * Fallback when `holds` is missing or all false: infer from fulfillment_status / status / profile.
     *
     * @param  array<string, mixed>  $node
     */
    private function extractHoldReasonFromTextFields(array $node): ?string
    {
        $hay = strtolower(trim(
            (string) ($node['fulfillment_status'] ?? '')
            .' '.(string) ($node['status'] ?? '')
            .' '.(string) ($node['profile'] ?? '')
        ));
        if ($hay === '') {
            return null;
        }
        if (str_contains($hay, 'fraud')) {
            return 'Fraud Hold';
        }
        if ((bool) preg_match('/\bpayment\b/', $hay)) {
            return 'Payment Hold';
        }
        if ((bool) preg_match('/\baddress\b/', $hay)) {
            return 'Address Hold';
        }
        if ((bool) preg_match('/\boperator\b/', $hay)) {
            return 'Operator Hold';
        }
        if (str_contains($hay, 'user hold')
            || str_contains($hay, 'user_hold')
            || (bool) preg_match('/\buser\s+hold\b/', $hay)) {
            return 'User Hold';
        }
        if (str_contains($hay, 'hold')) {
            return 'Hold';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function extractHoldReason(array $node): ?string
    {
        $fromHolds = $this->extractHoldReasonFromHolds($node['holds'] ?? null);
        if (is_string($fromHolds) && $fromHolds !== '') {
            return $fromHolds;
        }

        return $this->extractHoldReasonFromTextFields($node);
    }

    private function rowInDateRange(array $row, ?Carbon $from, ?Carbon $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }
        $raw = $row['order_date'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return false;
        }

        try {
            $date = Carbon::parse($raw);
        } catch (\Throwable $e) {
            return false;
        }

        if ($from !== null && $date->lt($from)) {
            return false;
        }
        if ($to !== null && $date->gt($to)) {
            return false;
        }

        return true;
    }

    private function normalizeDateBoundary($value, bool $startOfDay): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }

        return $startOfDay ? $date->startOfDay() : $date->endOfDay();
    }

    /**
     * @param list<array{id:int,name:string,customer_account_id:string}> $accounts
     * @return array<string,mixed>
     */
    public function readyToShipSummaryForAccounts(array $accounts, ?string $orderDateFrom, ?string $orderDateTo): array
    {
        $startedAt = microtime(true);
        $readyToShipTotal = 0;
        $lateOrdersTotal = 0;
        $priorityOrdersTotal = 0;
        $byAccount = [];
        $now = Carbon::now();
        $shipheroCalls = 0;
        $shipheroBytes = 0;
        $shipheroComplexity = 0;

        foreach ($accounts as $account) {
            $customerId = trim((string) ($account['customer_account_id'] ?? ''));
            if ($customerId === '') {
                continue;
            }
            $accountCount = 0;
            $after = null;
            $pages = 0;
            do {
                $payload = $this->fetchReadyToShipSummaryPage(
                    $customerId,
                    $orderDateFrom,
                    $orderDateTo,
                    $after,
                    100
                );
                $rows = $payload['rows'];
                $shipheroCalls += (int) ($payload['shiphero_calls'] ?? 0);
                $shipheroBytes += (int) ($payload['shiphero_body_bytes'] ?? 0);
                $shipheroComplexity += (int) ($payload['shiphero_complexity'] ?? 0);
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $accountCount++;
                    $readyToShipTotal++;

                    $lateAt = $row['required_ship_date'] ?? ($row['order_date'] ?? null);
                    if (is_string($lateAt) && trim($lateAt) !== '') {
                        try {
                            $lateDate = Carbon::parse($lateAt);
                            if ($lateDate->lt($now->copy()->subHours(24))) {
                                $lateOrdersTotal++;
                            }
                        } catch (\Throwable $e) {
                            // noop
                        }
                    }

                    $priorityRaw = strtolower(trim((string) (($row['priority'] ?? '') ?: ($row['profile'] ?? ''))));
                    if ($priorityRaw !== '' && (str_contains($priorityRaw, 'priority') || str_contains($priorityRaw, 'rush') || str_contains($priorityRaw, 'urgent'))) {
                        $priorityOrdersTotal++;
                    }
                }

                $hasNext = (bool) ($payload['has_next_page'] ?? false);
                $after = is_string($payload['end_cursor'] ?? null) ? trim((string) $payload['end_cursor']) : null;
                $after = $after !== '' ? $after : null;
                $pages++;
            } while ($after !== null && $pages < 10 && $hasNext);

            if ($accountCount > 0) {
                $byAccount[] = [
                    'account_id' => (int) ($account['id'] ?? 0),
                    'account_name' => (string) ($account['name'] ?? 'Account'),
                    'orders_count' => $accountCount,
                ];
            }
        }

        usort($byAccount, static function (array $a, array $b) {
            return ($b['orders_count'] ?? 0) <=> ($a['orders_count'] ?? 0);
        });

        Log::info('shiphero.orders_summary.service.completed', [
            'accounts_total' => count($accounts),
            'accounts_with_orders' => count($byAccount),
            'ready_to_ship_total' => $readyToShipTotal,
            'late_orders_total' => $lateOrdersTotal,
            'priority_orders_total' => $priorityOrdersTotal,
            'shiphero_calls' => $shipheroCalls,
            'shiphero_body_bytes' => $shipheroBytes,
            'shiphero_complexity_total' => $shipheroComplexity,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return [
            'ready_to_ship_total' => $readyToShipTotal,
            'ready_to_ship_by_account' => $byAccount,
            'late_orders_total' => $lateOrdersTotal,
            'priority_orders_total' => $priorityOrdersTotal,
        ];
    }

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   has_next_page:bool,
     *   end_cursor:?string,
     *   shiphero_calls:int,
     *   shiphero_body_bytes:int,
     *   shiphero_complexity:int
     * }
     */
    private function fetchReadyToShipSummaryPage(
        string $customerAccountId,
        ?string $orderDateFrom,
        ?string $orderDateTo,
        ?string $after = null,
        int $first = 100
    ): array {
        $startedAt = microtime(true);
        $graphql = <<<'GQL'
query ShipHeroReadyToShipSummaryPage(
  $customer_account_id: String!,
  $order_date_from: ISODateTime,
  $order_date_to: ISODateTime,
  $first: Int!,
  $after: String
) {
  orders(
    customer_account_id: $customer_account_id,
    order_date_from: $order_date_from,
    order_date_to: $order_date_to,
    ready_to_ship: true,
    fulfillment_status: "unfulfilled"
  ) {
    request_id
    complexity
    data(first: $first, after: $after) {
      edges {
        node {
          order_date
          required_ship_date
          profile
        }
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
  }
}
GQL;

        $vars = [
            'customer_account_id' => $customerAccountId,
            'order_date_from' => $this->nullableIso($orderDateFrom),
            'order_date_to' => $this->nullableIso($orderDateTo),
            'first' => max(1, min(100, $first)),
            'after' => $after !== null && trim($after) !== '' ? trim($after) : null,
        ];
        $json = $this->client->query($graphql, $vars);
        $data = data_get($json, 'data.orders.data');
        if (! is_array($data)) {
            throw new RuntimeException('ShipHero did not return summary orders data.');
        }

        $edges = is_array($data['edges'] ?? null) ? $data['edges'] : [];
        $rows = [];
        foreach ($edges as $edge) {
            if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                continue;
            }
            $node = $edge['node'];
            $rows[] = [
                'order_date' => $this->nullableIso($node['order_date'] ?? null),
                'required_ship_date' => $this->nullableIso($node['required_ship_date'] ?? null),
                'profile' => (string) ($node['profile'] ?? ''),
            ];
        }
        $pageInfo = is_array($data['pageInfo'] ?? null) ? $data['pageInfo'] : [];
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $complexity = (int) data_get($json, 'data.orders.complexity', 0);
        $requestId = (string) data_get($json, 'data.orders.request_id', '');

        Log::debug('shiphero.orders_summary.page', [
            'customer_account_id' => $customerAccountId,
            'orders_returned' => count($rows),
            'has_next_page' => (bool) ($pageInfo['hasNextPage'] ?? false),
            'complexity' => $complexity,
            'request_id' => $requestId !== '' ? $requestId : null,
            'duration_ms' => $durationMs,
        ]);

        return [
            'rows' => $rows,
            'has_next_page' => (bool) ($pageInfo['hasNextPage'] ?? false),
            'end_cursor' => isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor']) ? $pageInfo['endCursor'] : null,
            'shiphero_calls' => 1,
            'shiphero_body_bytes' => strlen((string) json_encode($json)),
            'shiphero_complexity' => $complexity,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function attachLineItemImages(array $items, string $customerAccountId): array
    {
        $productIds = [];
        $skus = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $pid = trim((string) ($item['product_id'] ?? ''));
            if ($pid !== '' && ! in_array($pid, $productIds, true)) {
                $productIds[] = $pid;
            }
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku !== '' && ! in_array($sku, $skus, true)) {
                $skus[] = $sku;
            }
        }
        if ($productIds === [] && $skus === []) {
            return $items;
        }

        $imageMap = [];
        foreach ($productIds as $pid) {
            $imageMap[$pid] = $this->fetchProductPrimaryImage($pid);
        }
        $imageBySku = [];
        foreach ($skus as $sku) {
            $imageBySku[$sku] = $this->fetchProductPrimaryImageBySku($sku, $customerAccountId);
        }

        foreach ($items as $idx => $item) {
            if (! is_array($item)) {
                continue;
            }
            $pid = trim((string) ($item['product_id'] ?? ''));
            $sku = trim((string) ($item['sku'] ?? ''));
            $byId = ($pid !== '' && isset($imageMap[$pid])) ? $imageMap[$pid] : null;
            $bySku = ($sku !== '' && isset($imageBySku[$sku])) ? $imageBySku[$sku] : null;
            $items[$idx]['image_url'] = $byId ?: $bySku;
        }

        return $items;
    }

    private function fetchProductPrimaryImage(string $productId): ?string
    {
        $graphql = <<<'GQL'
query ShipHeroProductImage($id: String!) {
  product(id: $id) {
    data {
      id
      images {
        src
        position
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, ['id' => $productId]);
        } catch (\Throwable $e) {
            return null;
        }
        $images = data_get($json, 'data.product.data.images');
        if (! is_array($images) || $images === []) {
            return null;
        }

        $chosen = null;
        $bestPos = PHP_INT_MAX;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $src = trim((string) ($img['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
            if ($chosen === null || $pos < $bestPos) {
                $chosen = $src;
                $bestPos = $pos;
            }
        }

        return $chosen;
    }

    private function fetchProductPrimaryImageBySku(string $sku, string $customerAccountId): ?string
    {
        $graphql = <<<'GQL'
query ShipHeroProductImageBySku($sku: String!, $customer_account_id: String) {
  product(sku: $sku, customer_account_id: $customer_account_id) {
    data {
      id
      images {
        src
        position
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, [
                'sku' => $sku,
                'customer_account_id' => trim($customerAccountId) !== '' ? trim($customerAccountId) : null,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $images = data_get($json, 'data.product.data.images');
        if (! is_array($images) || $images === []) {
            return null;
        }

        $chosen = null;
        $bestPos = PHP_INT_MAX;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $src = trim((string) ($img['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $pos = isset($img['position']) && is_numeric($img['position']) ? (int) $img['position'] : 999999;
            if ($chosen === null || $pos < $bestPos) {
                $chosen = $src;
                $bestPos = $pos;
            }
        }

        return $chosen;
    }
}

