<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ShipHeroOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    public function show(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);

        try {
            $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
            $order = $this->orders->getOrder($orderId, $customerId);

            return response()->json([
                'order' => $order,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $customerId = $this->resolveShipHeroCustomerAccountId((int) $validated['client_account_id'], $request);
            $summary = $this->orders->findOrderSummaryById($orderId, $customerId);
            if (is_array($summary)) {
                return response()->json([
                    'order' => $this->hydrateOrderFallbackFromSummary($orderId, $summary),
                    'fallback' => [
                        'source' => 'orders_list_summary',
                    ],
                ]);
            }
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load order details from ShipHero.',
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

