<?php

namespace App\Services;

use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderActivity;
use App\Models\ShopifyOrderLineItem;
use App\Models\ShopifyProductVariant;
use App\Models\User;
use App\Support\ShopifyGid;
use RuntimeException;

class ShopifyOrderEditService
{
    /** @var ShopifyClient */
    private $client;

    /** @var ShopifyOrderSyncService */
    private $sync;

    /** @var ShopifyOrderActivityService */
    private $activities;

    /** @var ShopifyFulfillmentService */
    private $fulfillments;

    public function __construct(
        ShopifyClient $client,
        ShopifyOrderSyncService $sync,
        ShopifyOrderActivityService $activities,
        ShopifyFulfillmentService $fulfillments
    ) {
        $this->client = $client;
        $this->sync = $sync;
        $this->activities = $activities;
        $this->fulfillments = $fulfillments;
    }

    /**
     * @param  array{
     *   full_name?:string,
     *   address1?:string,
     *   address2?:string,
     *   city?:string,
     *   province?:string,
     *   zip?:string,
     *   country?:string,
     *   email?:string,
     *   phone?:string
     * }  $input
     */
    public function updateShippingAddress(ShopifyOrder $order, array $input, ?User $actor = null): ShopifyOrder
    {
        if ($order->isCrmSource()) {
            return $this->updateShippingAddressLocal($order, $input, $actor);
        }

        $connection = $order->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }

        $fullName = trim((string) ($input['full_name'] ?? ''));
        $parts = preg_split('/\s+/', $fullName, 2) ?: [];
        $firstName = trim((string) ($parts[0] ?? ''));
        $lastName = trim((string) ($parts[1] ?? ''));

