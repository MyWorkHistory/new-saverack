<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ShopifyOrder;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyConnectionService;
use App\Services\ShopifyFulfillmentService;
use App\Services\ShopifyOAuthService;
use App\Services\ShopifyOrderSyncService;
use App\Services\ShopifyProductSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
            'oauth_configured' => app(ShopifyOAuthService::class)->isConfigured(),
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
            'message' => 'Shopify import completed.',
            'connection' => $connections->toPublicArray($connection),
            'import_queued' => false,
        ]);
    }

    public function syncConnection(Request $request, ClientAccount $clientAccount, ShopifyConnectionService $connections): JsonResponse
    {
        return $this->importConnection($request, $clientAccount, $connections);
    }

    public function syncOrders(
        Request $request,
        ClientAccount $clientAccount,
        ShopifyConnectionService $connections,
        ShopifyOrderSyncService $orderSync
    ): JsonResponse {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:unfulfilled,after_date,order_number'],
            'after_date' => ['nullable', 'date'],
            'order_number' => ['nullable', 'string', 'max:64'],
        ]);

        $connection = $connections->getForAccount((int) $clientAccount->id);
        if ($connection === null || ! $connection->hasCredentials()) {
            return response()->json(['message' => 'Connect Shopify credentials first.'], 422);
        }

        $mode = (string) $validated['mode'];

        try {
            if ($mode === 'order_number') {
                $orderNumber = trim((string) ($validated['order_number'] ?? ''));
                if ($orderNumber === '') {
                    return response()->json(['message' => 'Order number is required.'], 422);
                }
                $result = $connections->syncOrderByNumber($connection, $orderNumber, $orderSync);

                return response()->json([
                    'message' => 'Synced order '.$result['order']->name.'.',
                    'queued' => false,
                    'synced' => 1,
                    'mode' => $mode,
                    'order' => [
                        'id' => $result['order']->id,
                        'name' => $result['order']->name,
                        'shopify_order_id' => $result['order']->shopify_order_id,
                    ],
                    'connection' => $connections->toPublicArray($result['connection']),
                ]);
            }

            if ($mode === 'after_date' && empty($validated['after_date'])) {
                return response()->json(['message' => 'After date is required.'], 422);
            }

            $result = $connections->queueOrderResync($connection, [
                'mode' => $mode,
                'after_date' => $validated['after_date'] ?? null,
            ]);

            $msg = $mode === 'after_date'
                ? 'Order re-sync queued for orders on/after '.$validated['after_date'].'.'
                : 'Order re-sync queued for all unfulfilled orders.';

            return response()->json([
                'message' => $msg,
                'queued' => true,
                'mode' => $mode,
                'connection' => $connections->toPublicArray($result['connection']),
            ], 202);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function oauthStart(
        Request $request,
        ClientAccount $clientAccount,
        ShopifyOAuthService $oauth
    ): JsonResponse {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);

        if (! $oauth->isConfigured()) {
            return response()->json([
                'message' => 'Shopify OAuth is not configured on this server. Set SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET in .env.',
            ], 422);
        }

        $validated = $request->validate([
            'shop_domain' => ['required', 'string', 'max:191'],
            'import' => ['nullable', 'boolean'],
        ]);

        $shop = $oauth->normalizeShopDomain((string) $validated['shop_domain']);
        if ($shop === '') {
            return response()->json(['message' => 'Shop domain is required (e.g. test-store-wke6tzxl.myshopify.com).'], 422);
        }

        $user = $request->user();
        $payload = [
            'account_id' => (int) $clientAccount->id,
            'shop' => $shop,
            'user_id' => $user !== null ? $user->id : null,
            'import' => ! array_key_exists('import', $validated) || (bool) $validated['import'],
        ];
        $oauth->rememberPendingInstall($payload);
        $state = $oauth->createState($payload);

        try {
            $url = $oauth->connectUrl($shop, $state);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'authorization_url' => $url,
            'shop_domain' => $shop,
        ]);
    }

    /**
     * Partners App URL entrypoint. Shopify opens this with ?shop=… when testing/installing.
     * Do NOT use the oauth/callback URL as the App URL.
     */
    public function oauthInstall(Request $request, ShopifyOAuthService $oauth, ShopifyConnectionService $connections)
    {
        if (! $oauth->isConfigured()) {
            return response(
                'Shopify OAuth is not configured. Set SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET on the server.',
                503,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $shop = $oauth->normalizeShopDomain((string) $request->query('shop', ''));
        if ($shop === '') {
            return response(
                "Missing shop. Use CRM → Client Account → Settings → Shopify, enter the store domain "
                ."(e.g. test-store-wke6tzxl.myshopify.com), then click Connect With Shopify.\n\n"
                ."App URL must be: ".$oauth->installUri()."\n"
                ."Allowed redirection URL must be: ".$oauth->redirectUri(),
                422,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $query = $request->query();
        if (is_array($query) && isset($query['hmac']) && ! $oauth->verifyCallbackHmac($query)) {
            return response('Invalid Shopify install signature.', 401, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $pending = $oauth->peekPendingInstall($shop);
        $explicitAccountId = (int) $request->query('account_id', 0);
        $accountId = $oauth->resolveAccountIdForShop(
            $shop,
            $explicitAccountId > 0 ? $explicitAccountId : null
        );
        if ($accountId < 1) {
            return response(
                "No CRM client account mapped for shop {$shop}.\n"
                ."Open Connect With Shopify from that account's Settings tab first,\n"
                ."or set SHOPIFY_OAUTH_DEFAULT_ACCOUNT_ID in production .env.",
                422,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            return response(
                "CRM client account {$accountId} was not found. Check SHOPIFY_OAUTH_DEFAULT_ACCOUNT_ID.",
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $shouldImport = $pending !== null
            ? (bool) $pending['import']
            : true;

        $idToken = trim((string) $request->query('id_token', ''));
        if ($idToken !== '') {
            try {
                $tokenPayload = $oauth->exchangeSessionToken($shop, $idToken);
                $oauth->pullPendingInstall($shop);
                $connections->connectAndImport($account, [
                    'shop_domain' => $shop,
                    'admin_api_access_token' => $tokenPayload['access_token'],
                    'refresh_token' => $tokenPayload['refresh_token'] ?? null,
                    'expires_in' => $tokenPayload['expires_in'] ?? null,
                    'refresh_token_expires_in' => $tokenPayload['refresh_token_expires_in'] ?? null,
                    'import' => false,
                ]);
            } catch (Throwable $e) {
                report($e);
                $base = rtrim((string) config('app.url'), '/');

                return redirect()->away(
                    $base.'/admin/clients/accounts/'.$accountId
                    .'?tab=settings&shopify_oauth=error&shopify_oauth_message='
                    .rawurlencode($e->getMessage() !== '' ? $e->getMessage() : 'Shopify token exchange failed.')
                );
            }

            $base = rtrim((string) config('app.url'), '/');

            return redirect()->away(
                $base.'/admin/clients/accounts/'.$accountId.'?tab=settings&shopify_oauth=success'
            );
        }

        $state = $oauth->createState([
            'account_id' => (int) $account->id,
            'shop' => $shop,
            'user_id' => $pending['user_id'] ?? null,
            'import' => $shouldImport,
        ]);

        try {
            return redirect()->away($oauth->authorizationUrl($shop, $state));
        } catch (RuntimeException $e) {
            return response($e->getMessage(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

    public function oauthCallback(
        Request $request,
        ShopifyOAuthService $oauth,
        ShopifyConnectionService $connections
    ): RedirectResponse {
        $failRedirect = function ($message, $accountId = null) {
            $base = rtrim((string) config('app.url'), '/');
            $accountId = $accountId !== null ? (int) $accountId : 0;
            if ($accountId > 0) {
                return redirect()->away(
                    $base.'/admin/clients/accounts/'.$accountId
                    .'?tab=settings&shopify_oauth=error&shopify_oauth_message='.rawurlencode((string) $message)
                );
            }

            return redirect()->away(
                $base.'/admin/clients/accounts?shopify_oauth=error&shopify_oauth_message='.rawurlencode((string) $message)
            );
        };

        // Hitting /callback directly (or using it as Partners App URL) has no code/state.
        $code = trim((string) $request->query('code', ''));
        $shopQ = trim((string) $request->query('shop', ''));
        $stateQ = trim((string) $request->query('state', ''));
        if ($code === '' && $stateQ === '') {
            // If Shopify sent us here as App URL with only shop=, bounce to install.
            if ($shopQ !== '') {
                return redirect()->away(
                    rtrim((string) config('app.url'), '/').'/api/shopify/oauth/install?'.http_build_query($request->query())
                );
            }

            return $failRedirect(
                'This is the OAuth callback URL, not the store. In CRM Settings → Shopify enter '
                .'test-store-wke6tzxl.myshopify.com and click Connect With Shopify. '
                .'Partners App URL must be /api/shopify/oauth/install (not /callback).'
            );
        }

        if (! $oauth->isConfigured()) {
            return $failRedirect('Shopify OAuth is not configured. Set SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET.');
        }

        $query = $request->query();
        if (! $oauth->verifyCallbackHmac(is_array($query) ? $query : [])) {
            return $failRedirect('Invalid Shopify OAuth signature. Check SHOPIFY_CLIENT_SECRET matches the Partners app.');
        }

        $statePayload = $oauth->pullState($stateQ);
        if ($statePayload === null) {
            return $failRedirect('OAuth session expired or cache cleared. Start Connect With Shopify again from CRM Settings.');
        }

        $accountId = (int) $statePayload['account_id'];
        $expectedShop = (string) $statePayload['shop'];
        $callbackShop = $oauth->normalizeShopDomain($shopQ);
        if ($callbackShop === '' || $callbackShop !== $expectedShop) {
            return $failRedirect('Shop domain mismatch during OAuth (expected '.$expectedShop.').', $accountId);
        }

        if ($code === '') {
            return $failRedirect('Missing authorization code from Shopify.', $accountId);
        }

        $account = ClientAccount::query()->find($accountId);
        if ($account === null) {
            return $failRedirect('CRM client account '.$accountId.' was not found.', $accountId);
        }

        try {
            $tokenPayload = $oauth->exchangeCode($callbackShop, $code);
            // Import after redirect on the Settings page so Shopify's callback is not held open.
            $connections->connectAndImport($account, [
                'shop_domain' => $callbackShop,
                'admin_api_access_token' => $tokenPayload['access_token'],
                'refresh_token' => $tokenPayload['refresh_token'] ?? null,
                'expires_in' => $tokenPayload['expires_in'] ?? null,
                'refresh_token_expires_in' => $tokenPayload['refresh_token_expires_in'] ?? null,
                'import' => false,
            ]);
        } catch (Throwable $e) {
            report($e);

            return $failRedirect(
                $e->getMessage() !== '' ? $e->getMessage() : 'Could not complete Shopify OAuth.',
                $accountId
            );
        }

        $base = rtrim((string) config('app.url'), '/');

        return redirect()->away(
            $base.'/admin/clients/accounts/'.$accountId.'?tab=settings&shopify_oauth=success'
        );
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
        ShopifyProductVariant $shopifyVariant
    ): JsonResponse {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:500'],
            'product_title' => ['nullable', 'string', 'max:500'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'max:16'],
        ]);

        // Persist CRM copy immediately so the request finishes under Cloudflare.
        $shopifyVariant->loadMissing('product');
        if (array_key_exists('sku', $validated) && $validated['sku'] !== null) {
            $shopifyVariant->sku = trim((string) $validated['sku']);
        }
        if (array_key_exists('title', $validated) && $validated['title'] !== null) {
            $shopifyVariant->title = trim((string) $validated['title']);
        }
        if (array_key_exists('weight', $validated) && $validated['weight'] !== null) {
            $shopifyVariant->weight = (float) $validated['weight'];
        }
        if (array_key_exists('weight_unit', $validated) && $validated['weight_unit'] !== null) {
            $shopifyVariant->weight_unit = (string) $validated['weight_unit'];
        }
        $shopifyVariant->save();

        if (
            array_key_exists('product_title', $validated)
            && $validated['product_title'] !== null
            && $shopifyVariant->product
        ) {
            $shopifyVariant->product->title = trim((string) $validated['product_title']);
            $shopifyVariant->product->save();
        }

        \App\Jobs\PushShopifyVariantJob::dispatch((int) $shopifyVariant->id, $validated);

        // Also try once after the response (helps when queue workers are down).
        $variantId = (int) $shopifyVariant->id;
        $fields = $validated;
        app()->terminating(static function () use ($variantId, $fields) {
            try {
                (new \App\Jobs\PushShopifyVariantJob($variantId, $fields))
                    ->handle(app(ShopifyProductSyncService::class));
            } catch (Throwable $e) {
                report($e);
            }
        });

        $shopifyVariant->refresh()->load('product');

        return response()->json([
            'message' => 'Saved. Syncing To Shopify…',
            'queued' => true,
            'variant' => [
                'id' => $shopifyVariant->id,
                'sku' => $shopifyVariant->sku,
                'title' => $shopifyVariant->title,
                'product_title' => $shopifyVariant->product->title ?? null,
                'weight' => $shopifyVariant->weight,
                'weight_unit' => $shopifyVariant->weight_unit,
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
