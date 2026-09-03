<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ShopifyOrderListService
{
    public const DISPLAY_READY = 'ready_to_ship';

    public const DISPLAY_ON_HOLD = 'on_hold';

    public const DISPLAY_BACKORDER = 'backorder';

    /** @deprecated Use DISPLAY_FULFILLED */
    public const DISPLAY_SHIPPED = 'fulfilled';

    public const DISPLAY_FULFILLED = 'fulfilled';

    public const DISPLAY_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const DISPLAY_STATUSES = [
        self::DISPLAY_READY,
        self::DISPLAY_ON_HOLD,
        self::DISPLAY_BACKORDER,
        self::DISPLAY_FULFILLED,
        self::DISPLAY_CANCELLED,
    ];

    /** @var list<string> */
    public const HOLD_REASONS = [
        'Admin Hold',
        'Address Hold',
        'Payment Hold',
        'Client Hold',
    ];

    public function filteredQuery(Request $request): Builder
    {
        $q = trim((string) $request->query('q', ''));
        $accountId = (int) $request->query('client_account_id', 0);
        $status = strtolower(trim((string) ($request->query('status', $request->query('display_status', '')))));
        if ($status === 'shipped') {
            $status = self::DISPLAY_FULFILLED;
        }
        $shippingMethod = trim((string) $request->query('shipping_method', ''));
        $country = strtoupper(trim((string) $request->query('country', '')));
        $createdFrom = trim((string) $request->query('created_from', ''));
        $createdTo = trim((string) $request->query('created_to', ''));

        $query = ShopifyOrder::query()
            ->with(['connection.clientAccount:id,company_name', 'connection:id,shop_domain,client_account_id']);

        if ($accountId > 0) {
            $query->whereHas('connection', fn (Builder $b) => $b->where('client_account_id', $accountId));
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function (Builder $builder) use ($q, $like) {
                $builder->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('shopify_order_id', 'like', $like)
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(shipping_address_json, '$.name')) LIKE ?",
                        [$like]
                    )
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(customer_json, '$.firstName')) LIKE ?",
                        [$like]
                    )
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(customer_json, '$.lastName')) LIKE ?",
                        [$like]
                    );
                $digits = preg_replace('/\D+/', '', $q) ?? '';
                if ($digits !== '') {
                    $builder->orWhere('shopify_order_id', 'like', '%'.$digits.'%');
                    $builder->orWhere('name', 'like', '%#'.$digits.'%');
                    $builder->orWhere('name', 'like', '%'.$digits.'%');
                }
            });
        }

        if ($createdFrom !== '') {
            $query->whereDate('shopify_created_at', '>=', $createdFrom);
        }
        if ($createdTo !== '') {
            $query->whereDate('shopify_created_at', '<=', $createdTo);
        }

        if ($country !== '') {
            $query->where(function (Builder $builder) use ($country) {
                $builder->whereRaw(
                    "UPPER(JSON_UNQUOTE(JSON_EXTRACT(shipping_address_json, '$.countryCodeV2'))) = ?",
                    [$country]
                )->orWhereRaw(
                    "UPPER(JSON_UNQUOTE(JSON_EXTRACT(shipping_address_json, '$.country'))) = ?",
                    [$country]
                );
            });
        }

        if ($shippingMethod !== '') {
            $like = '%'.$shippingMethod.'%';
            $query->whereRaw(
                "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.shippingLine.title')), '')) LIKE ?",
                [strtolower($like)]
            );
        }

        if ($status !== '' && $status !== 'all' && in_array($status, self::DISPLAY_STATUSES, true)) {
            $this->applyDisplayStatusFilter($query, $status);
        }

        return $query->orderByDesc('shopify_created_at')->orderByDesc('id');
    }

    private function applyDisplayStatusFilter(Builder $query, string $status): void
    {
        if ($status === self::DISPLAY_FULFILLED) {
            $query->where('fulfillment_status', 'fulfilled');

            return;
        }

        if ($status === self::DISPLAY_ON_HOLD) {
            $query->whereNotNull('crm_hold_reasons')
                ->whereRaw('JSON_LENGTH(crm_hold_reasons) > 0')
                ->whereNull('cancelled_at')
                ->whereNull('crm_fulfillment_cancelled_at')
                ->where(function (Builder $b) {
                    $b->whereNull('fulfillment_status')
                        ->orWhere('fulfillment_status', '!=', 'fulfilled');
                });

            return;
        }

        if ($status === self::DISPLAY_BACKORDER) {
            $query->whereRaw(
                "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.crm_display_hint')), '')) = 'backorder'"
            )->whereNull('cancelled_at')
                ->whereNull('crm_fulfillment_cancelled_at')
                ->where(function (Builder $b) {
                    $b->whereNull('crm_hold_reasons')
                        ->orWhereRaw('JSON_LENGTH(crm_hold_reasons) = 0');
                })
                ->where(function (Builder $b) {
                    $b->whereNull('fulfillment_status')
                        ->orWhere('fulfillment_status', '!=', 'fulfilled');
                });

            return;
        }

        if ($status === self::DISPLAY_CANCELLED) {
            $query->where(function (Builder $b) {
                $b->whereNotNull('cancelled_at')
                    ->orWhereNotNull('crm_fulfillment_cancelled_at');
            })->where(function (Builder $b) {
                $b->whereNull('fulfillment_status')
                    ->orWhere('fulfillment_status', '!=', 'fulfilled');
            });

            return;
        }

        $query->where(function (Builder $b) {
            $b->whereNull('fulfillment_status')
                ->orWhereIn('fulfillment_status', ['unfulfilled', 'partial', 'partially_fulfilled', '']);
        })->where(function (Builder $b) {
            $b->whereNull('crm_hold_reasons')
                ->orWhereRaw('JSON_LENGTH(crm_hold_reasons) = 0');
        })->whereNull('cancelled_at')
            ->whereNull('crm_fulfillment_cancelled_at')
            ->whereRaw(
                "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_json, '$.crm_display_hint')), '')) != 'backorder'"
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(ShopifyOrder $order): array
    {
        $connection = $order->connection;
        $shopDomain = $connection !== null ? trim((string) $connection->shop_domain) : '';
        $shopifyId = trim((string) $order->shopify_order_id);
        $adminUrl = ($shopDomain !== '' && $shopifyId !== '')
            ? 'https://'.$shopDomain.'/admin/orders/'.$shopifyId
            : null;

        $accountName = null;
        $clientAccountId = null;
        if ($connection !== null) {
            $clientAccountId = $connection->client_account_id;
            $clientAccount = $connection->clientAccount;
            if ($clientAccount !== null) {
                $accountName = $clientAccount->company_name;
            }
        }

        return [
            'id' => $order->id,
            'name' => $order->name,
            'display_name' => $this->displayOrderName($order->name),
            'shopify_order_id' => $order->shopify_order_id,
            'display_status' => $this->displayStatus($order),
            'recipient_name' => $this->recipientName($order),
            'shopify_created_at' => optional($order->shopify_created_at)->toIso8601String(),
            'country' => $this->countryCode($order),
            'shipping_method' => $this->shippingMethod($order),
            'account_name' => $accountName,
            'client_account_id' => $clientAccountId,
            'connection_id' => $order->connection_id,
            'shop_domain' => $shopDomain !== '' ? $shopDomain : null,
            'shopify_admin_url' => $adminUrl,
            'crm_hold_reasons' => is_array($order->crm_hold_reasons) ? $order->crm_hold_reasons : [],
            'cancelled_at' => optional($order->cancelled_at)->toIso8601String(),
            'crm_fulfillment_cancelled_at' => optional($order->crm_fulfillment_cancelled_at)->toIso8601String(),
            'fulfillment_status' => $order->fulfillment_status,
        ];
    }

    public function displayOrderName(?string $name): string
    {
        $value = trim((string) $name);
        if ($value === '') {
            return '';
        }

        return ltrim($value, '#');
    }

    public function displayStatus(ShopifyOrder $order): string
    {
        $fulfillment = strtolower(trim((string) $order->fulfillment_status));
        if ($fulfillment === 'fulfilled') {
            return self::DISPLAY_FULFILLED;
        }

        if ($order->cancelled_at !== null || $order->crm_fulfillment_cancelled_at !== null) {
            return self::DISPLAY_CANCELLED;
        }

        $holds = is_array($order->crm_hold_reasons) ? $order->crm_hold_reasons : [];
        if ($holds !== []) {
            return self::DISPLAY_ON_HOLD;
        }

        if ($this->looksLikeBackorder($order)) {
            return self::DISPLAY_BACKORDER;
        }

        return self::DISPLAY_READY;
    }

    public function isFulfilled(ShopifyOrder $order): bool
    {
        return $this->displayStatus($order) === self::DISPLAY_FULFILLED;
    }

    public function isCancelled(ShopifyOrder $order): bool
    {
        return $this->displayStatus($order) === self::DISPLAY_CANCELLED;
    }

    public function displayStatusLabel(string $status): string
    {
        if ($status === 'shipped') {
            $status = self::DISPLAY_FULFILLED;
        }

        switch ($status) {
            case self::DISPLAY_READY:
                return 'Ready to Ship';
            case self::DISPLAY_ON_HOLD:
                return 'On Hold';
            case self::DISPLAY_BACKORDER:
                return 'Backorder';
            case self::DISPLAY_FULFILLED:
                return 'Fulfilled';
            case self::DISPLAY_CANCELLED:
                return 'Cancelled';
            default:
                return ucwords(str_replace('_', ' ', $status));
        }
    }

    private function looksLikeBackorder(ShopifyOrder $order): bool
    {
        // CRM status picker owns backorder — do not scan the full Shopify payload
        // (false positives kept orders stuck on Backorder after Ready to Ship).
        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        $hint = strtolower(trim((string) ($raw['crm_display_hint'] ?? '')));

        return $hint === 'backorder';
    }

    public function recipientName(ShopifyOrder $order): string
    {
        $ship = is_array($order->shipping_address_json) ? $order->shipping_address_json : [];
        $name = trim((string) ($ship['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $customer = is_array($order->customer_json) ? $order->customer_json : [];
        $parts = array_filter([
            trim((string) ($customer['firstName'] ?? $customer['first_name'] ?? '')),
            trim((string) ($customer['lastName'] ?? $customer['last_name'] ?? '')),
        ]);

        return $parts !== [] ? implode(' ', $parts) : trim((string) ($order->email ?? ''));
    }

    public function countryCode(ShopifyOrder $order): string
    {
        $ship = is_array($order->shipping_address_json) ? $order->shipping_address_json : [];

        return strtoupper(trim((string) ($ship['countryCodeV2'] ?? $ship['country_code'] ?? $ship['country'] ?? '')));
    }

    public function shippingMethod(ShopifyOrder $order): string
    {
        $raw = is_array($order->raw_json) ? $order->raw_json : [];
        $line = is_array($raw['shippingLine'] ?? null) ? $raw['shippingLine'] : [];
        $title = trim((string) ($line['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        $lines = $raw['shipping_lines'] ?? $raw['shippingLines'] ?? null;
        if (is_array($lines)) {
            $first = isset($lines['edges']) ? ($lines['edges'][0]['node'] ?? null) : ($lines[0] ?? null);
            if (is_array($first)) {
                $t = trim((string) ($first['title'] ?? ''));
                if ($t !== '') {
                    return $t;
                }
            }
        }

        return '';
    }

    /**
     * Blank-safe CSV cell values (empty string when missing).
     *
     * @return list<string>
     */
    public function exportCsvRow(ShopifyOrder $order): array
    {
        $row = $this->listRow($order);
        $date = '';
        if ($order->shopify_created_at !== null) {
            $date = $order->shopify_created_at->format('m-d-Y');
        }

        return [
            $this->displayStatusLabel((string) $row['display_status']),
            $this->displayOrderName($row['name'] ?? ''),
            (string) ($row['recipient_name'] ?? ''),
            $date,
            (string) ($row['country'] ?? ''),
            (string) ($row['shipping_method'] ?? ''),
            (string) ($row['account_name'] ?? ''),
        ];
    }

    /**
     * @return array{countries: list<string>, shipping_methods: list<string>, statuses: list<array{value:string,label:string}>, hold_reasons: list<string>, accounts: list<array{id:int,name:string}>}
     */
    public function filterMeta(): array
    {
        $countries = ShopifyOrder::query()
            ->whereNotNull('shipping_address_json')
            ->get(['shipping_address_json'])
            ->map(fn (ShopifyOrder $o) => $this->countryCode($o))
            ->filter(fn ($c) => $c !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $methods = ShopifyOrder::query()
            ->whereNotNull('raw_json')
            ->limit(500)
            ->get()
            ->map(fn (ShopifyOrder $o) => $this->shippingMethod($o))
            ->filter(fn ($m) => $m !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $statuses = array_map(fn (string $s) => [
            'value' => $s,
            'label' => $this->displayStatusLabel($s),
        ], self::DISPLAY_STATUSES);

        return [
            'countries' => $countries,
            'shipping_methods' => $methods,
            'statuses' => $statuses,
            'hold_reasons' => self::HOLD_REASONS,
            'accounts' => $this->accountOptions(),
        ];
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function accountOptions(): array
    {
        return ClientAccountShopifyConnection::query()
            ->with('clientAccount:id,company_name')
            ->whereNotNull('shop_domain')
            ->where('shop_domain', '!=', '')
            ->orderBy('id')
            ->get()
            ->map(function (ClientAccountShopifyConnection $c) {
                $clientAccount = $c->clientAccount;
                $name = $clientAccount !== null ? $clientAccount->company_name : null;

                return [
                    'id' => (int) $c->client_account_id,
                    'name' => $name ?? ('Account #'.$c->client_account_id),
                ];
            })
            ->unique('id')
            ->values()
            ->all();
    }
}
