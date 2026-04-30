<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ShipHeroOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $validated = $request->validate([
            'order_date_from' => ['nullable', 'date'],
            'order_date_to' => ['nullable', 'date'],
        ]);
        Gate::forUser($request->user())->authorize('viewAny', ClientAccount::class);

        try {
            $from = $this->dateStartIso($validated['order_date_from'] ?? null);
            $to = $this->dateEndIso($validated['order_date_to'] ?? null);
            $accounts = ClientAccount::query()
                ->whereNotNull('shiphero_customer_account_id')
                ->where('shiphero_customer_account_id', '!=', '')
                ->orderBy('company_name')
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

