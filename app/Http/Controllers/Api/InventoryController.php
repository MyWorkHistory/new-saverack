<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ShipHeroInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InventoryController extends Controller
{
    /** @var ShipHeroInventoryService */
    protected $inventory;

    public function __construct(ShipHeroInventoryService $inventory)
    {
        $this->inventory = $inventory;
    }

    public function clientAccountOptions(): JsonResponse
    {
        $accounts = ClientAccount::query()
            ->where('status', ClientAccount::STATUS_ACTIVE)
            ->orderBy('company_name')
            ->limit(500)
            ->get(['id', 'company_name', 'shiphero_customer_account_id']);

        return response()->json([
            'accounts' => $accounts->map(static function (ClientAccount $a) {
                $sid = $a->shiphero_customer_account_id;

                return [
                    'id' => $a->id,
                    'company_name' => $a->company_name,
                    'has_shiphero_customer' => is_string($sid) && trim($sid) !== '',
                ];
            })->values(),
        ]);
    }

    public function warehouses(): JsonResponse
    {
        try {
            return response()->json([
                'warehouses' => $this->inventory->listWarehouses(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'string', 'max:255'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);

        $warehouseId = isset($validated['warehouse_id']) && is_string($validated['warehouse_id']) && $validated['warehouse_id'] !== ''
            ? $validated['warehouse_id']
            : null;

        $clientAccountId = isset($validated['client_account_id'])
            ? (int) $validated['client_account_id']
            : null;

        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId(
                $clientAccountId > 0 ? $clientAccountId : null,
                $request,
            );

            $product = $this->inventory->searchProduct($validated['q'], $warehouseId, $shipheroCustomerId);

            return response()->json([
                'product' => $product,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function replaceQuantity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['required', 'string', 'max:255'],
            'location_id' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);

        $reason = isset($validated['reason']) && is_string($validated['reason'])
            ? $validated['reason']
            : 'CRM inventory replace';

        $clientAccountId = isset($validated['client_account_id'])
            ? (int) $validated['client_account_id']
            : null;

        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId(
                $clientAccountId > 0 ? $clientAccountId : null,
                $request,
            );

            $updated = $this->inventory->replaceLocationQuantity(
                $validated['sku'],
                $validated['warehouse_id'],
                $validated['location_id'],
                (int) $validated['quantity'],
                $reason,
                $shipheroCustomerId,
            );

            return response()->json([
                'warehouse' => $updated,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * ShipHero `customer_account_id` for 3PL: from the selected CRM client account, else optional .env fallback.
     *
     * @return string|null Non-empty GraphQL id, or null for brand-level calls
     */
    private function resolveShipHeroCustomerAccountId(?int $clientAccountId, Request $request): ?string
    {
        if ($clientAccountId !== null && $clientAccountId > 0) {
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
                        'This client account has no ShipHero customer account ID. Set it on the account profile (Payment section), then try again.',
                    ],
                ]);
            }

            return trim($sid);
        }

        $env = config('services.shiphero.customer_account_id');

        return (is_string($env) && trim($env) !== '') ? trim($env) : null;
    }
}
