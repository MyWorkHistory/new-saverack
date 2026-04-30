<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ShipHeroOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class OrderController extends Controller
{
    /** @var ShipHeroOrderService */
    protected $orders;

    public function __construct(ShipHeroOrderService $orders)
    {
        $this->orders = $orders;
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'tab' => ['nullable', 'string', 'in:manage,awaiting,on_hold,shipped'],
            'order_date_from' => ['nullable', 'date'],
            'order_date_to' => ['nullable', 'date'],
            'fulfillment_status' => ['nullable', 'string', 'max:64'],
            'ready_to_ship' => ['nullable', 'boolean'],
            'after' => ['nullable', 'string', 'max:255'],
            'first' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
            $payload = $this->orders->listOrders([
                'customer_account_id' => $customerId,
                'tab' => (string) ($validated['tab'] ?? 'manage'),
                'order_date_from' => $this->dateStartIso($validated['order_date_from'] ?? null),
                'order_date_to' => $this->dateEndIso($validated['order_date_to'] ?? null),
                'fulfillment_status' => $validated['fulfillment_status'] ?? null,
                'ready_to_ship' => array_key_exists('ready_to_ship', $validated) ? (bool) $validated['ready_to_ship'] : null,
                'after' => $validated['after'] ?? null,
                'first' => (int) ($validated['first'] ?? 20),
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
            'debug_shiphero_raw' => ['nullable', 'boolean'],
            'debug_variant' => ['nullable', 'string', 'in:minimal,core,pricing,addresses'],
        ]);
        $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);

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
            $order = $this->orders->getOrder($orderId, $customerId);

            Log::info('shiphero.order_detail.request.success', [
                'order_id' => $orderId,
                'shiphero_customer_account_id' => $customerId,
                'items_count' => is_array($order['items'] ?? null) ? count($order['items']) : 0,
                'history_count' => is_array($order['history'] ?? null) ? count($order['history']) : 0,
            ]);
            return response()->json([
                'order' => $order,
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
                return response()->json([
                    'order' => $this->hydrateOrderFallbackFromSummary($orderId, $summary),
                    'fallback' => [
                        'source' => 'orders_list_summary',
                    ],
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
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function hydrateOrderFallbackFromSummary(string $orderId, array $summary): array
    {
        return [
            'id' => (string) ($summary['id'] ?? $orderId),
            'legacy_id' => is_numeric($summary['legacy_id'] ?? null) ? (int) $summary['legacy_id'] : null,
            'order_number' => (string) ($summary['order_number'] ?? ''),
            'partner_order_id' => '',
            'status' => (string) ($summary['status'] ?? ''),
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
            'shipping_address' => [
                'country' => (string) ($summary['country'] ?? ''),
            ],
            'billing_address' => null,
            'items' => [],
            'history' => [],
        ];
    }
}

