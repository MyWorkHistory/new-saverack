<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ShopifyOrderManualCreateService
{
    public const SOURCE_SHOPIFY = 'shopify';

    public const SOURCE_CRM = 'crm';

    /** @var ShopifyOrderActivityService */
    private $activities;

    public function __construct(ShopifyOrderActivityService $activities)
    {
        $this->activities = $activities;
    }

    /**
     * @param  array{
     *   client_account_id:int,
     *   order_number?:string|null,
     *   email?:string|null,
     *   shipping_address?:array<string,mixed>|null
     * }  $input
     */
    public function create(array $input, ?User $actor = null): ShopifyOrder
    {
        $accountId = (int) ($input['client_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Select an account.'],
            ]);
        }

        $connection = ClientAccountShopifyConnection::query()
            ->where('client_account_id', $accountId)
            ->where('status', ClientAccountShopifyConnection::STATUS_CONNECTED)
            ->orderByDesc('id')
            ->first();

        if ($connection === null) {
            $connection = ClientAccountShopifyConnection::query()
                ->where('client_account_id', $accountId)
                ->orderByDesc('id')
                ->first();
        }

        if ($connection === null) {
            throw ValidationException::withMessages([
                'client_account_id' => ['This account has no Shopify connection.'],
            ]);
        }

        $orderNumber = trim((string) ($input['order_number'] ?? ''));
        if ($orderNumber === '') {
            $orderNumber = $this->nextManualOrderNumber();
        } else {
            $orderNumber = ltrim($orderNumber, '#');
        }

        $ship = is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : [];
        $email = trim((string) ($input['email'] ?? $ship['email'] ?? ''));
        $shippingAddress = $this->normalizeShippingAddress($ship);
        $hasRecipient = $this->shippingAddressHasContent($shippingAddress);

        return DB::transaction(function () use ($connection, $orderNumber, $email, $shippingAddress, $hasRecipient, $actor) {
            $shopifyOrderId = $this->allocateSyntheticShopifyOrderId((int) $connection->id);

            $order = new ShopifyOrder();
            $order->connection_id = (int) $connection->id;
            $order->source = self::SOURCE_CRM;
            $order->shopify_order_id = $shopifyOrderId;
            $order->name = $orderNumber;
            $order->email = $email !== '' ? $email : null;
            $order->financial_status = 'pending';
            $order->fulfillment_status = null;
            $order->currency = 'USD';
            $order->total_price = 0;
            $order->shopify_created_at = now();
            $order->shopify_updated_at = now();
            $order->crm_hold_reasons = [];
            $order->customer_json = $hasRecipient ? [
                'firstName' => (string) ($shippingAddress['first_name'] ?? ''),
                'lastName' => (string) ($shippingAddress['last_name'] ?? ''),
                'email' => $email,
                'phone' => (string) ($shippingAddress['phone'] ?? ''),
            ] : null;
            $order->shipping_address_json = $hasRecipient ? $shippingAddress : null;
            $order->raw_json = [
                'crm_display_hint' => ShopifyOrderListService::DISPLAY_DRAFT,
                'crm_source' => self::SOURCE_CRM,
                'sales_channel' => 'CRM',
            ];
            $order->payload_hash = hash('sha256', 'crm-manual:'.$shopifyOrderId);
            $order->save();

            $this->activities->record(
                $order,
                'order_created',
                'Manual order created in CRM (not sent to Shopify).',
                null,
                $actor,
                null,
                ['source' => self::SOURCE_CRM, 'order_number' => $orderNumber]
            );

            return $order->fresh(['connection.clientAccount', 'lineItems', 'fulfillmentOrders.lineItems', 'fulfillments']);
        });
    }

    public function nextManualOrderNumber(): string
    {
        $names = ShopifyOrder::query()
            ->where('source', self::SOURCE_CRM)
            ->where(function ($q) {
                $q->where('name', 'like', 'MO-%')
                    ->orWhere('name', 'like', '#MO-%');
            })
            ->pluck('name');

        $max = 0;
        foreach ($names as $name) {
            $raw = ltrim((string) $name, '#');
            if (preg_match('/^MO-(\d+)$/i', $raw, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'MO-'.($max + 1);
    }

    private function allocateSyntheticShopifyOrderId(int $connectionId): string
    {
        for ($i = 0; $i < 8; $i++) {
            $candidate = 'crm-'. $connectionId.'-'.str_replace('.', '', uniqid('', true));
            $exists = ShopifyOrder::query()
                ->where('connection_id', $connectionId)
                ->where('shopify_order_id', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not allocate a unique CRM order id.');
    }

    /**
     * @param  array<string,mixed>  $ship
     * @return array<string,mixed>
     */
    private function normalizeShippingAddress(array $ship): array
    {
        $first = trim((string) ($ship['first_name'] ?? $ship['firstName'] ?? ''));
        $last = trim((string) ($ship['last_name'] ?? $ship['lastName'] ?? ''));
        $fullName = trim((string) ($ship['full_name'] ?? $ship['name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim($first.' '.$last);
        }
        if (($first === '' || $last === '') && $fullName !== '') {
            $parts = preg_split('/\s+/', $fullName, 2) ?: [];
            if ($first === '') {
                $first = trim((string) ($parts[0] ?? ''));
            }
            if ($last === '') {
                $last = trim((string) ($parts[1] ?? ''));
            }
        }

        $country = trim((string) ($ship['country'] ?? 'United States'));
        $countryCode = strtoupper(trim((string) ($ship['country_code'] ?? $ship['countryCodeV2'] ?? '')));
        if ($countryCode === '' && preg_match('/^[A-Za-z]{2}$/', $country)) {
            $countryCode = strtoupper($country);
        }
        if ($countryCode === '' && stripos($country, 'united states') !== false) {
            $countryCode = 'US';
        }

        return [
            'name' => $fullName,
            'first_name' => $first,
            'last_name' => $last,
            'firstName' => $first,
            'lastName' => $last,
            'company' => trim((string) ($ship['company'] ?? '')),
            'address1' => trim((string) ($ship['address1'] ?? '')),
            'address2' => trim((string) ($ship['address2'] ?? '')),
            'city' => trim((string) ($ship['city'] ?? '')),
            'province' => trim((string) ($ship['province'] ?? $ship['state'] ?? '')),
            'provinceCode' => trim((string) ($ship['province_code'] ?? $ship['provinceCode'] ?? $ship['state'] ?? '')),
            'zip' => trim((string) ($ship['zip'] ?? '')),
            'country' => $country !== '' ? $country : 'United States',
            'countryCodeV2' => $countryCode !== '' ? $countryCode : 'US',
            'phone' => trim((string) ($ship['phone'] ?? '')),
            'email' => trim((string) ($ship['email'] ?? '')),
        ];
    }

    /**
     * @param  array<string,mixed>  $ship
     */
    private function shippingAddressHasContent(array $ship): bool
    {
        foreach (['name', 'first_name', 'last_name', 'address1', 'city', 'zip', 'phone', 'company'] as $key) {
            if (trim((string) ($ship[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
