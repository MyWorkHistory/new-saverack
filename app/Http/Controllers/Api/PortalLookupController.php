<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PortalLookupController extends Controller
{
    /** @var ShipHeroOrderService */
    private $orders;

    /** @var ShipHeroInventoryService */
    private $inventory;

    public function __construct(ShipHeroOrderService $orders, ShipHeroInventoryService $inventory)
    {
        $this->orders = $orders;
        $this->inventory = $inventory;
    }

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $clientAccountId = (int) ($user->client_account_id ?? 0);
        if ($clientAccountId <= 0) {
            return response()->json(['message' => 'Portal lookup is only available for client portal users.'], 403);
        }

        $query = $this->normalizeLookupQuery((string) $validated['query']);
        if ($query === '') {
            throw ValidationException::withMessages([
                'query' => ['Enter an order number or SKU.'],
            ]);
        }

        $customerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);

        $orderMatch = $this->findExactOrder($customerId, $clientAccountId, $query);
        if ($orderMatch !== null) {
            if ($orderMatch === 'multiple') {
                return response()->json([
                    'message' => 'Multiple orders match that number.',
                ], 422);
            }

            return response()->json($orderMatch);
        }

        $skuMatch = $this->findExactSku($customerId, $query);
        if ($skuMatch !== null) {
            return response()->json($skuMatch);
        }

        return response()->json(['message' => 'Not found.'], 404);
    }

    private function normalizeLookupQuery(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }

        return ltrim($s, '#');
    }

    private function normalizeOrderNumber(string $raw): string
    {
        return strtolower(ltrim(trim($raw), '#'));
    }

    /**
     * @return array<string, mixed>|null|'multiple'
     */
    private function findExactOrder(string $customerId, int $clientAccountId, string $query): array|string|null
    {
        try {
            $payload = $this->orders->listOrders([
                'customer_account_id' => $customerId,
                'tab' => 'manage',
                'order_number' => $query,
                'first' => 25,
            ]);
        } catch (RuntimeException $e) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $needle = $this->normalizeOrderNumber($query);
        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $matches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $num = $this->normalizeOrderNumber((string) ($row['order_number'] ?? ''));
            if ($num !== '' && $num === $needle) {
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    $matches[] = $row;
                }
            }
        }

        if (count($matches) === 0) {
            return null;
        }
        if (count($matches) > 1) {
            return 'multiple';
        }

        $row = $matches[0];

        return [
            'type' => 'order',
            'shiphero_order_id' => (string) $row['id'],
            'order_number' => (string) ($row['order_number'] ?? ''),
            'client_account_id' => $clientAccountId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExactSku(string $customerId, string $query): ?array
    {
        try {
            $product = $this->inventory->getProductDetailBySku($query, null, $customerId);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_array($product)) {
            return null;
        }

        $sku = trim((string) ($product['sku'] ?? ''));
        if ($sku === '' || strcasecmp($sku, $query) !== 0) {
            return null;
        }

        return [
            'type' => 'sku',
            'sku' => $sku,
        ];
    }

    private function resolveShipHeroCustomerAccountId(int $clientAccountId, Request $request): string
    {
        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Client account not found.'],
            ]);
        }

        $user = $request->user();
        if ($user instanceof User && (int) ($user->client_account_id ?? 0) > 0) {
            if ((int) $user->client_account_id !== $clientAccountId) {
                abort(403);
            }
        }

        $sid = $account->shiphero_customer_account_id;
        if (! is_string($sid) || trim($sid) === '') {
            throw ValidationException::withMessages([
                'client_account_id' => [
                    'This client account has no ShipHero customer account ID.',
                ],
            ]);
        }

        return trim($sid);
    }
}
