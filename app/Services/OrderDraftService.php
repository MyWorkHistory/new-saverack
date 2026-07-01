<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\OrderDraft;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderDraftService
{
    private const ROUTE_PREFIX = 'Draft:';

    public function encodeRouteId(int $id): string
    {
        return base64_encode(self::ROUTE_PREFIX.$id);
    }

    public function decodeRouteId(string $routeId): ?int
    {
        $raw = trim($routeId);
        if ($raw === '') {
            return null;
        }
        $decoded = base64_decode($raw, true);
        if (! is_string($decoded) || ! Str::startsWith($decoded, self::ROUTE_PREFIX)) {
            return null;
        }
        $id = (int) substr($decoded, strlen(self::ROUTE_PREFIX));
        if ($id <= 0) {
            return null;
        }

        return $id;
    }

    public function isDraftRouteId(string $routeId): bool
    {
        return $this->decodeRouteId($routeId) !== null;
    }

    public function findDraftForRoute(string $routeId): ?OrderDraft
    {
        $id = $this->decodeRouteId($routeId);
        if ($id === null) {
            return null;
        }

        return OrderDraft::query()->find($id);
    }

    public function findEditableDraftForRoute(string $routeId): ?OrderDraft
    {
        $draft = $this->findDraftForRoute($routeId);
        if ($draft === null || ! $draft->isDraft()) {
            return null;
        }

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $shippingAddress
     */
    public function createDraft(
        int $clientAccountId,
        string $orderNumber,
        array $shippingAddress,
        User $actor
    ): OrderDraft {
        $number = trim($orderNumber);
        if ($number === '') {
            throw ValidationException::withMessages([
                'order_number' => ['Order number is required.'],
            ]);
        }

        $exists = OrderDraft::query()
            ->where('client_account_id', $clientAccountId)
            ->where('order_number', $number)
            ->where('status', OrderDraft::STATUS_DRAFT)
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'order_number' => ['An order draft with this order number already exists for this account.'],
            ]);
        }

        return OrderDraft::query()->create([
            'client_account_id' => $clientAccountId,
            'order_number' => $number,
            'status' => OrderDraft::STATUS_DRAFT,
            'shipping_address' => $this->normalizeShippingAddress($shippingAddress),
            'line_items' => [],
            'tags' => [],
            'allow_partial' => false,
            'require_signature' => false,
            'created_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toOrderDetailPayload(OrderDraft $draft): array
    {
        $draft->loadMissing('clientAccount');
        $account = $draft->clientAccount;
        $routeId = $this->encodeRouteId((int) $draft->id);
        $carrier = trim((string) ($draft->shipping_carrier ?? ''));
        $method = trim((string) ($draft->shipping_method ?? ''));
        $ship = $this->normalizeShippingAddress(is_array($draft->shipping_address) ? $draft->shipping_address : []);
        $items = $this->normalizeLineItemsForApi(is_array($draft->line_items) ? $draft->line_items : []);
        $tags = is_array($draft->tags) ? array_values($draft->tags) : [];

        return [
            'id' => $routeId,
            'draft_id' => (int) $draft->id,
            'legacy_id' => null,
            'order_number' => (string) $draft->order_number,
            'partner_order_id' => '',
            'source' => 'Save Rack CRM',
            'status' => 'draft',
            'raw_fulfillment_status' => 'draft',
            'hold_reason' => null,
            'holds' => [
                'fraud_hold' => false,
                'address_hold' => false,
                'shipping_method_hold' => empty($method) || strcasecmp($method, 'Select') === 0,
                'operator_hold' => false,
                'payment_hold' => false,
                'client_hold' => false,
            ],
            'has_active_hold' => false,
            'tags' => $tags,
            'is_crm_user_hold' => false,
            'not_ready_subtitle' => $draft->isDraft() ? 'Draft order — not yet sent to ShipHero.' : '',
            'order_date' => $draft->created_at !== null ? $draft->created_at->toIso8601String() : null,
            'required_ship_date' => null,
            'account' => $account ? (string) ($account->company_name ?? '') : '',
            'email' => (string) ($ship['email'] ?? ''),
            'shipping_carrier' => $carrier,
            'method' => $method,
            'shipping_line' => [
                'title' => $method !== '' ? $method : 'Shipping',
                'carrier' => $carrier,
                'method' => $method,
                'price' => '0',
            ],
            'shipping_cost' => null,
            'subtotal' => null,
            'total_tax' => null,
            'total_discounts' => null,
            'total_price' => null,
            'gift_invoice' => false,
            'allow_partial' => (bool) $draft->allow_partial,
            'require_signature' => (bool) $draft->require_signature,
            'packing_note' => $draft->packing_note,
            'gift_note' => $draft->gift_note,
            'attachments' => [],
            'shipping_address' => $ship,
            'billing_address' => null,
            'items' => $items,
            'history' => [],
            'tracking_labels' => [],
            'total_label_cost' => null,
            'is_draft' => $draft->isDraft(),
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, string>
     */
    public function normalizeShippingAddress(array $address): array
    {
        return [
            'first_name' => (string) ($address['first_name'] ?? ''),
            'last_name' => (string) ($address['last_name'] ?? ''),
            'company' => (string) ($address['company'] ?? ''),
            'address1' => (string) ($address['address1'] ?? ''),
            'address2' => (string) ($address['address2'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'state' => (string) ($address['state'] ?? ''),
            'state_code' => (string) ($address['state_code'] ?? $address['state'] ?? ''),
            'zip' => (string) ($address['zip'] ?? ''),
            'country' => (string) ($address['country'] ?? 'US'),
            'country_code' => (string) ($address['country_code'] ?? $address['country'] ?? 'US'),
            'email' => (string) ($address['email'] ?? ''),
            'phone' => (string) ($address['phone'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function normalizeLineItemsForApi(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $qty = max(0, (float) ($row['quantity'] ?? 0));
            $pending = array_key_exists('quantity_pending_fulfillment', $row)
                ? (float) $row['quantity_pending_fulfillment']
                : $qty;
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = 'draft-line:'.Str::uuid()->toString();
            }
            $out[] = [
                'id' => $id,
                'sku' => $sku,
                'product_id' => (string) ($row['product_id'] ?? ''),
                'name' => (string) ($row['product_name'] ?? $row['name'] ?? ''),
                'price' => is_numeric($row['price'] ?? null) ? (float) $row['price'] : 0.0,
                'quantity' => $qty,
                'quantity_allocated' => 0,
                'quantity_pending_fulfillment' => $pending,
                'backorder_quantity' => 0,
                'fulfillment_status' => '',
                'custom_options' => null,
                'image_url' => null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateShippingAddress(OrderDraft $draft, array $data): void
    {
        $current = is_array($draft->shipping_address) ? $draft->shipping_address : [];
        $merged = array_merge($current, [
            'first_name' => (string) ($data['first_name'] ?? $current['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? $current['last_name'] ?? ''),
            'company' => (string) ($data['company'] ?? $current['company'] ?? ''),
            'address1' => (string) ($data['address1'] ?? $current['address1'] ?? ''),
            'address2' => (string) ($data['address2'] ?? $current['address2'] ?? ''),
            'city' => (string) ($data['city'] ?? $current['city'] ?? ''),
            'state' => (string) ($data['state'] ?? $current['state'] ?? ''),
            'zip' => (string) ($data['zip'] ?? $current['zip'] ?? ''),
            'country' => (string) ($data['country'] ?? $current['country'] ?? 'US'),
            'email' => (string) ($data['email'] ?? $current['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? $current['phone'] ?? ''),
        ]);
        $draft->shipping_address = $this->normalizeShippingAddress($merged);
        $draft->save();
    }

    public function updateShippingLines(OrderDraft $draft, string $carrier, string $method): void
    {
        $draft->shipping_carrier = trim($carrier);
        $draft->shipping_method = trim($method);
        $draft->save();
    }

    public function updateAllowPartial(OrderDraft $draft, bool $allow): void
    {
        $draft->allow_partial = $allow;
        $draft->save();
    }

    /**
     * @param  list<string>  $tags
     */
    public function updateTags(OrderDraft $draft, array $tags): void
    {
        $draft->tags = array_values($tags);
        $draft->save();
    }

    public function updatePackingNote(OrderDraft $draft, ?string $note): void
    {
        $draft->packing_note = $note;
        $draft->save();
    }

    public function updateSignatureGiftNote(OrderDraft $draft, bool $requireSignature, ?string $giftNote): void
    {
        $draft->require_signature = $requireSignature;
        $draft->gift_note = $giftNote;
        $draft->save();
    }

    /**
     * @param  list<array{sku: string, quantity: int, product_name?: string|null}>  $rows
     */
    public function addLineItems(OrderDraft $draft, array $rows): void
    {
        $items = $this->normalizeLineItemsForApi(is_array($draft->line_items) ? $draft->line_items : []);
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $name = isset($row['product_name']) ? trim((string) $row['product_name']) : '';
            $items[] = [
                'id' => 'draft-line:'.Str::uuid()->toString(),
                'sku' => $sku,
                'product_id' => '',
                'name' => $name,
                'price' => 0.0,
                'quantity' => (float) $qty,
                'quantity_allocated' => 0,
                'quantity_pending_fulfillment' => (float) $qty,
                'backorder_quantity' => 0,
                'fulfillment_status' => '',
                'custom_options' => null,
                'image_url' => null,
            ];
        }
        $draft->line_items = $items;
        $draft->save();
    }

    public function updateLineItemPending(OrderDraft $draft, string $lineItemId, float $quantityPending): void
    {
        $items = $this->normalizeLineItemsForApi(is_array($draft->line_items) ? $draft->line_items : []);
        $found = false;
        foreach ($items as &$item) {
            if ((string) ($item['id'] ?? '') !== $lineItemId) {
                continue;
            }
            $item['quantity_pending_fulfillment'] = max(0, $quantityPending);
            $item['quantity'] = max((float) $item['quantity'], $quantityPending);
            $found = true;
            break;
        }
        unset($item);
        if (! $found) {
            throw new RuntimeException('Line item not found on this draft.');
        }
        $draft->line_items = $items;
        $draft->save();
    }

    public function removeLineItem(OrderDraft $draft, string $lineItemId): void
    {
        $items = $this->normalizeLineItemsForApi(is_array($draft->line_items) ? $draft->line_items : []);
        $next = array_values(array_filter(
            $items,
            static fn (array $row): bool => (string) ($row['id'] ?? '') !== $lineItemId
        ));
        if (count($next) === count($items)) {
            throw new RuntimeException('Line item not found on this draft.');
        }
        $draft->line_items = $next;
        $draft->save();
    }

    /**
     * @return array{shiphero_order_id: string, order_number: string, client_account_id: int}
     */
    public function submitToShipHero(
        OrderDraft $draft,
        ShipHeroOrderService $orders,
        User $actor
    ): array {
        if (! $draft->isDraft()) {
            throw new RuntimeException('This order draft has already been submitted.');
        }

        $draft->loadMissing('clientAccount');
        $account = $draft->clientAccount;
        if ($account === null) {
            throw new RuntimeException('Client account not found.');
        }

        $customerId = trim((string) ($account->shiphero_customer_account_id ?? ''));
        if ($customerId === '') {
            throw ValidationException::withMessages([
                'client_account_id' => [
                    'This client account has no ShipHero customer account ID. Set it on the account profile, then try again.',
                ],
            ]);
        }

        $carrier = trim((string) ($draft->shipping_carrier ?? ''));
        $method = trim((string) ($draft->shipping_method ?? ''));
        if ($carrier === '') {
            throw ValidationException::withMessages([
                'shipping_carrier' => ['Select a shipping carrier before marking ready to ship.'],
            ]);
        }
        if ($method === '' || strcasecmp($method, 'Select') === 0) {
            throw ValidationException::withMessages([
                'shipping_method' => ['Select a shipping method before marking ready to ship.'],
            ]);
        }

        $ship = $this->normalizeShippingAddress(is_array($draft->shipping_address) ? $draft->shipping_address : []);
        foreach (['first_name', 'last_name', 'address1', 'city', 'state', 'zip', 'country'] as $field) {
            if (trim((string) ($ship[$field] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'shipping_address' => ['Complete the shipping address before marking ready to ship.'],
                ]);
            }
        }

        $items = $this->normalizeLineItemsForApi(is_array($draft->line_items) ? $draft->line_items : []);
        if ($items === []) {
            throw ValidationException::withMessages([
                'line_items' => ['Add at least one line item before marking ready to ship.'],
            ]);
        }

        $linePayload = [];
        foreach ($items as $item) {
            $linePayload[] = [
                'sku' => (string) $item['sku'],
                'quantity' => (int) max(1, (float) ($item['quantity_pending_fulfillment'] ?? $item['quantity'] ?? 1)),
                'price' => (float) ($item['price'] ?? 0),
                'product_name' => (string) ($item['name'] ?? ''),
            ];
        }

        $shopName = trim((string) ($account->company_name ?? ''));
        if ($shopName === '') {
            $shopName = 'Manual';
        }

        $created = $orders->createOrder($customerId, [
            'order_number' => (string) $draft->order_number,
            'shop_name' => $shopName,
            'shipping_address' => $ship,
            'line_items' => $linePayload,
        ]);

        $shipheroOrderId = trim((string) ($created['shiphero_order_id'] ?? ''));
        if ($shipheroOrderId === '') {
            throw new RuntimeException('ShipHero did not return an order ID.');
        }

        $orders->updateOrderShippingLines($shipheroOrderId, $customerId, $carrier, $method);

        if ($draft->packing_note) {
            $orders->updateOrderPackingNote($shipheroOrderId, $customerId, (string) $draft->packing_note);
        }

        $tags = is_array($draft->tags) ? array_values($draft->tags) : [];
        if ($tags !== []) {
            $orders->updateOrderTags($shipheroOrderId, $customerId, $tags);
        }

        if ($draft->allow_partial) {
            $orders->updateOrderAllowPartial($shipheroOrderId, $customerId, true);
        }

        if ($draft->require_signature || $draft->gift_note) {
            $orders->updateRequireSignatureAndGiftNote(
                $shipheroOrderId,
                $customerId,
                (bool) $draft->require_signature,
                $draft->gift_note ? (string) $draft->gift_note : null
            );
        }

        $actorName = trim((string) ($actor->name ?? ''));
        if ($actorName === '') {
            $actorName = 'CRM user';
        }
        $orders->addOrderHistoryEntry(
            $shipheroOrderId,
            $customerId,
            'Order created by '.$actorName.' via Save Rack.',
            'Save Rack CRM'
        );

        $draft->status = OrderDraft::STATUS_SUBMITTED;
        $draft->shiphero_order_id = $shipheroOrderId;
        $draft->save();

        return [
            'shiphero_order_id' => $shipheroOrderId,
            'order_number' => (string) ($created['order_number'] ?? $draft->order_number),
            'client_account_id' => (int) $draft->client_account_id,
        ];
    }

    public function assertDraftAccountAccess(OrderDraft $draft, User $user): void
    {
        $portalAccountId = (int) ($user->client_account_id ?? 0);
        if ($portalAccountId > 0 && $portalAccountId !== (int) $draft->client_account_id) {
            abort(403);
        }
    }

    public function listDraftsForUser(User $user, ?int $clientAccountId, int $perPage): LengthAwarePaginator
    {
        $query = OrderDraft::query()
            ->where('status', OrderDraft::STATUS_DRAFT)
            ->with([
                'clientAccount:id,company_name',
                'createdBy:id,name',
            ]);

        $portalAccountId = (int) ($user->client_account_id ?? 0);
        if ($portalAccountId > 0) {
            $query->where('client_account_id', $portalAccountId);
        } elseif ($clientAccountId !== null && $clientAccountId > 0) {
            $query->where('client_account_id', $clientAccountId);
        }

        $perPage = max(1, min(100, $perPage));

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toListRow(OrderDraft $draft): array
    {
        $ship = $this->normalizeShippingAddress(
            is_array($draft->shipping_address) ? $draft->shipping_address : []
        );
        $first = trim((string) ($ship['first_name'] ?? ''));
        $last = trim((string) ($ship['last_name'] ?? ''));
        $recipient = trim($first.' '.$last);
        $items = is_array($draft->line_items) ? $draft->line_items : [];
        $account = $draft->clientAccount;
        $creator = $draft->createdBy;

        return [
            'id' => (int) $draft->id,
            'draft_route_id' => $this->encodeRouteId((int) $draft->id),
            'order_number' => (string) $draft->order_number,
            'client_account_id' => (int) $draft->client_account_id,
            'client_account_company_name' => $account ? (string) ($account->company_name ?? '') : '',
            'recipient_name' => $recipient,
            'city' => (string) ($ship['city'] ?? ''),
            'state' => (string) ($ship['state'] ?? ''),
            'country' => (string) ($ship['country'] ?? ''),
            'line_items_count' => count($items),
            'created_at' => $draft->created_at !== null ? $draft->created_at->toIso8601String() : null,
            'created_by_name' => $creator ? (string) ($creator->name ?? '') : '',
        ];
    }
}
