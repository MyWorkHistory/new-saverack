<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ShipHeroOrderDetailCacheService;
use App\Services\ShopifyOrderAdminLinkService;
use App\Services\ShipHeroOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class OrderController extends Controller
{
    /** @var ShipHeroOrderService */
    protected $orders;

    /** @var ShopifyOrderAdminLinkService */
    protected $shopifyOrderLinks;

    /** @var ShipHeroOrderDetailCacheService */
    protected $orderDetailCache;

    public function __construct(
        ShipHeroOrderService $orders,
        ShopifyOrderAdminLinkService $shopifyOrderLinks,
        ShipHeroOrderDetailCacheService $orderDetailCache
    ) {
        $this->orders = $orders;
        $this->shopifyOrderLinks = $shopifyOrderLinks;
        $this->orderDetailCache = $orderDetailCache;
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'tab' => ['nullable', 'string', 'in:manage,awaiting,on_hold,backorder,shipped'],
            'order_date_from' => ['nullable', 'date'],
            'order_date_to' => ['nullable', 'date'],
            'fulfillment_status' => ['nullable', 'string', 'max:64'],
            'ready_to_ship' => ['nullable', 'boolean'],
            'hold_reason' => ['nullable', 'string', 'max:64'],
            'order_number' => ['nullable', 'string', 'max:128'],
            'after' => ['nullable', 'string', 'max:255'],
            'first' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $tab = (string) ($validated['tab'] ?? 'manage');
            if (
                $tab === 'shipped'
                && ! empty($validated['order_date_from'])
                && ! empty($validated['order_date_to'])
            ) {
                $from = Carbon::parse((string) $validated['order_date_from']);
                $to = Carbon::parse((string) $validated['order_date_to']);
                if ($from->diffInDays($to) > 30) {
                    throw ValidationException::withMessages([
                        'order_date_to' => ['Ship date range for shipped orders cannot exceed 30 days.'],
                    ]);
                }
            }
            $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
            $payload = $this->orders->listOrders([
                'customer_account_id' => $customerId,
                'tab' => $tab,
                'order_date_from' => $this->dateStartIso($validated['order_date_from'] ?? null),
                'order_date_to' => $this->dateEndIso($validated['order_date_to'] ?? null),
                'fulfillment_status' => $validated['fulfillment_status'] ?? null,
                'ready_to_ship' => array_key_exists('ready_to_ship', $validated) ? (bool) $validated['ready_to_ship'] : null,
                'hold_reason' => $validated['hold_reason'] ?? null,
                'order_number' => isset($validated['order_number']) ? trim((string) $validated['order_number']) : null,
                'after' => $validated['after'] ?? null,
                'first' => (int) ($validated['first'] ?? 20),
            ]);
            $payload['meta'] = [
                'client_account_id' => (int) $validated['client_account_id'],
                'shiphero_customer_account_id' => $customerId,
            ];

            return response()->json($payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero orders API.',
            ], 502);
        }
    }

    /**
     * Portal dashboard: ShipHero order counts per queue (same filters as list orders), short-lived cache.
     * Shipped uses ship date (updated_at proxy on list queries; label dates on detail when loaded).
     */
    public function queueCounts(Request $request): JsonResponse
    {
        set_time_limit(120);
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'order_date_from' => ['nullable', 'required_with:order_date_to', 'date'],
            'order_date_to' => ['nullable', 'required_with:order_date_from', 'date'],
        ]);

        try {
            $clientAccountId = (int) $validated['client_account_id'];
            $account = ClientAccount::query()->find($clientAccountId);
            if ($account === null) {
                throw ValidationException::withMessages([
                    'client_account_id' => ['Client account not found.'],
                ]);
            }
            Gate::forUser($request->user())->authorize('view', $account);

            $sid = $account->shiphero_customer_account_id;
            if (! is_string($sid) || trim($sid) === '') {
                return response()->json([
                    'ready_to_ship' => 0,
                    'on_hold' => 0,
                    'backorder' => 0,
                    'shipped' => 0,
                    'truncated' => false,
                    'shiphero_ready' => false,
                    'message' => 'ShipHero is not configured for this account yet. Save Rack will finish setup shortly.',
                    'cached_at' => now()->toIso8601String(),
                ]);
            }

            $customerId = trim($sid);

            $now = Carbon::now();
            $awaitingFrom = $this->dateStartIso($now->copy()->subDays(6)->toDateString());
            $awaitingTo = $this->dateEndIso($now->copy()->toDateString());
            $openFrom = $this->dateStartIso($now->copy()->toDateString());
            $openTo = $this->dateEndIso($now->copy()->toDateString());

            $shippedFromInput = $validated['order_date_from'] ?? null;
            $shippedToInput = $validated['order_date_to'] ?? null;
            if ($shippedFromInput !== null && $shippedToInput !== null) {
                $shippedFrom = $this->dateStartIso((string) $shippedFromInput);
                $shippedTo = $this->dateEndIso((string) $shippedToInput);
            } else {
                $shippedFrom = $openFrom;
                $shippedTo = $openTo;
            }

            $cacheKey = sprintf(
                'orders:queue_counts:v2:%d:%s',
                $clientAccountId,
                md5(implode('|', array_filter([
                    $customerId,
                    $awaitingFrom,
                    $awaitingTo,
                    $openFrom,
                    $openTo,
                    $shippedFrom,
                    $shippedTo,
                ])))
            );

            $buildQueueCountsPayload = function () use (
                $customerId,
                $awaitingFrom,
                $awaitingTo,
                $openFrom,
                $openTo,
                $shippedFrom,
                $shippedTo
            ) {
                $ready = $this->orders->countOrders([
                    'customer_account_id' => $customerId,
                    'tab' => 'awaiting',
                    'order_date_from' => $awaitingFrom,
                    'order_date_to' => $awaitingTo,
                ]);
                $hold = $this->orders->countOrders([
                    'customer_account_id' => $customerId,
                    'tab' => 'on_hold',
                    'order_date_from' => $openFrom,
                    'order_date_to' => $openTo,
                ]);
                $back = $this->orders->countOrders([
                    'customer_account_id' => $customerId,
                    'tab' => 'backorder',
                    'order_date_from' => $openFrom,
                    'order_date_to' => $openTo,
                ]);
                $ship = $this->orders->countOrders([
                    'customer_account_id' => $customerId,
                    'tab' => 'shipped',
                    'order_date_from' => $shippedFrom,
                    'order_date_to' => $shippedTo,
                ]);

                return [
                    'ready_to_ship' => $ready['count'],
                    'on_hold' => $hold['count'],
                    'backorder' => $back['count'],
                    'shipped' => $ship['count'],
                    'truncated' => $ready['truncated'] || $hold['truncated'] || $back['truncated'] || $ship['truncated'],
                    'shiphero_ready' => true,
                    'awaiting_order_date_from' => $awaitingFrom,
                    'awaiting_order_date_to' => $awaitingTo,
                    'open_queue_order_date_from' => $openFrom,
                    'open_queue_order_date_to' => $openTo,
                    'shipped_order_date_from' => $shippedFrom,
                    'shipped_order_date_to' => $shippedTo,
                    'cached_at' => now()->toIso8601String(),
                ];
            };

            if ($request->boolean('refresh')) {
                $payload = $buildQueueCountsPayload();
            } else {
                $payload = Cache::remember($cacheKey, now()->addMinutes(3), $buildQueueCountsPayload);
            }

            return response()->json($payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero orders API.',
            ], 502);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $validated = $request->validate([
            'order_date_from' => ['nullable', 'date'],
            'order_date_to' => ['nullable', 'date'],
            'accounts_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'accounts_offset' => ['nullable', 'integer', 'min:0'],
        ]);
        Gate::forUser($request->user())->authorize('viewAny', ClientAccount::class);

        try {
            $from = $this->dateStartIso($validated['order_date_from'] ?? null);
            $to = $this->dateEndIso($validated['order_date_to'] ?? null);
            $limit = (int) ($validated['accounts_limit'] ?? 0);
            $offset = (int) ($validated['accounts_offset'] ?? 0);
            $isPagedAccounts = $limit > 0;
            $cacheKey = sprintf(
                'orders:summary:%s:%s:%s:%s',
                $from ?? 'none',
                $to ?? 'none',
                $isPagedAccounts ? $limit : 'all',
                $isPagedAccounts ? $offset : 'all'
            );
            $staleKey = $cacheKey.':stale';
            $lockKey = $cacheKey.':refresh_lock';
            $fresh = Cache::get($cacheKey);
            if (is_array($fresh)) {
                Log::info('shiphero.orders_summary.controller.cache_hit', [
                    'cache_key' => $cacheKey,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
                return response()->json($fresh);
            }

            $stale = Cache::get($staleKey);
            if (is_array($stale)) {
                // Serve stale immediately; trigger a guarded refresh.
                if (Cache::add($lockKey, '1', now()->addSeconds(30))) {
                    try {
                        $payload = $this->computeOrdersSummaryPayload($from, $to, $limit, $offset);
                        Cache::put($cacheKey, $payload, now()->addSeconds(60));
                        Cache::put($staleKey, $payload, now()->addMinutes(10));
                    } finally {
                        Cache::forget($lockKey);
                    }
                }
                Log::info('shiphero.orders_summary.controller.stale_hit', [
                    'cache_key' => $cacheKey,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
                return response()->json($stale);
            }

            $payload = $this->computeOrdersSummaryPayload($from, $to, $limit, $offset);
            Cache::put($cacheKey, $payload, now()->addSeconds(60));
            Cache::put($staleKey, $payload, now()->addMinutes(10));

            Log::info('shiphero.orders_summary.controller.cold_compute', [
                'cache_key' => $cacheKey,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json($payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not compute ShipHero order summary.',
            ], 502);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function computeOrdersSummaryPayload(?string $from, ?string $to, int $accountsLimit = 0, int $accountsOffset = 0): array
    {
        $baseQuery = ClientAccount::query()
            ->whereNotNull('shiphero_customer_account_id')
            ->where('shiphero_customer_account_id', '!=', '')
            ->orderBy('company_name');

        $totalAccounts = (int) (clone $baseQuery)->count();

        if ($accountsLimit > 0) {
            $baseQuery->skip(max(0, $accountsOffset))->take($accountsLimit);
        }

        $accounts = $baseQuery
            ->get(['id', 'company_name', 'shiphero_customer_account_id'])
            ->map(static function (ClientAccount $account) {
                return [
                    'id' => (int) $account->id,
                    'name' => (string) $account->company_name,
                    'customer_account_id' => (string) $account->shiphero_customer_account_id,
                ];
            })
            ->values()
            ->all();

        $payload = $this->orders->readyToShipSummaryForAccounts($accounts, $from, $to);
        $payload['accounts_total'] = $totalAccounts;
        $payload['accounts_offset'] = max(0, $accountsOffset);
        $payload['accounts_limit'] = max(0, $accountsLimit);
        $payload['has_more_accounts'] = $accountsLimit > 0
            ? (max(0, $accountsOffset) + count($accounts)) < $totalAccounts
            : false;

        return $payload;
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'refresh' => ['nullable', 'boolean'],
            'debug_shiphero_raw' => ['nullable', 'boolean'],
            'debug_variant' => ['nullable', 'string', 'in:minimal,core,pricing,addresses'],
        ]);
        $clientAccountId = (int) $validated['client_account_id'];
        $refresh = (bool) ($validated['refresh'] ?? false);
        $customerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);

        try {
            if ((bool) ($validated['debug_shiphero_raw'] ?? false)) {
                $debug = $this->orders->debugOrderDetailRaw(
                    $orderId,
                    $customerId,
                    (string) ($validated['debug_variant'] ?? 'core')
                );

                return response()->json($debug);
            }

            Log::info('shiphero.order_detail.request.start', [
                'order_id' => $orderId,
                'client_account_id' => (int) $validated['client_account_id'],
                'shiphero_customer_account_id' => $customerId,
                'user_id' => optional($request->user())->id,
            ]);
            if ($clientAccountId > 0 && ! $refresh) {
                $cachedPayload = $this->orderDetailCache->getCachedOrderWithMeta($clientAccountId, $orderId);
                if ($cachedPayload !== null) {
                    return response()->json([
                        'order' => $this->enrichOrderDetailForResponse($clientAccountId, $cachedPayload['order']),
                        'cached' => true,
                        'cached_at' => $cachedPayload['cached_at'],
                    ]);
                }
            }

            $order = $this->orders->getOrder($orderId, $customerId);

            Log::info('shiphero.order_detail.request.success', [
                'order_id' => $orderId,
                'shiphero_customer_account_id' => $customerId,
                'items_count' => is_array($order['items'] ?? null) ? count($order['items']) : 0,
                'history_count' => is_array($order['history'] ?? null) ? count($order['history']) : 0,
            ]);
            $order = $this->enrichOrderDetailForResponse($clientAccountId, $order);
            if ($clientAccountId > 0) {
                $this->orderDetailCache->putOrder($clientAccountId, $orderId, $order);
            }

            return response()->json([
                'order' => $order,
                'cached' => false,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $message = (string) $e->getMessage();
            Log::warning('shiphero.order_detail.runtime_exception', [
                'order_id' => $orderId,
                'shiphero_customer_account_id' => $customerId,
                'message' => $message,
            ]);
            $canFallback = $this->isNotFoundMessage($message);
            $summary = $canFallback ? $this->orders->findOrderSummaryById($orderId, $customerId) : null;
            if ($canFallback && is_array($summary)) {
                Log::info('shiphero.order_detail.fallback.summary_used', [
                    'order_id' => $orderId,
                    'shiphero_customer_account_id' => $customerId,
                ]);
                $fallbackOrder = $this->hydrateOrderFallbackFromSummary($orderId, $summary);

                return response()->json([
                    'order' => $this->enrichOrderDetailForResponse($clientAccountId, $fallbackOrder),
                    'fallback' => [
                        'source' => 'orders_list_summary',
                    ],
                    'cached' => false,
                ]);
            }
            return response()->json([
                'message' => $message,
                'error_code' => 'shiphero_order_detail_upstream_error',
            ], 502);
        } catch (Throwable $e) {
            report($e);
            Log::error('shiphero.order_detail.unhandled_exception', [
                'order_id' => $orderId,
                'shiphero_customer_account_id' => $customerId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load order details from ShipHero.',
                'error_code' => 'shiphero_order_detail_internal_error',
            ], 502);
        }
    }

    public function markFulfilled(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->markOrderFulfilled(
                $orderId,
                $customerId,
                isset($validated['reason']) ? (string) $validated['reason'] : null
            );

            return response()->json(['message' => 'Order marked fulfilled.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not mark order fulfilled in ShipHero.',
            ], 502);
        }
    }

    public function cancelOrder(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'reason' => ['nullable', 'string', 'max:500'],
            'void_on_platform' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->cancelOrderInShipHero(
                $orderId,
                $customerId,
                isset($validated['reason']) ? (string) $validated['reason'] : null,
                (bool) ($validated['void_on_platform'] ?? false),
                (bool) ($validated['force'] ?? false)
            );

            return response()->json(['message' => 'Order canceled.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not cancel order in ShipHero.',
            ], 502);
        }
    }

    public function setHolds(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'fraud_hold' => ['nullable', 'boolean'],
            'address_hold' => ['nullable', 'boolean'],
            'payment_hold' => ['nullable', 'boolean'],
            'client_hold' => ['nullable', 'boolean'],
            'operator_hold' => ['nullable', 'boolean'],
        ]);
        $flags = [];
        foreach (['fraud_hold', 'address_hold', 'payment_hold', 'client_hold', 'operator_hold'] as $k) {
            if (! empty($validated[$k])) {
                $flags[$k] = true;
            }
        }
        if ($flags === []) {
            throw ValidationException::withMessages([
                'fraud_hold' => ['Select at least one hold type.'],
            ]);
        }
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->setOrderHoldsTrue($orderId, $customerId, $flags);

            return response()->json(['message' => 'Holds applied.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not apply holds in ShipHero.',
            ], 502);
        }
    }

    public function removeHolds(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'holds_to_clear' => ['nullable', 'array', 'min:1'],
            'holds_to_clear.*' => ['string', Rule::in(ShipHeroOrderService::orderRemovableHoldKeys())],
            'payment_hold_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $holds = $this->orders->getOrderHoldsNormalized($orderId, $customerId);
            $keysToClear = isset($validated['holds_to_clear']) && is_array($validated['holds_to_clear'])
                ? array_values(array_unique($validated['holds_to_clear']))
                : [];
            if ($keysToClear === []) {
                if ($this->orders->orderHoldsOnlyOperatorHoldActive($holds)) {
                    return response()->json([
                        'message' => ShipHeroOrderService::OPERATOR_HOLD_ONLY_MESSAGE,
                    ], 422);
                }
                $this->orders->clearOrderHolds($orderId, $customerId);

                return response()->json(['message' => 'Holds cleared.']);
            }

            $clearOperator = in_array(ShipHeroOrderService::ORDER_USER_HOLD_KEY, $keysToClear, true);
            $clearableKeys = array_values(array_filter(
                $keysToClear,
                static fn (string $k): bool => in_array($k, ShipHeroOrderService::ORDER_CLEARABLE_HOLD_KEYS, true)
            ));

            if ($clearOperator) {
                $this->orders->clearOperatorHold($orderId, $customerId);
                $holds = $this->orders->getOrderHoldsNormalized($orderId, $customerId);
            }

            if ($clearableKeys !== []) {
                $reason = isset($validated['payment_hold_reason']) ? trim((string) $validated['payment_hold_reason']) : '';
                $paymentReason = in_array('payment_hold', $clearableKeys, true)
                    ? ($reason !== '' ? $reason : 'User Clear Payment Hold')
                    : null;
                $this->orders->clearOrderHoldsSelective($orderId, $customerId, $clearableKeys, $paymentReason, $holds);
            }

            return response()->json(['message' => 'Holds cleared.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not clear holds in ShipHero.',
            ], 502);
        }
    }

    public function updateSignatureGiftNote(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'require_signature' => ['required', 'boolean'],
            'gift_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->updateRequireSignatureAndGiftNote(
                $orderId,
                $customerId,
                (bool) $validated['require_signature'],
                isset($validated['gift_note']) ? (string) $validated['gift_note'] : null
            );

            return response()->json(['message' => 'Options updated.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not update order in ShipHero.',
            ], 502);
        }
    }

    public function updateShippingAddress(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'first_name' => ['nullable', 'string', 'max:160'],
            'last_name' => ['nullable', 'string', 'max:160'],
            'company' => ['nullable', 'string', 'max:200'],
            'address1' => ['nullable', 'string', 'max:500'],
            'address2' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:200'],
            'state' => ['nullable', 'string', 'max:120'],
            'zip' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:8'],
            'email' => ['nullable', 'string', 'max:320'],
            'phone' => ['nullable', 'string', 'max:80'],
            'skip_address_validation' => ['nullable', 'boolean'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->updateOrderShippingAddress(
                $orderId,
                $customerId,
                [
                    'first_name' => (string) ($validated['first_name'] ?? ''),
                    'last_name' => (string) ($validated['last_name'] ?? ''),
                    'company' => (string) ($validated['company'] ?? ''),
                    'address1' => (string) ($validated['address1'] ?? ''),
                    'address2' => (string) ($validated['address2'] ?? ''),
                    'city' => (string) ($validated['city'] ?? ''),
                    'state' => (string) ($validated['state'] ?? ''),
                    'zip' => (string) ($validated['zip'] ?? ''),
                    'country' => (string) ($validated['country'] ?? ''),
                    'email' => (string) ($validated['email'] ?? ''),
                    'phone' => (string) ($validated['phone'] ?? ''),
                ],
                (bool) ($validated['skip_address_validation'] ?? false)
            );

            return response()->json(['message' => 'Shipping address updated.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not update shipping address in ShipHero.',
            ], 502);
        }
    }

    public function updateShippingLines(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'carrier' => ['nullable', 'string', 'max:200'],
            'method' => ['nullable', 'string', 'max:200'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->updateOrderShippingLines(
                $orderId,
                $customerId,
                (string) ($validated['carrier'] ?? ''),
                (string) ($validated['method'] ?? '')
            );

            return response()->json(['message' => 'Shipping carrier and method updated.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not update shipping lines in ShipHero.',
            ], 502);
        }
    }

    public function updateAllowPartial(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'allow_partial' => ['required', 'boolean'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->updateOrderAllowPartial(
                $orderId,
                $customerId,
                (bool) $validated['allow_partial']
            );

            return response()->json(['message' => 'Allow partial updated.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not update order in ShipHero.',
            ], 502);
        }
    }

    public function updateTags(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'tags' => ['required', 'array', 'max:200'],
            'tags.*' => ['string', 'max:120'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->updateOrderTags(
                $orderId,
                $customerId,
                array_values($validated['tags'])
            );

            return response()->json(['message' => 'Order tags updated.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not update order tags in ShipHero.',
            ], 502);
        }
    }

    public function addLineItems(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'line_items' => ['required', 'array', 'min:1', 'max:25'],
            'line_items.*.sku' => ['required', 'string', 'max:200'],
            'line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:99999'],
            'line_items.*.product_name' => ['nullable', 'string', 'max:500'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $rows = [];
            foreach ($validated['line_items'] as $row) {
                $rows[] = [
                    'sku' => (string) $row['sku'],
                    'quantity' => (int) $row['quantity'],
                    'product_name' => isset($row['product_name']) ? (string) $row['product_name'] : null,
                ];
            }
            $this->orders->addOrderLineItems($orderId, $customerId, $rows);

            return response()->json(['message' => 'Line items added.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not add line items in ShipHero.',
            ], 502);
        }
    }

    public function updateLineItemPending(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'line_item_id' => ['required', 'string', 'max:255'],
            'quantity_pending_fulfillment' => ['required', 'numeric', 'min:0', 'max:999999'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->updateOrderLineItemPendingFulfillment(
                $orderId,
                $customerId,
                (string) $validated['line_item_id'],
                (float) $validated['quantity_pending_fulfillment']
            );

            return response()->json(['message' => 'Quantity to ship updated.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not update line item in ShipHero.',
            ], 502);
        }
    }

    public function removeLineItem(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'line_item_id' => ['required', 'string', 'max:255'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->removeOrderLineItem(
                $orderId,
                $customerId,
                (string) $validated['line_item_id']
            );

            return response()->json(['message' => 'Line item removed.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not remove line item in ShipHero.',
            ], 502);
        }
    }

    public function updatePackingNote(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'packing_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        try {
            $this->orders->updateOrderPackingNote(
                $orderId,
                $customerId,
                (string) ($validated['packing_note'] ?? '')
            );

            return response()->json(['message' => 'Warehouse note updated.']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not update packing note in ShipHero.',
            ], 502);
        }
    }

    public function uploadAttachment(Request $request, string $orderId): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,txt,csv,doc,docx,xlsx'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        $file = $request->file('file');
        if ($file === null) {
            return response()->json(['message' => 'No file uploaded.'], 422);
        }
        $path = null;
        $publicUrl = null;
        try {
            $path = $file->store('order-attachments', 'public');
            $publicUrl = $this->buildPublicUrlForStoredOrderAttachment($path);
            $this->assertAttachmentUrlAcceptableForShipHero($publicUrl);
            $original = $file->getClientOriginalName();
            $mime = $file->getClientMimeType();
            $attachmentRow = $this->orders->addOrderAttachment(
                $orderId,
                $customerId,
                $publicUrl,
                $original,
                is_string($mime) ? $mime : null,
                null
            );
            if (! is_array($attachmentRow) || trim((string) ($attachmentRow['id'] ?? '')) === '') {
                $attachmentRow = [
                    'id' => 'crm-upload:'.Str::uuid()->toString(),
                    'url' => $publicUrl,
                    'filename' => $original,
                    'description' => '',
                    'file_type' => is_string($mime) ? $mime : '',
                    'created_at' => Carbon::now()->toIso8601String(),
                ];
            }

            return response()->json([
                'message' => 'Attachment added.',
                'url' => $publicUrl,
                'attachment' => $attachmentRow,
            ]);
        } catch (ValidationException $e) {
            if ($path !== null) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (Throwable $cleanupIgnored) {
                    // ignore cleanup failures
                }
            }

            throw $e;
        } catch (RuntimeException $e) {
            if ($path !== null) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (Throwable $cleanupIgnored) {
                    // ignore cleanup failures
                }
            }
            Log::warning('shiphero.order.attachment_failed', $this->attachmentFailureLogContext($orderId, $publicUrl, $e));

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            if ($path !== null) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (Throwable $cleanupIgnored) {
                    // ignore cleanup failures
                }
            }
            Log::warning('shiphero.order.attachment_failed', $this->attachmentFailureLogContext($orderId, $publicUrl, $e));
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not attach file in ShipHero.',
            ], 502);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentFailureLogContext(string $orderId, ?string $publicUrl, Throwable $e): array
    {
        $host = null;
        $scheme = null;
        if (is_string($publicUrl) && $publicUrl !== '') {
            $host = parse_url($publicUrl, PHP_URL_HOST);
            $scheme = parse_url($publicUrl, PHP_URL_SCHEME);
        }
        $appUrl = (string) config('app.url');

        return [
            'order_id' => $orderId,
            'public_url_scheme' => is_string($scheme) ? $scheme : null,
            'public_url_host' => is_string($host) ? $host : null,
            'app_url_host' => parse_url($appUrl, PHP_URL_HOST),
            'app_url_https' => str_starts_with(strtolower($appUrl), 'https://'),
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ];
    }

    private function buildPublicUrlForStoredOrderAttachment(string $path): string
    {
        $override = trim((string) config('services.shiphero.attachment_public_base_url', ''));
        if ($override !== '') {
            return rtrim($override, '/').'/storage/'.str_replace('\\', '/', $path);
        }
        $relative = Storage::disk('public')->url($path);

        return (is_string($relative) && (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')))
            ? $relative
            : url($relative);
    }

    /**
     * ShipHero downloads this URL from their infrastructure; it must be HTTPS and publicly routable.
     *
     * @throws ValidationException
     */
    private function assertAttachmentUrlAcceptableForShipHero(string $publicUrl): void
    {
        $scheme = parse_url($publicUrl, PHP_URL_SCHEME);
        $host = parse_url($publicUrl, PHP_URL_HOST);
        if (! is_string($scheme) || $scheme === '') {
            throw ValidationException::withMessages([
                'file' => ['Could not build a valid attachment URL. Set APP_URL, filesystems.disks.public.url, or SHIPHERO_ATTACHMENT_PUBLIC_BASE_URL.'],
            ]);
        }
        if (strtolower($scheme) !== 'https') {
            throw ValidationException::withMessages([
                'file' => ['ShipHero requires an HTTPS URL so it can download the file. Set APP_URL to an https origin or SHIPHERO_ATTACHMENT_PUBLIC_BASE_URL to your public CRM base.'],
            ]);
        }
        if (! is_string($host) || $host === '') {
            throw ValidationException::withMessages([
                'file' => ['Attachment URL is missing a hostname.'],
            ]);
        }
        if ($this->isNonPublicAttachmentHost(strtolower($host))) {
            throw ValidationException::withMessages([
                'file' => ['ShipHero cannot reach '.$host.' from the internet. Set SHIPHERO_ATTACHMENT_PUBLIC_BASE_URL to your public https CRM host (for example https://app.saverack.com) or use an ngrok https URL for local testing.'],
            ]);
        }
    }

    private function isNonPublicAttachmentHost(string $hostLower): bool
    {
        if ($hostLower === 'localhost' || str_ends_with($hostLower, '.localhost')) {
            return true;
        }
        if ($hostLower === '127.0.0.1' || str_starts_with($hostLower, '127.')) {
            return true;
        }
        if ($hostLower === '::1' || $hostLower === '0.0.0.0') {
            return true;
        }
        if (str_ends_with($hostLower, '.local') || $hostLower === 'laravel.test') {
            return true;
        }
        if (str_starts_with($hostLower, '10.')) {
            return true;
        }
        if (preg_match('/^192\.168\./', $hostLower) === 1) {
            return true;
        }
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $hostLower) === 1) {
            return true;
        }

        return false;
    }

    private const BULK_ORDER_IDS_MAX = 25;

    /**
     * @param  array<int, mixed>  $orderIds
     * @return list<string>
     */
    private function normalizeBulkOrderIds(array $orderIds): array
    {
        $out = [];
        foreach ($orderIds as $id) {
            $s = trim((string) $id);
            if ($s !== '' && ! in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        if ($out === []) {
            throw ValidationException::withMessages([
                'order_ids' => ['Select at least one order.'],
            ]);
        }
        if (count($out) > self::BULK_ORDER_IDS_MAX) {
            throw ValidationException::withMessages([
                'order_ids' => ['You may select at most '.self::BULK_ORDER_IDS_MAX.' orders per request.'],
            ]);
        }

        return $out;
    }

    public function bulkMarkFulfilled(Request $request): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_ORDER_IDS_MAX],
            'order_ids.*' => ['string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        $orderIds = $this->normalizeBulkOrderIds($validated['order_ids']);
        $reason = isset($validated['reason']) ? (string) $validated['reason'] : null;
        $results = [];
        $ok = 0;
        $failed = 0;
        foreach ($orderIds as $oid) {
            try {
                $this->orders->markOrderFulfilled($oid, $customerId, $reason);
                $results[] = ['order_id' => $oid, 'ok' => true];
                $ok++;
            } catch (RuntimeException $e) {
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => $e->getMessage()];
                $failed++;
            } catch (Throwable $e) {
                report($e);
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => config('app.debug') ? $e->getMessage() : 'Request failed.'];
                $failed++;
            }
        }

        return response()->json([
            'results' => $results,
            'summary' => ['ok' => $ok, 'failed' => $failed],
        ]);
    }

    public function bulkCancelOrders(Request $request): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_ORDER_IDS_MAX],
            'order_ids.*' => ['string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            'void_on_platform' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        $orderIds = $this->normalizeBulkOrderIds($validated['order_ids']);
        $results = [];
        $ok = 0;
        $failed = 0;
        foreach ($orderIds as $oid) {
            try {
                $this->orders->cancelOrderInShipHero(
                    $oid,
                    $customerId,
                    isset($validated['reason']) ? (string) $validated['reason'] : null,
                    (bool) ($validated['void_on_platform'] ?? false),
                    (bool) ($validated['force'] ?? false)
                );
                $results[] = ['order_id' => $oid, 'ok' => true];
                $ok++;
            } catch (RuntimeException $e) {
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => $e->getMessage()];
                $failed++;
            } catch (Throwable $e) {
                report($e);
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => config('app.debug') ? $e->getMessage() : 'Request failed.'];
                $failed++;
            }
        }

        return response()->json([
            'results' => $results,
            'summary' => ['ok' => $ok, 'failed' => $failed],
        ]);
    }

    public function bulkAllowPartial(Request $request): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_ORDER_IDS_MAX],
            'order_ids.*' => ['string', 'max:255'],
            'allow_partial' => ['nullable', 'boolean'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        $orderIds = $this->normalizeBulkOrderIds($validated['order_ids']);
        $allow = array_key_exists('allow_partial', $validated) ? (bool) $validated['allow_partial'] : true;
        $results = [];
        $ok = 0;
        $failed = 0;
        foreach ($orderIds as $oid) {
            try {
                $this->orders->updateOrderAllowPartial($oid, $customerId, $allow);
                $results[] = ['order_id' => $oid, 'ok' => true];
                $ok++;
            } catch (RuntimeException $e) {
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => $e->getMessage()];
                $failed++;
            } catch (Throwable $e) {
                report($e);
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => config('app.debug') ? $e->getMessage() : 'Request failed.'];
                $failed++;
            }
        }

        return response()->json([
            'results' => $results,
            'summary' => ['ok' => $ok, 'failed' => $failed],
        ]);
    }

    public function bulkSetHolds(Request $request): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_ORDER_IDS_MAX],
            'order_ids.*' => ['string', 'max:255'],
            'fraud_hold' => ['nullable', 'boolean'],
            'address_hold' => ['nullable', 'boolean'],
            'payment_hold' => ['nullable', 'boolean'],
            'client_hold' => ['nullable', 'boolean'],
            'operator_hold' => ['nullable', 'boolean'],
        ]);
        $flags = [];
        foreach (['fraud_hold', 'address_hold', 'payment_hold', 'client_hold', 'operator_hold'] as $k) {
            if (! empty($validated[$k])) {
                $flags[$k] = true;
            }
        }
        if ($flags === []) {
            throw ValidationException::withMessages([
                'fraud_hold' => ['Select at least one hold type.'],
            ]);
        }
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        $orderIds = $this->normalizeBulkOrderIds($validated['order_ids']);
        $results = [];
        $ok = 0;
        $failed = 0;
        foreach ($orderIds as $oid) {
            try {
                $this->orders->setOrderHoldsTrue($oid, $customerId, $flags);
                $results[] = ['order_id' => $oid, 'ok' => true];
                $ok++;
            } catch (RuntimeException $e) {
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => $e->getMessage()];
                $failed++;
            } catch (Throwable $e) {
                report($e);
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => config('app.debug') ? $e->getMessage() : 'Request failed.'];
                $failed++;
            }
        }

        return response()->json([
            'results' => $results,
            'summary' => ['ok' => $ok, 'failed' => $failed],
        ]);
    }

    public function bulkClearHolds(Request $request): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_ORDER_IDS_MAX],
            'order_ids.*' => ['string', 'max:255'],
            'holds_to_clear' => ['nullable', 'array', 'min:1'],
            'holds_to_clear.*' => ['string', Rule::in(ShipHeroOrderService::ORDER_CLEARABLE_HOLD_KEYS)],
            'payment_hold_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
        $orderIds = $this->normalizeBulkOrderIds($validated['order_ids']);
        $keysToClear = isset($validated['holds_to_clear']) && is_array($validated['holds_to_clear'])
            ? array_values(array_unique($validated['holds_to_clear']))
            : [];
        $reasonRaw = isset($validated['payment_hold_reason']) ? trim((string) $validated['payment_hold_reason']) : '';
        $paymentReason = $keysToClear !== [] && in_array('payment_hold', $keysToClear, true)
            ? ($reasonRaw !== '' ? $reasonRaw : 'User Clear Payment Hold')
            : null;
        $results = [];
        $ok = 0;
        $failed = 0;
        foreach ($orderIds as $oid) {
            try {
                $holds = $this->orders->getOrderHoldsNormalized($oid, $customerId);
                if ($this->orders->orderHoldsOnlyOperatorHoldActive($holds)) {
                    $results[] = [
                        'order_id' => $oid,
                        'ok' => false,
                        'message' => ShipHeroOrderService::OPERATOR_HOLD_ONLY_MESSAGE,
                    ];
                    $failed++;

                    continue;
                }
                if ($keysToClear === []) {
                    $this->orders->clearOrderHolds($oid, $customerId);
                    $results[] = ['order_id' => $oid, 'ok' => true];
                    $ok++;

                    continue;
                }
                try {
                    $this->orders->clearOrderHoldsSelective($oid, $customerId, $keysToClear, $paymentReason, $holds);
                    $results[] = ['order_id' => $oid, 'ok' => true];
                    $ok++;
                } catch (RuntimeException $e) {
                    if ($e->getMessage() === ShipHeroOrderService::NO_MATCHING_HOLDS_MESSAGE) {
                        $results[] = [
                            'order_id' => $oid,
                            'ok' => true,
                            'message' => 'No matching holds on this order for the selected types.',
                        ];
                        $ok++;
                    } else {
                        throw $e;
                    }
                }
            } catch (RuntimeException $e) {
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => $e->getMessage()];
                $failed++;
            } catch (Throwable $e) {
                report($e);
                $results[] = ['order_id' => $oid, 'ok' => false, 'message' => config('app.debug') ? $e->getMessage() : 'Request failed.'];
                $failed++;
            }
        }

        return response()->json([
            'results' => $results,
            'summary' => ['ok' => $ok, 'failed' => $failed],
        ]);
    }

    private function resolveShipHeroCustomerAccountId(int $clientAccountId, Request $request): string
    {
        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Client account not found.'],
            ]);
        }
        Gate::forUser($request->user())->authorize('view', $account);

        $sid = $account->shiphero_customer_account_id;
        if (! is_string($sid) || trim($sid) === '') {
            throw ValidationException::withMessages([
                'client_account_id' => [
                    'This client account has no ShipHero customer account ID. Set it on the account profile, then try again.',
                ],
            ]);
        }

        return trim($sid);
    }

    private function dateStartIso($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \Carbon\Carbon::parse($value)->startOfDay()->toIso8601String();
    }

    private function dateEndIso($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \Carbon\Carbon::parse($value)->endOfDay()->toIso8601String();
    }

    private function isTransientUpstreamErrorMessage(string $message): bool
    {
        $m = strtolower(trim($message));
        return str_contains($m, '502')
            || str_contains($m, '503')
            || str_contains($m, '504')
            || str_contains($m, 'cloudflare')
            || str_contains($m, 'bad gateway')
            || str_contains($m, 'temporarily unavailable')
            || str_contains($m, 'timeout')
            || str_contains($m, 'failed before response');
    }

    private function isNotFoundMessage(string $message): bool
    {
        return str_contains(strtolower(trim($message)), 'not found');
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function enrichOrderDetailForResponse(int $clientAccountId, array $order): array
    {
        try {
            return $this->shopifyOrderLinks->enrichOrder($clientAccountId, $order);
        } catch (Throwable $e) {
            Log::warning('shopify.order_admin_link.controller_enrich_failed', [
                'client_account_id' => $clientAccountId,
                'message' => $e->getMessage(),
            ]);

            return $order;
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function hydrateOrderFallbackFromSummary(string $orderId, array $summary): array
    {
        return [
            'id' => (string) ($summary['id'] ?? $orderId),
            'legacy_id' => is_numeric($summary['legacy_id'] ?? null) ? (int) $summary['legacy_id'] : null,
            'order_number' => (string) ($summary['order_number'] ?? ''),
            'partner_order_id' => (string) ($summary['partner_order_id'] ?? ''),
            'source' => '',
            'status' => (string) ($summary['status'] ?? ''),
            'hold_reason' => is_string($summary['hold_reason'] ?? null) ? $summary['hold_reason'] : null,
            'holds' => [
                'fraud_hold' => false,
                'address_hold' => false,
                'shipping_method_hold' => false,
                'operator_hold' => false,
                'payment_hold' => false,
                'client_hold' => false,
            ],
            'has_active_hold' => false,
            'not_ready_subtitle' => '',
            'order_date' => is_string($summary['order_date'] ?? null) ? $summary['order_date'] : null,
            'required_ship_date' => null,
            'account' => (string) ($summary['account'] ?? ''),
            'email' => (string) ($summary['email'] ?? ''),
            'shipping_carrier' => (string) ($summary['shipping_carrier'] ?? ''),
            'method' => (string) ($summary['method'] ?? ''),
            'shipping_cost' => null,
            'subtotal' => null,
            'total_tax' => null,
            'total_discounts' => null,
            'total_price' => null,
            'gift_invoice' => false,
            'allow_partial' => false,
            'require_signature' => false,
            'packing_note' => null,
            'gift_note' => '',
            'tags' => [],
            'attachments' => [],
            'shipping_line' => [
                'title' => 'Shipping',
                'carrier' => (string) ($summary['shipping_carrier'] ?? ''),
                'method' => (string) ($summary['method'] ?? ''),
                'price' => '0',
            ],
            'shipping_address' => [
                'first_name' => '',
                'last_name' => '',
                'company' => '',
                'address1' => '',
                'address2' => '',
                'city' => '',
                'state' => '',
                'state_code' => '',
                'zip' => '',
                'country' => (string) ($summary['country'] ?? ''),
                'country_code' => '',
                'email' => '',
                'phone' => '',
            ],
            'billing_address' => null,
            'items' => [],
            'history' => [],
        ];
    }
}

