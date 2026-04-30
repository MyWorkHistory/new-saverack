<?php

namespace App\Services;

use Carbon\Carbon;
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
            $vars['fulfillment_status'] = 'unfulfilled';
        } elseif ($tab === 'shipped') {
            $vars['fulfillment_status'] = 'shipped';
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
        $rows = $this->applyListFilters($rows, $filters);

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
      shipping_address {
        address1
        address2
        city
        state
        zip
        country
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
        carrier
        method
        price
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

        return [
            'id' => (string) ($node['id'] ?? ''),
            'legacy_id' => is_numeric($node['legacy_id'] ?? null) ? (int) $node['legacy_id'] : null,
            'cursor' => is_string($cursor) ? $cursor : null,
            'status' => $this->normalizeFulfillmentStatus($node),
            'raw_fulfillment_status' => (string) ($node['fulfillment_status'] ?? ''),
            'raw_status' => (string) ($node['status'] ?? ''),
            'raw_profile' => (string) ($node['profile'] ?? ''),
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

        return [
            'id' => (string) ($node['id'] ?? ''),
            'legacy_id' => is_numeric($node['legacy_id'] ?? null) ? (int) $node['legacy_id'] : null,
            'order_number' => (string) ($node['order_number'] ?? ''),
            'partner_order_id' => (string) ($node['partner_order_id'] ?? ''),
            'status' => $this->normalizeFulfillmentStatus($node),
            'order_date' => $this->nullableIso($node['order_date'] ?? null),
            'required_ship_date' => $this->nullableIso($node['required_ship_date'] ?? null),
            'account' => (string) ($node['shop_name'] ?? ''),
            'email' => (string) ($node['email'] ?? ''),
            'shipping_carrier' => (string) ($shippingLine['carrier'] ?? ''),
            'method' => (string) ($shippingLine['method'] ?? ''),
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

    private function nullableNumber($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
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
        if (! preg_match('/(ship|hold|pend|await|fulfill|ready|back|cancel|open|close|partial|deliver|test)/', $v)) {
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

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (! $this->statusMatchesTab($status, $tab)) {
                continue;
            }
            if (! $this->rowInDateRange($row, $from, $to)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
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

        return true;
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
        $readyToShipTotal = 0;
        $lateOrdersTotal = 0;
        $priorityOrdersTotal = 0;
        $byAccount = [];
        $now = Carbon::now();

        foreach ($accounts as $account) {
            $customerId = trim((string) ($account['customer_account_id'] ?? ''));
            if ($customerId === '') {
                continue;
            }
            $accountCount = 0;
            $after = null;
            $pages = 0;
            do {
                $payload = $this->listOrders([
                    'customer_account_id' => $customerId,
                    'tab' => 'awaiting',
                    'order_date_from' => $orderDateFrom,
                    'order_date_to' => $orderDateTo,
                    'after' => $after,
                    'first' => 100,
                ]);
                $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
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

                $hasNext = (bool) data_get($payload, 'pagination.has_next_page', false);
                $endCursor = data_get($payload, 'pagination.end_cursor');
                $after = is_string($endCursor) && trim($endCursor) !== '' ? $endCursor : null;
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

        return [
            'ready_to_ship_total' => $readyToShipTotal,
            'ready_to_ship_by_account' => $byAccount,
            'late_orders_total' => $lateOrdersTotal,
            'priority_orders_total' => $priorityOrdersTotal,
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

