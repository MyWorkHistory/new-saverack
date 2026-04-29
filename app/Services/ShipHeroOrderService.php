<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
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
            'has_hold' => null,
            'ready_to_ship' => null,
            'fulfillment_status' => null,
        ];

        $tab = strtolower(trim((string) ($filters['tab'] ?? 'manage')));
        if ($tab === 'on_hold') {
            $vars['has_hold'] = true;
        } elseif ($tab === 'awaiting') {
            $vars['ready_to_ship'] = true;
        } elseif ($tab === 'shipped') {
            $vars['fulfillment_status'] = 'shipped';
        }

        $graphql = <<<'GQL'
query ShipHeroOrders(
  $customer_account_id: String!,
  $order_date_from: ISODateTime,
  $order_date_to: ISODateTime,
  $has_hold: Boolean,
  $ready_to_ship: Boolean,
  $fulfillment_status: String,
  $first: Int!,
  $after: String
) {
  orders(
    customer_account_id: $customer_account_id,
    order_date_from: $order_date_from,
    order_date_to: $order_date_to,
    has_hold: $has_hold,
    ready_to_ship: $ready_to_ship,
    fulfillment_status: $fulfillment_status
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
            'pagination' => [
                'has_next_page' => (bool) ($pageInfo['hasNextPage'] ?? false),
                'end_cursor' => isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
                    ? $pageInfo['endCursor']
                    : null,
            ],
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

        Log::info('shiphero.order_detail.history.start', [
            'order_id' => $id,
            'relay_id' => $relayId,
            'customer_account_id' => $customer,
        ]);
        $history = $this->fetchOrderHistory($customer, $relayId);

        Log::info('shiphero.order_detail.normalize.done', [
            'order_id' => $id,
            'relay_id' => $relayId,
            'line_items_count' => count($lineItems),
            'history_count' => count($history),
        ]);
        return $this->normalizeOrderDetail($node, $lineItems, $history);
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
query ShipHeroOrderHeader($ids: [String], $customer_account_id: String!) {
  orders(ids: $ids, customer_account_id: $customer_account_id) {
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
          subtotal
          total_tax
          total_price
          total_discounts
          gift_invoice
          allow_partial
          require_signature
          packing_note
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

        $json = $this->client->query($graphql, [
            'ids' => [$id],
            'customer_account_id' => $customerAccountId,
        ]);

        $edges = data_get($json, 'data.orders.data.edges');
        if (! is_array($edges) || $edges === []) {
            return null;
        }
        $node = data_get($edges, '0.node');
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
query ShipHeroOrderLineItems($ids: [String], $customer_account_id: String!, $first: Int!, $after: String) {
  orders(ids: $ids, customer_account_id: $customer_account_id) {
    data(first: 1) {
      edges {
        node {
          id
          line_items(first: $first, after: $after) {
            edges {
              node {
                id
                sku
                quantity
                quantity_allocated
                quantity_pending_fulfillment
                backorder_quantity
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
  }
}
GQL;
            $json = $this->client->query($graphql, [
                'ids' => [$id],
                'customer_account_id' => $customerAccountId,
                'first' => $perPage,
                'after' => $after,
            ]);
            $edges = data_get($json, 'data.orders.data.edges.0.node.line_items.edges');
            $edges = is_array($edges) ? $edges : [];
            foreach ($edges as $edge) {
                if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                    continue;
                }
                $line = $edge['node'];
                $items[] = [
                    'id' => (string) ($line['id'] ?? ''),
                    'sku' => (string) ($line['sku'] ?? ''),
                    'name' => (string) ($line['product_name'] ?? ''),
                    'quantity' => (float) ($line['quantity'] ?? 0),
                    'quantity_allocated' => (float) ($line['quantity_allocated'] ?? 0),
                    'quantity_pending_fulfillment' => (float) ($line['quantity_pending_fulfillment'] ?? 0),
                    'backorder_quantity' => (float) ($line['backorder_quantity'] ?? 0),
                    'custom_options' => is_string($line['custom_options'] ?? null) ? $line['custom_options'] : null,
                ];
            }
            $pageInfo = data_get($json, 'data.orders.data.edges.0.node.line_items.pageInfo');
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

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOrderHistory(string $customerAccountId, string $id): array
    {
        $graphql = <<<'GQL'
query ShipHeroOrderHistory($ids: [String], $customer_account_id: String!) {
  orders(ids: $ids, customer_account_id: $customer_account_id) {
    data(first: 1) {
      edges {
        node {
          id
          order_history {
            created_at
            information
            user_id
          }
        }
      }
    }
  }
}
GQL;

        try {
            $json = $this->client->query($graphql, [
                'ids' => [$id],
                'customer_account_id' => $customerAccountId,
            ]);
            $raw = data_get($json, 'data.orders.data.edges.0.node.order_history');
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
        $shippingLines = is_array($node['shipping_lines'] ?? null) ? $node['shipping_lines'] : [];
        $shippingLine = is_array($shippingLines[0] ?? null) ? $shippingLines[0] : [];
        $shippingAddress = is_array($node['shipping_address'] ?? null) ? $node['shipping_address'] : [];

        return [
            'id' => (string) ($node['id'] ?? ''),
            'legacy_id' => is_numeric($node['legacy_id'] ?? null) ? (int) $node['legacy_id'] : null,
            'cursor' => is_string($cursor) ? $cursor : null,
            'status' => (string) ($node['fulfillment_status'] ?? ''),
            'order_number' => (string) ($node['order_number'] ?? ''),
            'order_date' => $this->nullableIso($node['order_date'] ?? null),
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
        $shippingLines = is_array($node['shipping_lines'] ?? null) ? $node['shipping_lines'] : [];
        $shippingLine = is_array($shippingLines[0] ?? null) ? $shippingLines[0] : [];

        return [
            'id' => (string) ($node['id'] ?? ''),
            'legacy_id' => is_numeric($node['legacy_id'] ?? null) ? (int) $node['legacy_id'] : null,
            'order_number' => (string) ($node['order_number'] ?? ''),
            'partner_order_id' => (string) ($node['partner_order_id'] ?? ''),
            'status' => (string) ($node['fulfillment_status'] ?? ''),
            'order_date' => $this->nullableIso($node['order_date'] ?? null),
            'required_ship_date' => $this->nullableIso($node['required_ship_date'] ?? null),
            'account' => (string) ($node['shop_name'] ?? ''),
            'email' => (string) ($node['email'] ?? ''),
            'shipping_carrier' => (string) ($shippingLine['carrier'] ?? ''),
            'method' => (string) ($shippingLine['method'] ?? ''),
            'shipping_cost' => is_numeric($shippingLine['shipping_cost'] ?? null) ? (float) $shippingLine['shipping_cost'] : null,
            'subtotal' => is_numeric($node['subtotal'] ?? null) ? (float) $node['subtotal'] : null,
            'total_tax' => is_numeric($node['total_tax'] ?? null) ? (float) $node['total_tax'] : null,
            'total_discounts' => is_numeric($node['total_discounts'] ?? null) ? (float) $node['total_discounts'] : null,
            'total_price' => is_numeric($node['total_price'] ?? null) ? (float) $node['total_price'] : null,
            'gift_invoice' => (bool) ($node['gift_invoice'] ?? false),
            'allow_partial' => (bool) ($node['allow_partial'] ?? false),
            'require_signature' => (bool) ($node['require_signature'] ?? false),
            'packing_note' => is_string($node['packing_note'] ?? null) ? $node['packing_note'] : null,
            'shipping_address' => is_array($node['shipping_address'] ?? null) ? $node['shipping_address'] : null,
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
}

