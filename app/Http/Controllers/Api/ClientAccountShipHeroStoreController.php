<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ShipHeroStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ClientAccountShipHeroStoreController extends Controller
{
    /** @var ShipHeroStoreService */
    private $stores;

    public function __construct(ShipHeroStoreService $stores)
    {
        $this->stores = $stores;
    }

    private function assertStaff(Request $request): void
    {
        $user = $request->user();
        if ($user !== null && (int) ($user->client_account_id ?? 0) > 0) {
            abort(403, 'ShipHero store endpoints are for staff only.');
        }
    }

    public function index(Request $request, ClientAccount $client_account): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorize('viewStores', $client_account);

        $cached = $this->stores->getCachedForAccount($client_account);
        $customerId = trim((string) $client_account->shiphero_customer_account_id);

        return response()->json([
            'stores' => $cached['stores'],
            'imported_at' => $cached['imported_at'],
            'shiphero_customer_account_id' => $customerId !== '' ? $customerId : null,
            'can_import' => $request->user() !== null && $request->user()->can('createStore', $client_account),
        ]);
    }

    public function import(Request $request, ClientAccount $client_account): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorize('createStore', $client_account);

        try {
            $result = $this->stores->importForAccount($client_account);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'stores' => $result['stores'],
            'imported_at' => $result['imported_at'],
            'shiphero_customer_account_id' => trim((string) $client_account->shiphero_customer_account_id),
        ]);
    }
}