        $address1 = trim((string) ($input['address1'] ?? ''));
        $address2 = trim((string) ($input['address2'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $province = trim((string) ($input['province'] ?? ''));
        $zip = trim((string) ($input['zip'] ?? ''));
        $countryRaw = trim((string) ($input['country'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $countryCode = $this->mailingAddressCountryCode($countryRaw, $order);

        [$province, $zip, $provinceCode] = $this->normalizeMailingRegion($countryCode, $province, $zip);

        // MailingAddressInput: firstName/lastName/countryCode — no `name` or `country`.
        $shippingAddress = array_filter([
            'address1' => $address1 !== '' ? $address1 : null,
            'address2' => $address2 !== '' ? $address2 : null,
            'city' => $city !== '' ? $city : null,
            'province' => $province !== '' ? $province : null,
            'provinceCode' => $provinceCode !== '' ? $provinceCode : null,
            'zip' => $zip !== '' ? $zip : null,
            'countryCode' => $countryCode !== '' ? $countryCode : null,
            'firstName' => $firstName !== '' ? $firstName : null,
            'lastName' => $lastName !== '' ? $lastName : null,
            'phone' => $phone !== '' ? $phone : null,
        ], static fn ($v) => $v !== null && $v !== '');

        if ($countryCode === 'US' && $provinceCode === '' && $province === '' && $zip !== '') {
            throw new RuntimeException('State / Province is required for United States addresses (e.g. FL).');
        }
        if ($countryCode === 'US' && $provinceCode !== '' && ! $this->isUsStateCode($provinceCode)) {
            throw new RuntimeException('State / Province looks invalid. Use a US state code (e.g. FL) or full name (Florida).');
        }
        if ($countryCode === 'US' && $zip !== '' && ! preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
            throw new RuntimeException('Postal / Zip Code looks invalid. Use a 5-digit US ZIP (e.g. 33811).');
        }

        $email = trim((string) ($input['email'] ?? ''));

        if ($this->orderShippingAddressLockedInShopify($order)) {
            throw new RuntimeException('You can\'t update the address on a fulfilled order.');
        }

        $orderInput = [
            'id' => ShopifyGid::of('Order', (string) $order->shopify_order_id),
            'shippingAddress' => $shippingAddress,
        ];
        if ($email !== '') {
            $orderInput['email'] = $email;
        }

        $api = $this->client->forConnection($connection);
        $data = $api->graphql(
            <<<'GQL'
mutation OrderUpdate($input: OrderInput!) {
  orderUpdate(input: $input) {
    order { id email }
    userErrors { field message }
  }
}
GQL
            ,
            ['input' => $orderInput]
        );

        $errors = is_array($data['orderUpdate']['userErrors'] ?? null) ? $data['orderUpdate']['userErrors'] : [];
        if ($errors !== []) {
            $shopifyMessage = (string) ($errors[0]['message'] ?? 'Could not update shipping address in Shopify.');
            if ($this->isShopifyAddressLockedError($shopifyMessage)) {
                throw new RuntimeException('You can\'t update the address on a fulfilled order.');
            }
            throw new RuntimeException($shopifyMessage);
        }

        $refreshed = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
        $target = $refreshed ?? $order->fresh(['connection', 'lineItems']);

        // Ensure local address reflects CRM edit even if GraphQL refresh omits fields.
        $localShip = is_array($target->shipping_address_json) ? $target->shipping_address_json : [];
        $localShip = array_merge($localShip, [
            'name' => $fullName,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'address1' => $address1,
            'address2' => $address2,
            'city' => $city,
            'province' => $province,
            'provinceCode' => $provinceCode !== '' ? $provinceCode : ($localShip['provinceCode'] ?? null),
            'zip' => $zip,
            'country' => $countryRaw !== '' ? $countryRaw : ($countryCode !== '' ? $countryCode : ''),
            'countryCodeV2' => $countryCode !== '' ? $countryCode : ($localShip['countryCodeV2'] ?? null),
            'phone' => $phone !== '' ? $phone : null,
        ]);
        $target->shipping_address_json = $localShip;
        if ($email !== '') {
            $target->email = $email;
        }
        $target->save();

        $this->activities->record(
            $target,
            ShopifyOrderActivity::TYPE_ADDRESS,
            'Order Edited',
            'Updated shipping address',
            $actor
        );

        return $target->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
    }

    /**
     * @param  array{carrier:string, service:string, price?:float|string|null}  $input
     */
    public function updateShippingMethod(ShopifyOrder $order, array $input, ?User $actor = null): ShopifyOrder
    {
        $carrier = strtoupper(trim((string) ($input['carrier'] ?? '')));
        $service = trim((string) ($input['service'] ?? ''));
        if ($carrier === '' || $service === '') {
            throw new RuntimeException('Carrier and service are required.');
        }

        $title = $service;
        if (stripos($service, $carrier) === false) {
            $title = $carrier.' '.$service;
        }

        $price = $input['price'] ?? null;
        if ($price === null || $price === '') {
            $price = $this->currentShippingPrice($order);
        }
        $amount = number_format((float) $price, 2, '.', '');
        $currency = strtoupper(trim((string) ($order->currency ?: 'USD'))) ?: 'USD';

        if ($order->isCrmSource()) {
            return $this->persistLocalShippingMethod($order, $carrier, $service, $title, $amount, $currency, $actor, false);
        }

        $connection = $order->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }

        $shopifySynced = false;
        try {
            $api = $this->client->forConnection($connection);
            $orderGid = ShopifyGid::of('Order', (string) $order->shopify_order_id);

            $begin = $api->graphql(
                <<<'GQL'
mutation OrderEditBegin($id: ID!) {
  orderEditBegin(id: $id) {
    calculatedOrder { id }
    userErrors { field message }
  }
}
GQL
                ,
                ['id' => $orderGid]
            );
            $beginErrors = is_array($begin['orderEditBegin']['userErrors'] ?? null) ? $begin['orderEditBegin']['userErrors'] : [];
            if ($beginErrors !== []) {
                throw new RuntimeException((string) ($beginErrors[0]['message'] ?? 'Could not start order edit.'));
            }
            $calculatedId = (string) ($begin['orderEditBegin']['calculatedOrder']['id'] ?? '');
            if ($calculatedId === '') {
                throw new RuntimeException('Could not start order edit session.');
            }

            $add = $api->graphql(
                <<<'GQL'
mutation OrderEditAddShippingLine($id: ID!, $shippingLine: OrderEditAddShippingLineInput!) {
  orderEditAddShippingLine(id: $id, shippingLine: $shippingLine) {
    calculatedOrder { id }
    userErrors { field message }
  }
}
GQL
                ,
                [
                    'id' => $calculatedId,
                    'shippingLine' => [
                        'title' => $title,
                        'price' => [
                            'amount' => $amount,
                            'currencyCode' => $currency,
                        ],
                    ],
                ]
            );
            $addErrors = is_array($add['orderEditAddShippingLine']['userErrors'] ?? null)
                ? $add['orderEditAddShippingLine']['userErrors']
                : [];
            if ($addErrors !== []) {
                throw new RuntimeException((string) ($addErrors[0]['message'] ?? 'Could not update shipping method in Shopify.'));
            }

            $this->commitOrderEdit($api, $calculatedId, 'Updated shipping method');
            $shopifySynced = true;

            $refreshed = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
            if ($refreshed !== null) {
                $order = $refreshed;
            }
        } catch (RuntimeException $e) {
            if (! $this->isMissingOrderEditScope($e->getMessage())) {
                throw $e;
            }
            // Persist CRM display so staff can continue; reconnect store after adding write_order_edits.
        }

        return $this->persistLocalShippingMethod($order, $carrier, $service, $title, $amount, $currency, $actor, $shopifySynced);
    }

    private function isMissingOrderEditScope(string $message): bool
    {
        $m = strtolower($message);

        return strpos($m, 'write_order_edits') !== false
            || (strpos($m, 'access denied') !== false && strpos($m, 'orderedit') !== false)
            || (strpos($m, 'access denied') !== false && strpos($m, 'order edit') !== false);
    }

    private function orderShippingAddressLockedInShopify(ShopifyOrder $order): bool
    {
        $fulfillment = strtolower(trim((string) ($order->fulfillment_status ?? '')));

        return $fulfillment === 'fulfilled';
    }

    private function isShopifyAddressLockedError(string $message): bool
    {
        $m = strtolower($message);

        return (strpos($m, 'fulfill') !== false && strpos($m, 'address') !== false)
            || (strpos($m, 'shipping address') !== false && (
                strpos($m, 'fulfill') !== false
                || strpos($m, 'closed') !== false
                || strpos($m, 'cannot') !== false
                || strpos($m, "can't") !== false
                || strpos($m, 'can not') !== false
            ))
            || (strpos($m, 'order is closed') !== false)
            || (strpos($m, 'already fulfilled') !== false);
    }

    /**
     * Map CRM country text / ISO code to Shopify MailingAddressInput.countryCode.
     */
    private function mailingAddressCountryCode(string $countryRaw, ShopifyOrder $order): string
    {
        $raw = trim($countryRaw);
        if ($raw !== '' && preg_match('/^[A-Za-z]{2}$/', $raw)) {
            return strtoupper($raw);
        }

        $aliases = [
            'united states' => 'US',
            'united states of america' => 'US',
            'usa' => 'US',
            'u.s.' => 'US',
            'u.s.a.' => 'US',
            'canada' => 'CA',
            'mexico' => 'MX',
            'united kingdom' => 'GB',
            'great britain' => 'GB',
            'england' => 'GB',
        ];
        $key = strtolower($raw);
        if ($key !== '' && isset($aliases[$key])) {
            return $aliases[$key];
        }

        $existing = is_array($order->shipping_address_json) ? $order->shipping_address_json : [];
        $fromOrder = strtoupper(trim((string) ($existing['countryCodeV2'] ?? $existing['country_code'] ?? '')));
        if ($fromOrder !== '' && preg_match('/^[A-Z]{2}$/', $fromOrder)) {
            return $fromOrder;
        }

        return '';
    }

    /**
     * Normalize province/zip for Shopify (swap common UI mixups; map US names → codes).
     *
     * @return array{0:string,1:string,2:string} [province, zip, provinceCode]
     */
    private function normalizeMailingRegion(string $countryCode, string $province, string $zip): array
    {
        $province = trim($province);
        $zip = trim($zip);

        // Common form mixup: ZIP typed into State, state code typed into ZIP.
        if (
            preg_match('/^\d{5}(-\d{4})?$/', $province)
            && preg_match('/^[A-Za-z]{2}$/', $zip)
        ) {
            $tmp = $province;
            $province = strtoupper($zip);
            $zip = $tmp;
        }

        $provinceCode = '';
        if ($countryCode === 'US') {
            if (preg_match('/^[A-Za-z]{2}$/', $province)) {
                $provinceCode = strtoupper($province);
                $province = $provinceCode;
            } else {
                $provinceCode = $this->usStateCodeFromName($province);
                if ($provinceCode !== '') {
                    $province = $provinceCode;
                }
            }
        } elseif (preg_match('/^[A-Za-z]{2}$/', $province)) {
            $provinceCode = strtoupper($province);
        }

        return [$province, $zip, $provinceCode];
    }

    private function usStateCodeFromName(string $name): string
    {
        $key = strtolower(trim($name));
        if ($key === '') {
            return '';
        }

        $map = $this->usStateNameMap();

        return $map[$key] ?? '';
    }

    private function isUsStateCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return false;
        }

        return in_array($code, array_values($this->usStateNameMap()), true);
    }

    /**
     * @return array<string, string>
     */
    private function usStateNameMap(): array
    {
        return [
            'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
            'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
            'district of columbia' => 'DC', 'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI',
            'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA',
            'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME',
            'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
            'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE',
            'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM',
            'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH',
            'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI',
            'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX',
            'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA',
            'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
        ];
    }

    private function persistLocalShippingMethod(
        ShopifyOrder $order,
        string $carrier,
        string $service,
        string $title,
        string $amount,
        string $currency,
        ?User $actor,
        bool $shopifySynced
    ): ShopifyOrder {
        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        $raw['shippingLine'] = array_merge(
            is_array($raw['shippingLine'] ?? null) ? $raw['shippingLine'] : [],
            [
                'title' => $title,
                'code' => $service,
                'source' => $carrier,
                'originalPriceSet' => [
                    'shopMoney' => [
                        'amount' => $amount,
                        'currencyCode' => $currency,
                    ],
                ],
            ]
        );
        $raw['crm_shipping_carrier'] = $carrier;
        $raw['crm_shipping_service'] = $service;
        $order->raw_json = $raw;
        $order->save();

        $detail = 'Updated shipping method to '.$title;
        if (! $shopifySynced) {
            $detail .= ' (saved in CRM — reconnect the Shopify store after adding the write_order_edits scope to sync to Shopify)';
        }

        $this->activities->record(
            $order,
            ShopifyOrderActivity::TYPE_SHIPPING,
            'Order Edited',
            $detail,
            $actor
        );

        return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
    }

    /**
     * @param  array{
     *   lines: list<array{id?:int, shopify_variant_id?:string, quantity:int, action?:string}>,
     *   add?: list<array{shopify_variant_id:string, quantity:int}>
     * }  $payload
     */
    public function updateItems(ShopifyOrder $order, array $payload, ?User $actor = null): ShopifyOrder
    {
        if ($order->isCrmSource()) {
            return $this->updateItemsLocal($order, $payload, $actor);
        }

        $connection = $order->connection;
        if ($connection === null || ! $connection->hasCredentials()) {
            throw new RuntimeException('Shopify connection credentials missing.');
        }

        $order->loadMissing(['lineItems', 'fulfillmentOrders.lineItems']);
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $adds = is_array($payload['add'] ?? null) ? $payload['add'] : [];
        $fulfillLineIds = [];
        $qtyUpdates = [];
        $changes = [];

        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lineId = (int) ($row['id'] ?? 0);
            $action = strtolower(trim((string) ($row['action'] ?? '')));
            $qty = (int) ($row['quantity'] ?? 0);
            /** @var ShopifyOrderLineItem|null $line */
            $line = $lineId > 0 ? $order->lineItems->firstWhere('id', $lineId) : null;
            if ($line === null) {
                continue;
            }
            if ($action === 'fulfilled') {
                $fulfillLineIds[] = $line->id;
                continue;
            }
            if ($action === 'cancel') {
                $qty = 0;
            }
            $oldQty = (int) $line->quantity;
            if ($qty === $oldQty && $action !== 'cancel') {
                continue;
            }
            $qtyUpdates[] = ['line' => $line, 'qty' => max(0, $qty), 'old' => $oldQty];
        }

        $needsEdit = $qtyUpdates !== [] || $adds !== [];
        $api = $this->client->forConnection($connection);

        if ($needsEdit) {
            try {
                $orderGid = ShopifyGid::of('Order', (string) $order->shopify_order_id);
                $begin = $api->graphql(
                <<<'GQL'
mutation OrderEditBegin($id: ID!) {
  orderEditBegin(id: $id) {
    calculatedOrder {
      id
      lineItems(first: 100) {
        edges {
          node {
            id
            quantity
            variant { id }
          }
        }
      }
    }
    userErrors { field message }
  }
}
GQL
                ,
                ['id' => $orderGid]
            );
            $beginErrors = is_array($begin['orderEditBegin']['userErrors'] ?? null) ? $begin['orderEditBegin']['userErrors'] : [];
            if ($beginErrors !== []) {
                throw new RuntimeException((string) ($beginErrors[0]['message'] ?? 'Could not start order edit.'));
            }
            $calculated = is_array($begin['orderEditBegin']['calculatedOrder'] ?? null)
                ? $begin['orderEditBegin']['calculatedOrder']
                : [];
            $calculatedId = (string) ($calculated['id'] ?? '');
            if ($calculatedId === '') {
                throw new RuntimeException('Could not start order edit session.');
            }

            $calcLinesByVariant = [];
            foreach (($calculated['lineItems']['edges'] ?? []) as $edge) {
                $node = is_array($edge['node'] ?? null) ? $edge['node'] : null;
                if ($node === null) {
                    continue;
                }
                $variantId = ShopifyGid::toId((string) ($node['variant']['id'] ?? ''));
                if ($variantId !== '') {
                    $calcLinesByVariant[$variantId] = $node;
                }
            }

            foreach ($qtyUpdates as $update) {
                /** @var ShopifyOrderLineItem $line */
                $line = $update['line'];
                $variantId = trim((string) ($line->shopify_variant_id ?? ''));
                $calcNode = $variantId !== '' ? ($calcLinesByVariant[$variantId] ?? null) : null;
                $calcLineId = is_array($calcNode) ? (string) ($calcNode['id'] ?? '') : '';
                if ($calcLineId === '') {
                    throw new RuntimeException('Could not match line item in Shopify edit session.');
                }
                $set = $api->graphql(
                    <<<'GQL'
mutation OrderEditSetQuantity($id: ID!, $lineItemId: ID!, $quantity: Int!) {
  orderEditSetQuantity(id: $id, lineItemId: $lineItemId, quantity: $quantity) {
    calculatedOrder { id }
    userErrors { field message }
  }
}
GQL
                    ,
                    [
                        'id' => $calculatedId,
                        'lineItemId' => $calcLineId,
                        'quantity' => (int) $update['qty'],
                    ]
                );
                $setErrors = is_array($set['orderEditSetQuantity']['userErrors'] ?? null)
                    ? $set['orderEditSetQuantity']['userErrors']
                    : [];
                if ($setErrors !== []) {
                    throw new RuntimeException((string) ($setErrors[0]['message'] ?? 'Could not update line quantity.'));
                }
                $changes[] = trim((string) ($line->title ?: 'Item')).' (SKU: '.trim((string) ($line->sku ?: '—')).') qty '.$update['old'].' → '.$update['qty'];
            }

            foreach ($adds as $add) {
                if (! is_array($add)) {
                    continue;
                }
                $variantId = ShopifyGid::toId((string) ($add['shopify_variant_id'] ?? ''));
                $qty = max(1, (int) ($add['quantity'] ?? 1));
                if ($variantId === '') {
                    continue;
                }
                $addRes = $api->graphql(
                    <<<'GQL'
mutation OrderEditAddVariant($id: ID!, $variantId: ID!, $quantity: Int!) {
  orderEditAddVariant(id: $id, variantId: $variantId, quantity: $quantity) {
    calculatedOrder { id }
    userErrors { field message }
  }
}
GQL
                    ,
                    [
                        'id' => $calculatedId,
                        'variantId' => ShopifyGid::of('ProductVariant', $variantId),
                        'quantity' => $qty,
                    ]
                );
                $addErrors = is_array($addRes['orderEditAddVariant']['userErrors'] ?? null)
                    ? $addRes['orderEditAddVariant']['userErrors']
                    : [];
                if ($addErrors !== []) {
                    throw new RuntimeException((string) ($addErrors[0]['message'] ?? 'Could not add variant to order.'));
                }
                $variant = ShopifyProductVariant::query()
                    ->where('connection_id', $connection->id)
                    ->where('shopify_variant_id', $variantId)
                    ->first();
                $label = $variant !== null
                    ? trim((string) ($variant->title ?: $variant->sku ?: $variantId))
                    : $variantId;
                $sku = $variant !== null ? trim((string) ($variant->sku ?: '')) : '';
                $changes[] = 'Added '.$label.($sku !== '' ? ' (SKU: '.$sku.')' : '').' qty '.$qty;
            }

            $this->commitOrderEdit($api, $calculatedId, 'Updated order items');
            $refreshed = $this->sync->refreshOrderByShopifyId($connection, (string) $order->shopify_order_id);
            $target = $refreshed ?? $order->fresh(['connection', 'lineItems', 'fulfillmentOrders.lineItems']);
            } catch (RuntimeException $e) {
                if ($this->isMissingOrderEditScope($e->getMessage())) {
                    throw new RuntimeException(
                        'Shopify app is missing the write_order_edits scope. Add it in the Shopify app settings, set SHOPIFY_SCOPES (includes write_order_edits), then reconnect the store under Account → Stores.'
                    );
                }
                throw $e;
            }
        } else {
            $target = $order->fresh(['connection', 'lineItems', 'fulfillmentOrders.lineItems']);
        }

        if ($fulfillLineIds !== []) {
            $target->loadMissing(['fulfillmentOrders.lineItems', 'lineItems', 'connection']);
            $foItems = [];
            foreach ($target->fulfillmentOrders as $fo) {
                foreach ($fo->lineItems as $foLine) {
                    $liId = (string) ($foLine->shopify_line_item_id ?? '');
                    $match = $target->lineItems->first(function ($li) use ($liId, $fulfillLineIds) {
                        return in_array((int) $li->id, $fulfillLineIds, true)
                            && (string) $li->shopify_line_item_id === $liId;
                    });
                    if ($match === null) {
                        continue;
                    }
                    $remaining = (int) $foLine->remaining_quantity;
                    if ($remaining <= 0) {
                        continue;
                    }
                    $foItems[] = [
                        'fo_line_item_id' => (string) $foLine->shopify_fo_line_item_id,
                        'quantity' => $remaining,
                    ];
                }
            }
            if ($foItems !== []) {
                $this->fulfillments->markShipped($target, $foItems, '', '', $actor);
                $changes[] = 'Marked selected item(s) fulfilled';
            }
            $target = $target->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
        }

        if ($changes !== []) {
            $this->activities->record(
                $target,
                ShopifyOrderActivity::TYPE_ITEMS,
                'Order Edited',
                implode('; ', $changes),
                $actor
            );
        }

        return $target->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
    }

    /**
     * @param  object  $api  ShopifyClient instance bound to a connection
     */
    private function commitOrderEdit($api, string $calculatedId, string $staffNote): void
    {
        $commit = $api->graphql(
            <<<'GQL'
mutation OrderEditCommit($id: ID!, $notifyCustomer: Boolean, $staffNote: String) {
  orderEditCommit(id: $id, notifyCustomer: $notifyCustomer, staffNote: $staffNote) {
    order { id }
    userErrors { field message }
  }
}
GQL
            ,
            [
                'id' => $calculatedId,
                'notifyCustomer' => false,
                'staffNote' => $staffNote,
            ]
        );
        $errors = is_array($commit['orderEditCommit']['userErrors'] ?? null) ? $commit['orderEditCommit']['userErrors'] : [];
        if ($errors !== []) {
            throw new RuntimeException((string) ($errors[0]['message'] ?? 'Could not commit order edit.'));
        }
    }

    private function currentShippingPrice(ShopifyOrder $order): float
    {
        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        $line = is_array($raw['shippingLine'] ?? null) ? $raw['shippingLine'] : [];
        $amount = $line['originalPriceSet']['shopMoney']['amount']
            ?? $line['price']
            ?? null;
        if ($amount !== null && $amount !== '') {
            return (float) $amount;
        }
        $lines = $raw['shipping_lines'] ?? null;
        if (is_array($lines) && isset($lines[0]) && is_array($lines[0])) {
            return (float) ($lines[0]['price'] ?? 0);
        }

        return 0.0;
    }

    /**
     * @param  array{
     *   full_name?:string,
     *   address1?:string,
     *   address2?:string,
     *   city?:string,
     *   province?:string,
     *   zip?:string,
     *   country?:string,
     *   email?:string,
     *   phone?:string
     * }  $input
     */
    private function updateShippingAddressLocal(ShopifyOrder $order, array $input, ?User $actor = null): ShopifyOrder
    {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $parts = preg_split('/\s+/', $fullName, 2) ?: [];
        $firstName = trim((string) ($parts[0] ?? ''));
        $lastName = trim((string) ($parts[1] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $country = trim((string) ($input['country'] ?? 'United States'));
        $countryCode = preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : '';
        if ($countryCode === '' && stripos($country, 'united states') !== false) {
            $countryCode = 'US';
        }

        $order->shipping_address_json = [
            'name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'address1' => trim((string) ($input['address1'] ?? '')),
            'address2' => trim((string) ($input['address2'] ?? '')),
            'city' => trim((string) ($input['city'] ?? '')),
            'province' => trim((string) ($input['province'] ?? '')),
            'zip' => trim((string) ($input['zip'] ?? '')),
            'country' => $country !== '' ? $country : 'United States',
            'countryCodeV2' => $countryCode !== '' ? $countryCode : 'US',
            'phone' => $phone,
        ];
        if ($email !== '') {
            $order->email = $email;
        }
        $customer = is_array($order->customer_json) ? $order->customer_json : [];
        $customer['firstName'] = $firstName;
        $customer['lastName'] = $lastName;
        if ($email !== '') {
            $customer['email'] = $email;
        }
        if ($phone !== '') {
            $customer['phone'] = $phone;
        }
        $order->customer_json = $customer;
        $order->shopify_updated_at = now();
        $order->save();

        $this->activities->record(
            $order,
            'address_updated',
            'Shipping address updated.',
            null,
            $actor
        );

        return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
    }

    /**
     * @param  array{lines?:list<array<string,mixed>>, add?:list<array<string,mixed>>}  $payload
     */
    private function updateItemsLocal(ShopifyOrder $order, array $payload, ?User $actor = null): ShopifyOrder
    {
        $order->loadMissing('lineItems');
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $adds = is_array($payload['add'] ?? null) ? $payload['add'] : [];
        $changes = [];

        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lineId = (int) ($row['id'] ?? 0);
            $action = strtolower(trim((string) ($row['action'] ?? '')));
            $qty = (int) ($row['quantity'] ?? 0);
            /** @var ShopifyOrderLineItem|null $line */
            $line = $lineId > 0 ? $order->lineItems->firstWhere('id', $lineId) : null;
            if ($line === null) {
                continue;
            }
            if ($action === 'cancel' || $qty <= 0) {
                $oldQty = (int) $line->quantity;
                $line->delete();
                $changes[] = 'Removed '.$line->sku.' (was '.$oldQty.')';
                continue;
            }
            if ($action === 'fulfilled') {
                $line->fulfilled_quantity = (int) $line->quantity;
                $line->fulfillable_quantity = 0;
                $line->save();
                $changes[] = 'Marked '.$line->sku.' fulfilled';
                continue;
            }
            $oldQty = (int) $line->quantity;
            if ($qty === $oldQty) {
                continue;
            }
            $line->quantity = $qty;
            $line->fulfillable_quantity = max(0, $qty - (int) $line->fulfilled_quantity);
            $line->save();
            $changes[] = 'Updated '.$line->sku.' qty '.$oldQty.' → '.$qty;
        }

        foreach ($adds as $row) {
            if (! is_array($row)) {
                continue;
            }
            $variantKey = trim((string) ($row['shopify_variant_id'] ?? ''));
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            if ($variantKey === '') {
                continue;
            }
            $variant = ShopifyProductVariant::query()
                ->with('product')
                ->where('connection_id', $order->connection_id)
                ->where(function ($q) use ($variantKey) {
                    $q->where('id', (int) $variantKey)
                        ->orWhere('shopify_variant_id', $variantKey);
                })
                ->first();
            if ($variant === null) {
                throw new RuntimeException('Product variant not found for this account.');
            }

            $line = new ShopifyOrderLineItem();
            $line->connection_id = (int) $order->connection_id;
            $line->shopify_order_id = (int) $order->id;
            $line->shopify_line_item_id = 'crm-line-'.str_replace('.', '', uniqid('', true));
            $line->shopify_variant_id = (string) $variant->shopify_variant_id;
            $line->shopify_product_id = $variant->product ? (string) $variant->product->shopify_product_id : null;
            $line->sku = (string) ($variant->sku ?? '');
            $line->title = (string) ($variant->product->title ?? $variant->title ?? 'Product');
            $line->variant_title = (string) ($variant->title ?? '');
            $line->quantity = $qty;
            $line->fulfillable_quantity = $qty;
            $line->fulfilled_quantity = 0;
            $line->price = 0;
            $line->raw_json = ['crm_source' => 'crm'];
            $line->save();
            $changes[] = 'Added '.$line->sku.' x'.$qty;
        }

        $order->load('lineItems');
        $total = 0.0;
        foreach ($order->lineItems as $line) {
            $total += ((float) $line->price) * (int) $line->quantity;
        }
        $order->total_price = $total;
        $order->shopify_updated_at = now();
        $order->save();

        if ($changes !== []) {
            $this->activities->record(
                $order,
                'items_updated',
                'Order items updated.',
                implode('; ', array_slice($changes, 0, 8)),
                $actor
            );
        }

        return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
    }
}
