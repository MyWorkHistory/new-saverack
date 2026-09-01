<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyLocation;
use App\Models\ShopifyOrder;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyConnectionService;
use App\Services\ShopifyFulfillmentService;
use App\Services\ShopifyOAuthService;
use App\Services\ShopifyOrderActionService;
use App\Services\ShopifyOrderListService;
use App\Services\ShopifyOrderSyncService;
use App\Services\ShopifyProductSyncService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function listConnections(Request $request, ClientAccount $clientAccount, ShopifyConnectionService $connections): JsonResponse
    {
        $this->assertAdmin($request);
        $this->authorize('view', $clientAccount);

        $rows = $connections->listForAccount((int) $clientAccount->id);

        return response()->json([
            'connections' => array_map(static function ($row) use ($connections) {
                return $connections->toPublicArray($row);
            }, $rows),
            'oauth_configured' => app(ShopifyOAuthService::class)->isConfigured(),
        ]);
    }

    public function showStoreConnection(
        Request $request,
        ClientAccount $clientAccount,
        ClientAccountShopifyConnection $shopifyConnection,
        ShopifyConnectionService $connections
    ): JsonResponse {
        $this->assertAdmin($request);
        $this->authorize('view', $clientAccount);
        $this->assertConnectionAccount($clientAccount, $shopifyConnection);
        $connections->getForAccountConnection((int) $clientAccount->id, (int) $shopifyConnection->id);
        $shopifyConnection->refresh();

        $locations = $shopifyConnection->locations()->orderBy('name')->orderBy('id')->get()->map(function ($loc) {
            return [
                'id' => $loc->id,
                'shopify_location_id' => $loc->shopify_location_id,
                'name' => $loc->name,
                'address_line' => $this->locationAddressLine($loc),
                'active' => (bool) $loc->active,
                'import_orders' => (bool) $loc->import_orders,
                'sync_inventory' => (bool) $loc->sync_inventory,
            ];
        })->values();

        return response()->json([
            'connection' => $connections->toPublicArray($shopifyConnection),
            'locations' => $locations,
            'oauth_configured' => app(ShopifyOAuthService::class)->isConfigured(),
        ]);
    }

    public function updateLocation(
        Request $request,
        ClientAccount $clientAccount,
        ClientAccountShopifyConnection $shopifyConnection,
        ShopifyLocation $shopifyLocation
    ): JsonResponse {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);
        $this->assertConnectionAccount($clientAccount, $shopifyConnection);
        if ((int) $shopifyLocation->connection_id !== (int) $shopifyConnection->id) {
            abort(404);
        }

        $validated = $request->validate([
            'import_orders' => ['sometimes', 'boolean'],
            'sync_inventory' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('import_orders', $validated)) {
            $shopifyLocation->import_orders = (bool) $validated['import_orders'];
        }
        if (array_key_exists('sync_inventory', $validated)) {
            $shopifyLocation->sync_inventory = (bool) $validated['sync_inventory'];
        }
        $shopifyLocation->save();

        return response()->json([
            'location' => [
                'id' => $shopifyLocation->id,
                'shopify_location_id' => $shopifyLocation->shopify_location_id,
                'name' => $shopifyLocation->name,
                'active' => (bool) $shopifyLocation->active,
                'import_orders' => (bool) $shopifyLocation->import_orders,
                'sync_inventory' => (bool) $shopifyLocation->sync_inventory,
            ],
        ]);
    }

    public function disconnectConnection(
        Request $request,
        ClientAccount $clientAccount,
        ClientAccountShopifyConnection $shopifyConnection,
        ShopifyConnectionService $connections
    ): JsonResponse {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);
        $this->assertConnectionAccount($clientAccount, $shopifyConnection);
        $connections->disconnect($shopifyConnection);

        return response()->json([
            'message' => 'Shopify disconnected.',
            'connection' => $connections->toPublicArray($shopifyConnection->fresh()),
        ]);
    }

    public function syncStoreOrders(
        Request $request,
        ClientAccount $clientAccount,
        ClientAccountShopifyConnection $shopifyConnection,
        ShopifyOrderSyncService $orderSync
    ): JsonResponse {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);
        $this->assertConnectionAccount($clientAccount, $shopifyConnection);
        if (! $shopifyConnection->hasCredentials()) {
            return response()->json(['message' => 'Connect Shopify credentials first.'], 422);
        }

        $validated = $request->validate([
            'order_number' => ['nullable', 'string', 'max:64'],
        ]);
        $orderNumber = trim((string) ($validated['order_number'] ?? ''));

        try {
            if ($orderNumber !== '') {
                $result = app(ShopifyConnectionService::class)->syncOrderByNumber(
                    $shopifyConnection,
                    $orderNumber,
                    $orderSync
                );

                return response()->json([
                    'message' => 'Imported order '.$result['order']->name.'.',
                    'synced' => 1,
                    'order' => [
                        'id' => $result['order']->id,
                        'name' => $result['order']->name,
                    ],
                    'connection' => app(ShopifyConnectionService::class)->toPublicArray($result['connection']),
                ]);
            }

            $synced = $orderSync->importOpenOrders($shopifyConnection);
            $shopifyConnection->last_order_sync_at = now();
            $shopifyConnection->save();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Synced '.$synced.' open order'.($synced === 1 ? '' : 's').'.',
            'synced' => $synced,
            'connection' => app(ShopifyConnectionService::class)->toPublicArray($shopifyConnection->fresh()),
        ]);
    }

    public function syncStoreProducts(
        Request $request,
        ClientAccount $clientAccount,
        ClientAccountShopifyConnection $shopifyConnection,
        ShopifyProductSyncService $productSync,
        ShopifyConnectionService $connections
    ): JsonResponse {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);
        $this->assertConnectionAccount($clientAccount, $shopifyConnection);
        if (! $shopifyConnection->hasCredentials()) {
            return response()->json(['message' => 'Connect Shopify credentials first.'], 422);
        }

        try {
            $catalog = $productSync->importActiveProducts($shopifyConnection);
            $shopifyConnection->last_product_sync_at = now();
            $shopifyConnection->last_sync_at = now();
            $shopifyConnection->save();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Synced '.(int) ($catalog['products'] ?? 0).' products.',
            'products' => (int) ($catalog['products'] ?? 0),
            'variants' => (int) ($catalog['variants'] ?? 0),
            'connection' => $connections->toPublicArray($shopifyConnection->fresh()),
        ]);
    }

    public function pushStoreInventory(
        Request $request,
        ClientAccount $clientAccount,
        ClientAccountShopifyConnection $shopifyConnection,
        ShopifyProductSyncService $productSync,
        ShopifyConnectionService $connections
    ): JsonResponse {
        $this->assertAdmin($request);
        $this->authorize('update', $clientAccount);
        $this->assertConnectionAccount($clientAccount, $shopifyConnection);
        if (! $shopifyConnection->hasCredentials()) {
            return response()->json(['message' => 'Connect Shopify credentials first.'], 422);
        }

        $pushed = 0;
        $variants = $shopifyConnection->variants()->whereNotNull('shopify_inventory_item_id')->get();
        try {
            foreach ($variants as $variant) {
                $pushed += $productSync->pushInventoryToShopify($variant);
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Pushed inventory for '.$pushed.' location'.($pushed === 1 ? '' : 's').'.',
            'pushed' => $pushed,
            'connection' => $connections->toPublicArray($shopifyConnection->fresh()),
        ]);
    }

    private function assertConnectionAccount(ClientAccount $clientAccount, ClientAccountShopifyConnection $connection): void
    {
        if ((int) $connection->client_account_id !== (int) $clientAccount->id) {
            abort(404);
        }
    }

    private function locationAddressLine(ShopifyLocation $location): string
    {
        $addr = is_array($location->address_json) ? $location->address_json : [];
        $parts = array_filter([
            trim((string) ($addr['address1'] ?? '')),
            trim((string) ($addr['address2'] ?? '')),
            trim((string) ($addr['city'] ?? '')),
            trim((string) ($addr['province'] ?? $addr['provinceCode'] ?? '')),
            trim((string) ($addr['zip'] ?? '')),
        ], static function ($part) {
            return $part !== '';
        });

        return implode(', ', $parts);
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

            if (empty($result['queued'])) {
                $synced = (int) ($result['synced'] ?? 0);

                return response()->json([
                    'message' => 'Synced '.$synced.' open order'.($synced === 1 ? '' : 's').'.',
                    'queued' => false,
                    'synced' => $synced,
                    'mode' => $mode,
                    'connection' => $connections->toPublicArray($result['connection']),
                ]);
            }

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

        try {
            $connection = app(ShopifyConnectionService::class)->preparePendingConnection($clientAccount, $shop);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user = $request->user();
        $payload = [
            'account_id' => (int) $clientAccount->id,
            'shop' => $shop,
            'user_id' => $user !== null ? $user->id : null,
            'import' => ! array_key_exists('import', $validated) || (bool) $validated['import'],
            'connection_id' => (int) $connection->id,
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
            'connection_id' => (int) $connection->id,
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
                $connection = $connections->connectAndImport($account, [
                    'shop_domain' => $shop,
                    'connection_id' => isset($pending['connection_id']) ? (int) $pending['connection_id'] : null,
                    'admin_api_access_token' => $tokenPayload['access_token'],
                    'refresh_token' => $tokenPayload['refresh_token'] ?? null,
                    'expires_in' => $tokenPayload['expires_in'] ?? null,
                    'refresh_token_expires_in' => $tokenPayload['refresh_token_expires_in'] ?? null,
                    'import' => $shouldImport,
                ]);
            } catch (Throwable $e) {
                report($e);

                return $this->oauthCrmRedirect(
                    $accountId,
                    isset($pending['connection_id']) ? (int) $pending['connection_id'] : null,
                    false,
                    $e->getMessage() !== '' ? $e->getMessage() : 'Shopify token exchange failed.'
                );
            }

            return $this->oauthCrmRedirect($accountId, (int) $connection->id, true);
        }

        $state = $oauth->createState([
            'account_id' => (int) $account->id,
            'shop' => $shop,
            'user_id' => $pending['user_id'] ?? null,
            'import' => $shouldImport,
            'connection_id' => isset($pending['connection_id']) ? (int) $pending['connection_id'] : null,
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
        $failRedirect = function ($message, $accountId = null, $connectionId = null) {
            return $this->oauthCrmRedirect(
                $accountId !== null ? (int) $accountId : null,
                $connectionId !== null ? (int) $connectionId : null,
                false,
                (string) $message
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
            $shouldImport = array_key_exists('import', $statePayload)
                ? (bool) $statePayload['import']
                : true;

            $connection = $connections->connectAndImport($account, [
                'shop_domain' => $callbackShop,
                'connection_id' => isset($statePayload['connection_id']) ? (int) $statePayload['connection_id'] : null,
                'admin_api_access_token' => $tokenPayload['access_token'],
                'refresh_token' => $tokenPayload['refresh_token'] ?? null,
                'expires_in' => $tokenPayload['expires_in'] ?? null,
                'refresh_token_expires_in' => $tokenPayload['refresh_token_expires_in'] ?? null,
                'import' => $shouldImport,
            ]);
        } catch (Throwable $e) {
            report($e);

            return $failRedirect(
                $e->getMessage() !== '' ? $e->getMessage() : 'Could not complete Shopify OAuth.',
                $accountId,
                isset($statePayload['connection_id']) ? (int) $statePayload['connection_id'] : null
            );
        }

        return $this->oauthCrmRedirect($accountId, (int) $connection->id, true);
    }

    public function ordersIndex(Request $request, ShopifyOrderListService $orders): JsonResponse
    {
        $this->assertAdmin($request);

        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $page = $orders->filteredQuery($request)->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (ShopifyOrder $order) => $orders->listRow($order))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function ordersMeta(Request $request, ShopifyOrderListService $orders): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json($orders->filterMeta());
    }

    public function ordersExport(Request $request, ShopifyOrderListService $orders): StreamedResponse
    {
        $this->assertAdmin($request);

        $ids = $request->query('ids');
        $query = $orders->filteredQuery($request);
        if (is_string($ids) && trim($ids) !== '') {
            $idList = array_filter(array_map('intval', explode(',', $ids)));
            if ($idList !== []) {
                $query->whereIn('id', $idList);
            }
        }

        $rows = $query->get();
        $filename = 'shopify-orders-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $orders) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Status',
                'Order #',
                'Recipient',
                'Order Date',
                'Country',
                'Shipping Method',
                'Account',
            ]);
            foreach ($rows as $order) {
                $row = $orders->listRow($order);
                fputcsv($out, [
                    $orders->displayStatusLabel((string) $row['display_status']),
                    $row['name'],
                    $row['recipient_name'],
                    $row['shopify_created_at'],
                    $row['country'],
                    $row['shipping_method'],
                    $row['account_name'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function orderSync(Request $request, ShopifyOrder $shopifyOrder, ShopifyOrderActionService $actions): JsonResponse
    {
        $this->assertAdmin($request);

        try {
            $order = $actions->syncOrder($shopifyOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not sync order from Shopify.'], 500);
        }

        return response()->json(['order' => app(ShopifyOrderListService::class)->listRow($order)]);
    }

    public function orderHold(Request $request, ShopifyOrder $shopifyOrder, ShopifyOrderActionService $actions): JsonResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['required', 'string', 'max:64'],
        ]);

        try {
            $order = $actions->holdOrder($shopifyOrder, $validated['reasons']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not hold order.'], 500);
        }

        return response()->json(['order' => app(ShopifyOrderListService::class)->listRow($order)]);
    }

    public function orderCancel(Request $request, ShopifyOrder $shopifyOrder, ShopifyOrderActionService $actions): JsonResponse
    {
        $this->assertAdmin($request);

        try {
            $order = $actions->cancelOrder($shopifyOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not cancel order.'], 500);
        }

        return response()->json(['order' => app(ShopifyOrderListService::class)->listRow($order)]);
    }

    public function orderFulfillAll(Request $request, ShopifyOrder $shopifyOrder, ShopifyOrderActionService $actions): JsonResponse
    {
        $this->assertAdmin($request);

        try {
            $result = $actions->fulfillAllRemaining($shopifyOrder, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not mark order fulfilled.'], 500);
        }

        return response()->json([
            'order' => app(ShopifyOrderListService::class)->listRow($result['order']),
        ]);
    }

    public function ordersBulkHold(Request $request, ShopifyOrderActionService $actions, ShopifyOrderListService $orders): JsonResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['required', 'string', 'max:64'],
        ]);

        $updated = [];
        $errors = [];
        foreach ($validated['ids'] as $id) {
            $order = ShopifyOrder::query()->find((int) $id);
            if ($order === null) {
                continue;
            }
            try {
                $updated[] = $orders->listRow($actions->holdOrder($order, $validated['reasons']));
            } catch (RuntimeException $e) {
                $errors[] = ['id' => (int) $id, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    public function ordersBulkCancel(Request $request, ShopifyOrderActionService $actions, ShopifyOrderListService $orders): JsonResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $updated = [];
        $errors = [];
        foreach ($validated['ids'] as $id) {
            $order = ShopifyOrder::query()->find((int) $id);
            if ($order === null) {
                continue;
            }
            try {
                $updated[] = $orders->listRow($actions->cancelOrder($order));
            } catch (RuntimeException $e) {
                $errors[] = ['id' => (int) $id, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    public function ordersBulkFulfill(Request $request, ShopifyOrderActionService $actions, ShopifyOrderListService $orders): JsonResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $updated = [];
        $errors = [];
        foreach ($validated['ids'] as $id) {
            $order = ShopifyOrder::query()->find((int) $id);
            if ($order === null) {
                continue;
            }
            try {
                $result = $actions->fulfillAllRemaining($order, $request->user());
                $updated[] = $orders->listRow($result['order']);
            } catch (RuntimeException $e) {
                $errors[] = ['id' => (int) $id, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    public function orderPackingSlip(Request $request, ShopifyOrder $shopifyOrder, ShopifyOrderListService $orders)
    {
        $this->assertAdmin($request);
        $shopifyOrder->load(['connection.clientAccount', 'lineItems']);

        $accountName = 'Save Rack';
        $connection = $shopifyOrder->connection;
        if ($connection !== null && $connection->clientAccount !== null) {
            $companyName = trim((string) $connection->clientAccount->company_name);
            if ($companyName !== '') {
                $accountName = $companyName;
            }
        }

        $pdf = Pdf::loadView('pdf.shopify.order-packing-slip', [
            'order' => $shopifyOrder,
            'accountName' => $accountName,
            'recipientName' => $orders->recipientName($shopifyOrder),
            'shippingAddress' => is_array($shopifyOrder->shipping_address_json) ? $shopifyOrder->shipping_address_json : [],
        ])->setPaper('letter');

        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($shopifyOrder->name ?? 'order')) ?: 'order';

        return $pdf->stream('shopify-'.$name.'-packing-slip.pdf');
    }

    public function ordersShow(Request $request, ShopifyOrder $shopifyOrder, ShopifyOrderListService $orders): JsonResponse
    {
        $this->assertAdmin($request);
        $shopifyOrder->load([
            'connection.clientAccount:id,company_name',
            'lineItems',
            'fulfillmentOrders.lineItems',
            'fulfillments',
        ]);

        return response()->json(['order' => $this->orderDetail($shopifyOrder, $orders)]);
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
            'order' => $this->orderDetail($order, app(ShopifyOrderListService::class)),
            'fulfillment' => [
                'id' => $result['fulfillment']->id,
                'shopify_fulfillment_id' => $result['fulfillment']->shopify_fulfillment_id,
                'tracking_company' => $result['fulfillment']->tracking_company,
                'tracking_number' => $result['fulfillment']->tracking_number,
            ],
        ]);
    }

    public function inventoryAccounts(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $connections = ClientAccountShopifyConnection::query()
            ->with(['clientAccount:id,company_name'])
            ->whereNotNull('shop_domain')
            ->where('shop_domain', '!=', '')
            ->orderBy('id')
            ->get();

        $seen = [];
        $data = [];
        foreach ($connections as $connection) {
            if (! $connection->hasCredentials()) {
                continue;
            }
            $account = $connection->clientAccount;
            if ($account === null) {
                continue;
            }
            $accountId = (int) $account->id;
            if (isset($seen[$accountId])) {
                continue;
            }
            $seen[$accountId] = true;
            $data[] = [
                'id' => $accountId,
                'company_name' => (string) ($account->company_name ?? ''),
                'connection_id' => (int) $connection->id,
            ];
        }

        usort($data, function ($a, $b) {
            return strcasecmp((string) $a['company_name'], (string) $b['company_name']);
        });

        return response()->json(['data' => $data]);
    }

    public function inventoryIndex(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $q = trim((string) $request->query('q', ''));
        $accountId = (int) $request->query('client_account_id', 0);
        $status = strtolower(trim((string) $request->query('status', '')));
        $bundle = strtolower(trim((string) $request->query('bundle', '')));
        $allocated = strtolower(trim((string) $request->query('allocated', 'all')));
        $backorder = strtolower(trim((string) $request->query('backorder', 'all')));
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));

        $query = ShopifyProductVariant::query()
            ->with(['product', 'connection.clientAccount:id,company_name'])
            ->orderByDesc('id');

        if ($status === 'active') {
            $query->whereHas('product', function ($builder) {
                $builder->where(function ($p) {
                    $p->whereNull('status')->orWhere('status', 'active');
                });
            });
        } elseif ($status === 'inactive') {
            $query->whereHas('product', function ($builder) {
                $builder->whereNotNull('status')->where('status', '!=', 'active');
            });
        }

        // CRM bundle kind filter (Standard Product vs Bundle).
        if ($bundle === 'yes') {
            $query->whereHas('product', function ($builder) {
                $builder->where('crm_product_kind', \App\Models\ShopifyProduct::KIND_BUNDLE);
            });
        } elseif ($bundle === 'no') {
            $query->whereHas('product', function ($builder) {
                $builder->where(function ($p) {
                    $p->whereNull('crm_product_kind')
                        ->orWhere('crm_product_kind', '!=', \App\Models\ShopifyProduct::KIND_BUNDLE);
                });
            });
        }

        // Allocated / backorder are 0 until CRM inventory is connected.
        if ($allocated === 'show') {
            $query->whereRaw('1 = 0');
        }
        if ($backorder === 'show') {
            $query->whereRaw('1 = 0');
        }
        // allocated=hide / backorder=hide / all: no extra filter (all rows are 0 today)

        if ($accountId > 0) {
            $query->whereHas('connection', function ($builder) use ($accountId) {
                $builder->where('client_account_id', $accountId);
            });
        }

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('sku', 'like', '%'.$q.'%')
                    ->orWhere('title', 'like', '%'.$q.'%')
                    ->orWhere('barcode', 'like', '%'.$q.'%')
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
            ->groupBy(function ($row) {
                return $row->connection_id.'|'.$row->shopify_inventory_item_id;
            });

        $locations = \App\Models\ShopifyLocation::query()
            ->whereIn('connection_id', $connectionIds)
            ->get()
            ->groupBy('connection_id');

        return response()->json([
            'data' => collect($page->items())->map(function (ShopifyProductVariant $variant) use ($levels, $locations) {
                $key = $variant->connection_id.'|'.$variant->shopify_inventory_item_id;
                $levelRows = $levels->get($key, collect());
                $locMap = ($locations->get($variant->connection_id) ?? collect())->keyBy('shopify_location_id');

                $onHand = (int) $levelRows->sum('available');
                $productStatus = $this->normalizeCrmProductStatus(
                    $variant->product ? (string) ($variant->product->status ?? 'active') : 'active'
                );
                $kind = \App\Models\ShopifyProduct::normalizeCrmProductKind(
                    $variant->product->crm_product_kind ?? null
                );

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'title' => $variant->title,
                    'product_title' => $variant->product->title ?? null,
                    'barcode' => $variant->barcode,
                    'image_url' => $variant->displayImageUrl(),
                    'shopify_variant_id' => $variant->shopify_variant_id,
                    'shopify_product_id' => $variant->product->shopify_product_id ?? null,
                    'weight' => $variant->weight,
                    'weight_unit' => $variant->weight_unit,
                    'status' => $productStatus,
                    'product_type' => $kind,
                    'product_type_label' => \App\Models\ShopifyProduct::crmProductKindLabel($kind),
                    'bundle' => $kind === \App\Models\ShopifyProduct::KIND_BUNDLE,
                    'on_hand' => $onHand,
                    'allocated' => 0,
                    'backorder' => 0,
                    'client_account_id' => $variant->connection && $variant->connection->client_account_id
                        ? (int) $variant->connection->client_account_id
                        : null,
                    'connection_id' => (int) $variant->connection_id,
                    'account_name' => optional(optional($variant->connection)->clientAccount)->company_name,
                    'inventory' => $levelRows->map(function ($level) use ($locMap) {
                        $loc = $locMap->get($level->shopify_location_id);

                        return [
                            'location_id' => $level->shopify_location_id,
                            'location_name' => $loc->name ?? $level->shopify_location_id,
                            'available' => (int) $level->available,
                        ];
                    })->values(),
                    'available_total' => $onHand,
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

        $levelMap = $levels->keyBy('shopify_location_id');
        $inventory = $locMap->values()->map(function ($loc) use ($levelMap) {
            $level = $levelMap->get($loc->shopify_location_id);

            return [
                'location_id' => $loc->shopify_location_id,
                'location_name' => $loc->name ?? $loc->shopify_location_id,
                'available' => $level !== null ? (int) $level->available : '',
            ];
        })->values();

        $kind = \App\Models\ShopifyProduct::normalizeCrmProductKind(
            $shopifyVariant->product->crm_product_kind ?? null
        );
        $productStatus = $this->normalizeCrmProductStatus(
            $shopifyVariant->product ? (string) ($shopifyVariant->product->status ?? 'active') : 'active'
        );

        $components = [];
        if ($kind === \App\Models\ShopifyProduct::KIND_BUNDLE) {
            $components = $this->serializeBundleComponents($shopifyVariant);
        }

        // Pre-generate barcode label in the background when missing so Print is fast.
        $labelPath = trim((string) ($shopifyVariant->barcode_label_path ?? ''));
        if ($labelPath === '' || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($labelPath)) {
            $payload = trim((string) ($shopifyVariant->barcode ?? ''));
            if ($payload === '') {
                $payload = trim((string) ($shopifyVariant->sku ?? ''));
            }
            if ($payload !== '') {
                \App\Jobs\GenerateShopifyVariantBarcodeLabelJob::dispatch((int) $shopifyVariant->id);
            }
        }

        return response()->json([
            'variant' => [
                'id' => $shopifyVariant->id,
                'sku' => $shopifyVariant->sku,
                'title' => $shopifyVariant->title,
                'product_title' => $shopifyVariant->product->title ?? null,
                'image_url' => $shopifyVariant->displayImageUrl(),
                'weight' => $shopifyVariant->weight,
                'weight_unit' => $shopifyVariant->weight_unit,
                'barcode' => $shopifyVariant->barcode,
                'length' => $shopifyVariant->length,
                'width' => $shopifyVariant->width,
                'height' => $shopifyVariant->height,
                'dimension_unit' => $shopifyVariant->dimension_unit,
                'shopify_variant_id' => $shopifyVariant->shopify_variant_id,
                'shopify_product_id' => $shopifyVariant->product->shopify_product_id ?? null,
                'connection_id' => (int) $shopifyVariant->connection_id,
                'client_account_id' => $shopifyVariant->connection && $shopifyVariant->connection->client_account_id
                    ? (int) $shopifyVariant->connection->client_account_id
                    : null,
                'crm_locked_at' => optional($shopifyVariant->crm_locked_at)->toIso8601String(),
                'account_name' => optional(optional($shopifyVariant->connection)->clientAccount)->company_name,
                'status' => $productStatus,
                'product_type' => $kind,
                'product_type_label' => \App\Models\ShopifyProduct::crmProductKindLabel($kind),
                'bundle' => $kind === \App\Models\ShopifyProduct::KIND_BUNDLE,
                'bundle_components' => $components,
                'inventory' => $inventory,
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
            'barcode' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'max:16'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'dimension_unit' => ['nullable', 'string', 'max:16'],
            'inventory' => ['nullable', 'array'],
            'inventory.*.location_id' => ['required_with:inventory', 'string', 'max:64'],
            'inventory.*.available' => ['required_with:inventory', 'integer'],
        ]);

        // Persist CRM copy immediately so the request finishes under Cloudflare.
        $shopifyVariant->loadMissing('product');
        $labelFieldsChanged = false;
        if (array_key_exists('sku', $validated) && $validated['sku'] !== null) {
            $newSku = trim((string) $validated['sku']);
            if ($newSku !== trim((string) ($shopifyVariant->sku ?? ''))) {
                $labelFieldsChanged = true;
            }
            $shopifyVariant->sku = $newSku;
        }
        if (array_key_exists('title', $validated) && $validated['title'] !== null) {
            $newTitle = trim((string) $validated['title']);
            if ($newTitle !== trim((string) ($shopifyVariant->title ?? ''))) {
                $labelFieldsChanged = true;
            }
            $shopifyVariant->title = $newTitle;
        }
        if (array_key_exists('weight', $validated) && $validated['weight'] !== null) {
            $shopifyVariant->weight = (float) $validated['weight'];
        }
        if (array_key_exists('weight_unit', $validated) && $validated['weight_unit'] !== null) {
            $shopifyVariant->weight_unit = (string) $validated['weight_unit'];
        }
        if (array_key_exists('barcode', $validated) && $validated['barcode'] !== null) {
            $newBarcode = trim((string) $validated['barcode']);
            if ($newBarcode !== trim((string) ($shopifyVariant->barcode ?? ''))) {
                $labelFieldsChanged = true;
            }
            $shopifyVariant->barcode = $newBarcode;
        }
        foreach (['length', 'width', 'height'] as $dimKey) {
            if (array_key_exists($dimKey, $validated) && $validated[$dimKey] !== null) {
                $shopifyVariant->{$dimKey} = (float) $validated[$dimKey];
            }
        }
        if (array_key_exists('dimension_unit', $validated) && $validated['dimension_unit'] !== null) {
            $shopifyVariant->dimension_unit = (string) $validated['dimension_unit'];
        }
        $shopifyVariant->save();

        $inventoryLevels = is_array($validated['inventory'] ?? null) ? $validated['inventory'] : [];
        unset($validated['inventory']);
        if ($inventoryLevels !== []) {
            $itemId = trim((string) $shopifyVariant->shopify_inventory_item_id);
            foreach ($inventoryLevels as $level) {
                $locId = \App\Support\ShopifyGid::toId((string) ($level['location_id'] ?? ''));
                if ($itemId === '' || $locId === '') {
                    continue;
                }
                \App\Models\ShopifyInventoryLevel::query()->updateOrCreate(
                    [
                        'connection_id' => $shopifyVariant->connection_id,
                        'shopify_inventory_item_id' => $itemId,
                        'shopify_location_id' => $locId,
                    ],
                    [
                        'available' => (int) $level['available'],
                        'crm_set_at' => now(),
                    ]
                );
            }
        }

        if (
            array_key_exists('product_title', $validated)
            && $validated['product_title'] !== null
            && $shopifyVariant->product
        ) {
            $newTitle = trim((string) $validated['product_title']);
            if ($newTitle !== trim((string) ($shopifyVariant->product->title ?? ''))) {
                $labelFieldsChanged = true;
            }
            $shopifyVariant->product->title = $newTitle;
            $shopifyVariant->product->save();
        }

        \App\Jobs\PushShopifyVariantJob::dispatch((int) $shopifyVariant->id, $validated);

        if ($labelFieldsChanged) {
            \App\Jobs\GenerateShopifyVariantBarcodeLabelJob::dispatch((int) $shopifyVariant->id);
            $labelVariantId = (int) $shopifyVariant->id;
            app()->terminating(static function () use ($labelVariantId) {
                try {
                    (new \App\Jobs\GenerateShopifyVariantBarcodeLabelJob($labelVariantId))
                        ->handle(app(\App\Services\ShopifyVariantBarcodeLabelService::class));
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        // Also try once after the response (helps when queue workers are down).
        $variantId = (int) $shopifyVariant->id;
        $fields = $validated;
        $levels = $inventoryLevels;
        app()->terminating(static function () use ($variantId, $fields, $levels) {
            try {
                $products = app(ShopifyProductSyncService::class);
                (new \App\Jobs\PushShopifyVariantJob($variantId, $fields))->handle($products);
                $variant = \App\Models\ShopifyProductVariant::query()->with('connection')->find($variantId);
                if ($variant !== null) {
                    $products->pushInventoryToShopify($variant, $levels !== [] ? $levels : null);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });

        $shopifyVariant->refresh()->load(['product', 'connection.clientAccount']);

        return response()->json([
            'message' => 'Saved. Syncing To Shopify…',
            'queued' => true,
            'variant' => $this->serializeInventoryVariantDetail($shopifyVariant),
        ]);
    }

    public function updateProductSettings(Request $request, ShopifyProductVariant $shopifyVariant): JsonResponse
    {
        $this->assertAdmin($request);
        $shopifyVariant->loadMissing(['product', 'connection']);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactive'],
            'product_type' => ['required', 'string', 'in:standard,bundle'],
        ]);

        $product = $shopifyVariant->product;
        if ($product === null) {
            return response()->json(['message' => 'Product missing for this variant.'], 422);
        }

        $status = strtolower(trim((string) $validated['status'])) === 'inactive' ? 'inactive' : 'active';
        $kind = \App\Models\ShopifyProduct::normalizeCrmProductKind($validated['product_type']);

        $product->status = $status;
        $product->crm_product_kind = $kind;
        $product->save();

        // Push Active/Inactive to Shopify when connected (CRM type stays local).
        try {
            app(ShopifyProductSyncService::class)->pushProductStatusToShopify($product, $status);
        } catch (Throwable $e) {
            report($e);
        }

        $shopifyVariant->refresh()->load(['product', 'connection.clientAccount']);

        return response()->json([
            'message' => 'Product settings saved.',
            'variant' => $this->serializeInventoryVariantDetail($shopifyVariant),
        ]);
    }

    public function uploadVariantImage(Request $request, ShopifyProductVariant $shopifyVariant): JsonResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['image'];
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = 'jpg';
        }
        $dir = 'shopify/crm-images/'.$shopifyVariant->connection_id;
        $path = $file->storeAs($dir, 'variant-'.$shopifyVariant->id.'.'.$ext, 'public');

        $old = trim((string) ($shopifyVariant->crm_image_path ?? ''));
        if ($old !== '' && $old !== $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($old)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
        }

        $shopifyVariant->crm_image_path = $path;
        $shopifyVariant->save();

        return response()->json([
            'message' => 'Image updated.',
            'image_url' => $shopifyVariant->displayImageUrl(),
            'variant' => $this->serializeInventoryVariantDetail(
                $shopifyVariant->fresh(['product', 'connection.clientAccount'])
            ),
        ]);
    }

    public function barcodeLabel(Request $request, ShopifyProductVariant $shopifyVariant)
    {
        $this->assertAdmin($request);
        $shopifyVariant->loadMissing('product');

        $labels = app(\App\Services\ShopifyVariantBarcodeLabelService::class);
        $path = $labels->ensureLabel($shopifyVariant, true);
        if ($path === null || $path === '') {
            return response()->json([
                'message' => 'Add a barcode or SKU before printing a label.',
            ], 422);
        }

        $absolute = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
        if (! is_file($absolute)) {
            $path = $labels->ensureLabel($shopifyVariant, true);
            $absolute = \Illuminate\Support\Facades\Storage::disk('public')->path((string) $path);
        }

        return response()->file($absolute, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="barcode-label-'.$shopifyVariant->id.'.svg"',
        ]);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response|JsonResponse
     */
    public function barcodeLabelPdf(Request $request, ShopifyProductVariant $shopifyVariant)
    {
        $this->assertAdmin($request);
        $shopifyVariant->loadMissing('product');

        try {
            return app(\App\Services\ShopifyVariantBarcodeLabelService::class)
                ->streamPdf($shopifyVariant);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not generate barcode label.',
            ], 500);
        }
    }

    public function bundleComponents(Request $request, ShopifyProductVariant $shopifyVariant): JsonResponse
    {
        $this->assertAdmin($request);
        $shopifyVariant->loadMissing('product');

        return response()->json([
            'components' => $this->serializeBundleComponents($shopifyVariant),
        ]);
    }

    public function syncBundleComponents(Request $request, ShopifyProductVariant $shopifyVariant): JsonResponse
    {
        $this->assertAdmin($request);
        $shopifyVariant->loadMissing(['product', 'connection']);

        $kind = \App\Models\ShopifyProduct::normalizeCrmProductKind(
            $shopifyVariant->product->crm_product_kind ?? null
        );
        if ($kind !== \App\Models\ShopifyProduct::KIND_BUNDLE) {
            return response()->json([
                'message' => 'Set product type to Bundle before adding components.',
            ], 422);
        }

        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.component_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $connectionId = (int) $shopifyVariant->connection_id;
        $parentId = (int) $shopifyVariant->id;
        $seen = [];
        $rows = [];
        foreach ($validated['items'] as $item) {
            $componentId = (int) $item['component_variant_id'];
            if ($componentId === $parentId || isset($seen[$componentId])) {
                continue;
            }
            $exists = ShopifyProductVariant::query()
                ->where('id', $componentId)
                ->where('connection_id', $connectionId)
                ->exists();
            if (! $exists) {
                continue;
            }
            $seen[$componentId] = true;
            $rows[] = [
                'parent_variant_id' => $parentId,
                'component_variant_id' => $componentId,
                'quantity' => (int) $item['quantity'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        \App\Models\ShopifyVariantBundleComponent::query()
            ->where('parent_variant_id', $parentId)
            ->delete();

        if ($rows !== []) {
            \App\Models\ShopifyVariantBundleComponent::query()->insert($rows);
        }

        return response()->json([
            'message' => 'Bundle components saved.',
            'components' => $this->serializeBundleComponents($shopifyVariant->fresh()),
        ]);
    }

    public function updateBundleComponent(
        Request $request,
        ShopifyProductVariant $shopifyVariant,
        \App\Models\ShopifyVariantBundleComponent $component
    ): JsonResponse {
        $this->assertAdmin($request);
        if ((int) $component->parent_variant_id !== (int) $shopifyVariant->id) {
            abort(404);
        }
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);
        $component->quantity = (int) $validated['quantity'];
        $component->save();

        return response()->json([
            'message' => 'Quantity updated.',
            'components' => $this->serializeBundleComponents($shopifyVariant),
        ]);
    }

    public function destroyBundleComponent(
        Request $request,
        ShopifyProductVariant $shopifyVariant,
        \App\Models\ShopifyVariantBundleComponent $component
    ): JsonResponse {
        $this->assertAdmin($request);
        if ((int) $component->parent_variant_id !== (int) $shopifyVariant->id) {
            abort(404);
        }
        $component->delete();

        return response()->json([
            'message' => 'Component removed.',
            'components' => $this->serializeBundleComponents($shopifyVariant),
        ]);
    }

    public function searchBundleCandidates(Request $request, ShopifyProductVariant $shopifyVariant): JsonResponse
    {
        $this->assertAdmin($request);
        $q = trim((string) $request->query('q', ''));
        $exclude = \App\Models\ShopifyVariantBundleComponent::query()
            ->where('parent_variant_id', $shopifyVariant->id)
            ->pluck('component_variant_id')
            ->map(static function ($id) {
                return (int) $id;
            })
            ->all();
        $exclude[] = (int) $shopifyVariant->id;

        $query = ShopifyProductVariant::query()
            ->with(['product'])
            ->where('connection_id', $shopifyVariant->connection_id)
            ->whereNotIn('id', $exclude)
            ->orderByDesc('id')
            ->limit(40);

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($builder) use ($like) {
                $builder->where('sku', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhereHas('product', function ($p) use ($like) {
                        $p->where('title', 'like', $like);
                    });
            });
        }

        $rows = $query->get()->map(function (ShopifyProductVariant $v) {
            return [
                'id' => (int) $v->id,
                'sku' => (string) ($v->sku ?? ''),
                'title' => (string) ($v->product->title ?? $v->title ?? ''),
                'image_url' => $v->displayImageUrl(),
            ];
        })->values();

        return response()->json(['products' => $rows]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeBundleComponents(ShopifyProductVariant $parent): array
    {
        $rows = \App\Models\ShopifyVariantBundleComponent::query()
            ->with(['componentVariant.product'])
            ->where('parent_variant_id', $parent->id)
            ->orderBy('id')
            ->get();

        return $rows->map(function (\App\Models\ShopifyVariantBundleComponent $row) {
            $v = $row->componentVariant;

            return [
                'id' => (int) $row->id,
                'component_variant_id' => (int) $row->component_variant_id,
                'quantity' => (int) $row->quantity,
                'sku' => $v ? (string) ($v->sku ?? '') : '',
                'title' => $v ? (string) ($v->product->title ?? $v->title ?? '') : '',
                'image_url' => $v ? $v->displayImageUrl() : null,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInventoryVariantDetail(ShopifyProductVariant $shopifyVariant): array
    {
        $shopifyVariant->loadMissing(['product', 'connection.clientAccount']);
        $kind = \App\Models\ShopifyProduct::normalizeCrmProductKind(
            $shopifyVariant->product->crm_product_kind ?? null
        );
        $productStatus = $this->normalizeCrmProductStatus(
            $shopifyVariant->product ? (string) ($shopifyVariant->product->status ?? 'active') : 'active'
        );

        return [
            'id' => $shopifyVariant->id,
            'sku' => $shopifyVariant->sku,
            'title' => $shopifyVariant->title,
            'product_title' => $shopifyVariant->product->title ?? null,
            'image_url' => $shopifyVariant->displayImageUrl(),
            'weight' => $shopifyVariant->weight,
            'weight_unit' => $shopifyVariant->weight_unit,
            'barcode' => $shopifyVariant->barcode,
            'length' => $shopifyVariant->length,
            'width' => $shopifyVariant->width,
            'height' => $shopifyVariant->height,
            'dimension_unit' => $shopifyVariant->dimension_unit,
            'shopify_variant_id' => $shopifyVariant->shopify_variant_id,
            'shopify_product_id' => $shopifyVariant->product->shopify_product_id ?? null,
            'connection_id' => (int) $shopifyVariant->connection_id,
            'client_account_id' => $shopifyVariant->connection && $shopifyVariant->connection->client_account_id
                ? (int) $shopifyVariant->connection->client_account_id
                : null,
            'crm_locked_at' => optional($shopifyVariant->crm_locked_at)->toIso8601String(),
            'account_name' => optional(optional($shopifyVariant->connection)->clientAccount)->company_name,
            'status' => $productStatus,
            'product_type' => $kind,
            'product_type_label' => \App\Models\ShopifyProduct::crmProductKindLabel($kind),
            'bundle' => $kind === \App\Models\ShopifyProduct::KIND_BUNDLE,
            'bundle_components' => $kind === \App\Models\ShopifyProduct::KIND_BUNDLE
                ? $this->serializeBundleComponents($shopifyVariant)
                : [],
        ];
    }

    private function normalizeCrmProductStatus(?string $status): string
    {
        $v = strtolower(trim((string) ($status ?? '')));
        if (in_array($v, ['inactive', 'draft', 'archived'], true)) {
            return 'inactive';
        }

        return 'active';
    }

    private function oauthCrmRedirect(?int $accountId, ?int $connectionId, bool $success, string $message = ''): RedirectResponse
    {
        $base = rtrim((string) config('app.url'), '/');
        $accountId = (int) $accountId;
        $connectionId = (int) $connectionId;
        $flag = $success ? 'shopify_oauth=success' : 'shopify_oauth=error';
        if (! $success && $message !== '') {
            $flag .= '&shopify_oauth_message='.rawurlencode($message);
        }
        if ($accountId > 0 && $connectionId > 0) {
            return redirect()->away($base.'/admin/clients/accounts/'.$accountId.'/stores/'.$connectionId.'?'.$flag);
        }
        if ($accountId > 0) {
            return redirect()->away($base.'/admin/clients/accounts/'.$accountId.'?tab=stores&'.$flag);
        }

        return redirect()->away($base.'/admin/clients/accounts?'.$flag);
    }

    private function orderDetail(ShopifyOrder $order, ?ShopifyOrderListService $orders = null): array
    {
        $orders ??= app(ShopifyOrderListService::class);
        $list = $orders->listRow($order);

        return array_merge($list, [
            'email' => $order->email,
            'financial_status' => $order->financial_status,
            'total_price' => $order->total_price,
            'currency' => $order->currency,
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
        ]);
    }
}
