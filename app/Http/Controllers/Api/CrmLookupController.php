<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\OrderSkuLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CrmLookupController extends Controller
{
    /** @var OrderSkuLookupService */
    private $lookup;

    public function __construct(OrderSkuLookupService $lookup)
    {
        $this->lookup = $lookup;
    }

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:255'],
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ((int) ($user->client_account_id ?? 0) > 0) {
            return response()->json(['message' => 'Use portal lookup for client portal users.'], 403);
        }

        if (! Gate::forUser($user)->check('orders.view') && ! Gate::forUser($user)->check('inventory.view')) {
            return response()->json(['message' => 'You do not have permission to search orders or inventory.'], 403);
        }

        $clientAccountId = (int) $validated['client_account_id'];
        $account = ClientAccount::query()->findOrFail($clientAccountId);
        Gate::forUser($user)->authorize('view', $account);

        $query = $this->lookup->normalizeLookupQuery((string) $validated['query']);
        if ($query === '') {
            throw ValidationException::withMessages([
                'query' => ['Enter an order number or SKU.'],
            ]);
        }

        try {
            $customerId = $this->lookup->resolveShipHeroCustomerAccountId($account);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'client_account_id' => [$e->getMessage()],
            ]);
        }

        $match = $this->lookup->lookup($clientAccountId, $customerId, $query);
        if ($match !== null) {
            if (! empty($match['multiple'])) {
                return response()->json([
                    'message' => 'Multiple orders match that number.',
                ], 422);
            }

            return response()->json($match);
        }

        return response()->json(['message' => 'Not found.'], 404);
    }
}
