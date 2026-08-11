<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ShopifyOrder;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyConnectionService;
use App\Services\ShopifyFulfillmentService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ShopifyIntegrationController extends Controller
{
    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null || (! $user->isAdministrator() && ! $user->isCrmOwner())) {
            abort(403, 'Shopify admin access required.');
        }
    }

    public function showConnection(Request $request, ClientAccount $clientAccount, ShopifyConnectionService $connections): JsonResponse
    {
        $this->assertAdmin($request);
        $this->authorize('view', $clientAccount);

        $connection = $connections->getForAccount((int) $clientAccount->id);

        return response()->json([
            'connection' => $connections->toPublicArray($connection),
        ]);
    }

    public function upsertConnection(Request $request, ClientAccount $clientAccount, ShopifyConnectionService $connections): JsonResponse
    {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);

        $validated = $request->validate([
            'shop_domain' => ['required', 'string', 'max:191'],
            'admin_api_access_token' => ['nullable', 'string', 'max:5000'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'api_version' => ['nullable', 'string', 'max:32'],
            'import' => ['nullable', 'boolean'],
        ]);

        try {
            $connection = $connections->connectAndImport($clientAccount, $validated);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Could not connect Shopify.',
                'connection' => $connections->toPublicArray(
                    $connections->getForAccount((int) $clientAccount->id)
                ),
            ], 422);
        }

        $status = (string) ($connection->status ?? '');
        $importQueued = $status === \App\Models\ClientAccountShopifyConnection::STATUS_IMPORTING;

        return response()->json([
            'message' => $importQueued
                ? 'Shopify connected. Catalog and order import is running in the background.'
                : 'Shopify connected.',
            'connection' => $connections->toPublicArray($connection),
            'import_queued' => $importQueued,
        ], $importQueued ? 202 : 200);
    }

    public function disconnect(Request $request, ClientAccount $clientAccount, ShopifyConnectionService $connections): JsonResponse
    {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);

        $connection = $connections->getForAccount((int) $clientAccount->id);
        if ($connection === null) {
            return response()->json(['message' => 'No Shopify connection.'], 404);
        }
        $connections->disconnect($connection);

        return response()->json([
            'message' => 'Shopify disconnected.',
            'connection' => $connections->toPublicArray($connection->fresh()),
        ]);
    }

    public function importConnection(Request $request, ClientAccount $clientAccount, ShopifyConnectionService $connections): JsonResponse
    {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);

        $connection = $connections->getForAccount((int) $clientAccount->id);
        if ($connection === null || ! $connection->hasCredentials()) {
            return response()->json(['message' => 'Connect Shopify credentials first.'], 422);
        }

        try {
            $connection = $connections->syncNow($connection);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Shopify import queued. Refresh in a moment to see synced data.',
            'connection' => $connections->toPublicArray($connection),
            'import_queued' => true,
        ], 202);
    }

    public function syncConnection(Request $request, ClientAccount $clientAccount, ShopifyConnectionService $connections): JsonResponse
    {
        return $this->importConnection($request, $clientAccount, $connections);
    }

    public function ordersIndex(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $q = trim((string) $request->query('q', ''));
        $status = strtolower(trim((string) $request->query('fulfillment_status', '')));
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));

        $query = ShopifyOrder::query()
            ->with(['connection.clientAccount:id,company_name'])
            ->orderByDesc('shopify_created_at')
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', '%'.$q.'%')
                    ->orWhere('email', 'like', '%'.$q.'%')
                    ->orWhere('shopify_order_id', 'like', '%'.$q.'%');
            });
        }
        if ($status !== '' && $status !== 'all') {
            $query->where('fulfillment_status', $status);
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (ShopifyOrder $order) => $this->orderListRow($order))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function ordersShow(Request $request, ShopifyOrder $shopifyOrder): JsonResponse
    {
        $this->assertAdmin($request);
        $shopifyOrder->load([
            'connection.clientAccount:id,company_name',
            'lineItems',
            'fulfillmentOrders.lineItems',
            'fulfillments',
        ]);

        return response()->json(['order' => $this->orderDetail($shopifyOrder)]);
    }

    public function fulfillOrder(
        Request $request,
        ShopifyOrder $shopifyOrder,
        ShopifyFulfillmentService $fulfillments
    ): JsonResponse {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.fo_line_item_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'tracking_company' => ['nullable', 'string', 'max:128'],
            'tracking_number' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $result = $fulfillments->markShipped(
                $shopifyOrder,
                $validated['items'],
                (string) ($validated['tracking_company'] ?? 'UPS'),
                (string) ($validated['tracking_number'] ?? 'TEST123456789'),
                $request->user()
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        $order = $result['order']->load([
            'connection.clientAccount:id,company_name',
            'lineItems',
            'fulfillmentOrders.lineItems',
            'fulfillments',
        ]);

        return response()->json([
            'message' => 'Fulfillment created in Shopify.',
            'order' => $this->orderDetail($order),
            'fulfillment' => [
                'id' => $result['fulfillment']->id,
                'shopify_fulfillment_id' => $result['fulfillment']->shopify_fulfillment_id,
                'tracking_company' => $result['fulfillment']->tracking_company,
                'tracking_number' => $result['fulfillment']->tracking_number,
            ],
        ]);
    }

    public function inventoryIndex(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $q = trim((string) $request->query('q', ''));
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));

        $query = ShopifyProductVariant::query()
            ->with(['product', 'connection.clientAccount:id,company_name'])
            ->whereHas('product', function ($builder) {
                $builder->where(function ($p) {
                    $p->whereNull('status')->orWhere('status', 'active');
                });
            })
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('sku', 'like', '%'.$q.'%')
                    ->orWhere('title', 'like', '%'.$q.'%')
                    ->orWhere('shopify_variant_id', 'like', '%'.$q.'%')
                    ->orWhereHas('product', function ($p) use ($q) {
                        $p->where('title', 'like', '%'.$q.'%');
                    });
            });
        }

        $page = $query->paginate($perPage);
        $variantIds = collect($page->items())->pluck('shopify_inventory_item_id')->filter()->all();
        $connectionIds = collect($page->items())->pluck('connection_id')->unique()->all();

        $levels = \App\Models\ShopifyInventoryLevel::query()
            ->whereIn('connection_id', $connectionIds)
            ->whereIn('shopify_inventory_item_id', $variantIds)
            ->get()
            ->groupBy(fn ($row) => $row->connection_id.'|'.$row->shopify_inventory_item_id);

        $locations = \App\Models\ShopifyLocation::query()
            ->whereIn('connection_id', $connectionIds)
            ->get()
            ->groupBy('connection_id');

        return response()->json([
            'data' => collect($page->items())->map(function (ShopifyProductVariant $variant) use ($levels, $locations) {
                $key = $variant->connection_id.'|'.$variant->shopify_inventory_item_id;
                $levelRows = $levels->get($key, collect());
                $locMap = ($locations->get($variant->connection_id) ?? collect())->keyBy('shopify_location_id');

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'title' => $variant->title,
                    'product_title' => $variant->product->title ?? null,
                    'shopify_variant_id' => $variant->shopify_variant_id,
                    'shopify_product_id' => $variant->product->shopify_product_id ?? null,
                    'weight' => $variant->weight,
                    'weight_unit' => $variant->weight_unit,
                    'account_name' => $variant->connection->clientAccount->company_name ?? null,
                    'inventory' => $levelRows->map(function ($level) use ($locMap) {
                        $loc = $locMap->get($level->shopify_location_id);

                        return [
                            'location_id' => $level->shopify_location_id,
                            'location_name' => $loc->name ?? $level->shopify_location_id,
                            'available' => (int) $level->available,
                        ];
                    })->values(),
                    'available_total' => (int) $levelRows->sum('available'),
                ];
            })->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function inventoryShow(Request $request, ShopifyProductVariant $shopifyVariant): JsonResponse
    {
        $this->assertAdmin($request);
        $shopifyVariant->load(['product', 'connection.clientAccount:id,company_name']);

        $levels = \App\Models\ShopifyInventoryLevel::query()
            ->where('connection_id', $shopifyVariant->connection_id)
            ->where('shopify_inventory_item_id', $shopifyVariant->shopify_inventory_item_id)
            ->get();
        $locMap = \App\Models\ShopifyLocation::query()
            ->where('connection_id', $shopifyVariant->connection_id)
            ->get()
            ->keyBy('shopify_location_id');

        return response()->json([
            'variant' => [
                'id' => $shopifyVariant->id,
                'sku' => $shopifyVariant->sku,
                'title' => $shopifyVariant->title,
                'product_title' => $shopifyVariant->product->title ?? null,
                'weight' => $shopifyVariant->weight,
                'weight_unit' => $shopifyVariant->weight_unit,
                'shopify_variant_id' => $shopifyVariant->shopify_variant_id,
                'shopify_product_id' => $shopifyVariant->product->shopify_product_id ?? null,
                'crm_locked_at' => optional($shopifyVariant->crm_locked_at)->toIso8601String(),
                'account_name' => $shopifyVariant->connection->clientAccount->company_name ?? null,
                'inventory' => $levels->map(function ($level) use ($locMap) {
                    $loc = $locMap->get($level->shopify_location_id);

                    return [
                        'location_id' => $level->shopify_location_id,
                        'location_name' => $loc->name ?? $level->shopify_location_id,
                        'available' => (int) $level->available,
                    ];
                })->values(),
            ],
        ]);
    }

    public function updateVariant(
        Request $request,
        ShopifyProductVariant $shopifyVariant,
        ShopifyProductSyncService $products
    ): JsonResponse {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:500'],
            'product_title' => ['nullable', 'string', 'max:500'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'max:16'],
        ]);

        try {
            $variant = $products->pushVariantToShopify($shopifyVariant, $validated);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => 'Saved To Shopify.',
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'title' => $variant->title,
                'product_title' => $variant->product->title ?? null,
                'weight' => $variant->weight,
                'weight_unit' => $variant->weight_unit,
            ],
        ]);
    }

    private function orderListRow(ShopifyOrder $order): array
    {
        return [
            'id' => $order->id,
            'name' => $order->name,
            'shopify_order_id' => $order->shopify_order_id,
            'email' => $order->email,
            'financial_status' => $order->financial_status,
            'fulfillment_status' => $order->fulfillment_status,
            'total_price' => $order->total_price,
            'currency' => $order->currency,
            'shopify_created_at' => optional($order->shopify_created_at)->toIso8601String(),
            'account_name' => $order->connection->clientAccount->company_name ?? null,
            'line_count' => $order->lineItems()->count(),
        ];
    }

    private function orderDetail(ShopifyOrder $order): array
    {
        return [
            'id' => $order->id,
            'name' => $order->name,
            'shopify_order_id' => $order->shopify_order_id,
            'email' => $order->email,
            'financial_status' => $order->financial_status,
            'fulfillment_status' => $order->fulfillment_status,
            'total_price' => $order->total_price,
            'currency' => $order->currency,
            'shopify_created_at' => optional($order->shopify_created_at)->toIso8601String(),
            'cancelled_at' => optional($order->cancelled_at)->toIso8601String(),
            'account_name' => $order->connection->clientAccount->company_name ?? null,
            'customer' => $order->customer_json,
            'shipping_address' => $order->shipping_address_json,
            'line_items' => $order->lineItems->map(fn ($line) => [
                'id' => $line->id,
                'shopify_line_item_id' => $line->shopify_line_item_id,
                'sku' => $line->sku,
                'title' => $line->title,
                'variant_title' => $line->variant_title,
                'quantity' => $line->quantity,
                'fulfillable_quantity' => $line->fulfillable_quantity,
                'fulfilled_quantity' => $line->fulfilled_quantity,
                'price' => $line->price,
            ])->values(),
            'fulfillment_orders' => $order->fulfillmentOrders->map(fn ($fo) => [
                'id' => $fo->id,
                'shopify_fulfillment_order_id' => $fo->shopify_fulfillment_order_id,
                'status' => $fo->status,
                'shopify_location_id' => $fo->shopify_location_id,
                'line_items' => $fo->lineItems->map(fn ($line) => [
                    'id' => $line->id,
                    'shopify_fo_line_item_id' => $line->shopify_fo_line_item_id,
                    'shopify_line_item_id' => $line->shopify_line_item_id,
                    'total_quantity' => $line->total_quantity,
                    'remaining_quantity' => $line->remaining_quantity,
                ])->values(),
            ])->values(),
            'fulfillments' => $order->fulfillments->map(fn ($f) => [
                'id' => $f->id,
                'shopify_fulfillment_id' => $f->shopify_fulfillment_id,
                'status' => $f->status,
                'tracking_company' => $f->tracking_company,
                'tracking_number' => $f->tracking_number,
                'created_at' => optional($f->created_at)->toIso8601String(),
            ])->values(),
        ];
    }
}
