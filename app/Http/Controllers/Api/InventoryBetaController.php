<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\InventoryProductDetailCacheService;
use App\Services\ShipHeroInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class InventoryBetaController extends Controller
{
    /** @var ShipHeroInventoryService */
    protected $inventory;

    /** @var InventoryProductDetailCacheService */
    protected $detailCache;

    public function __construct(
        ShipHeroInventoryService $inventory,
        InventoryProductDetailCacheService $detailCache
    ) {
        $this->inventory = $inventory;
        $this->detailCache = $detailCache;
    }

    public function list(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'first' => ['nullable', 'integer', 'min:1', 'max:200'],
            'after' => ['nullable', 'string', 'max:500'],
            'kits' => ['nullable', 'string', Rule::in(['all', 'yes', 'no'])],
            'active_status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'all'])],
            'query' => ['nullable', 'string', 'max:255'],
            'search_skip' => ['nullable', 'integer', 'min:0', 'max:500000'],
            'backorder_only' => ['nullable', 'boolean'],
            'refresh' => ['nullable', 'boolean'],
            'sync_mode' => ['nullable', 'string', Rule::in(['incremental', 'full'])],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $clientAccountId = (int) $validated['client_account_id'];
        $first = isset($validated['first']) ? (int) $validated['first'] : 100;
        $after = isset($validated['after']) && is_string($validated['after']) ? $validated['after'] : null;
        $kits = isset($validated['kits']) && is_string($validated['kits']) ? $validated['kits'] : 'all';
        $activeStatus = isset($validated['active_status']) && is_string($validated['active_status'])
            ? $validated['active_status']
            : 'active';
        $searchQuery = isset($validated['query']) && is_string($validated['query']) ? trim($validated['query']) : '';
        $searchSkip = isset($validated['search_skip']) ? (int) $validated['search_skip'] : 0;
        $backorderOnly = (bool) ($validated['backorder_only'] ?? false);
        $refresh = (bool) ($validated['refresh'] ?? false);
        $syncMode = isset($validated['sync_mode']) && is_string($validated['sync_mode'])
            ? $validated['sync_mode']
            : ShipHeroInventoryService::CATALOG_SYNC_INCREMENTAL;

        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
            $payload = $this->inventory->listCatalogInventoryRows(
                $shipheroCustomerId,
                $first,
                $after,
                $kits,
                $activeStatus,
                $searchQuery !== '' ? $searchQuery : null,
                $searchSkip,
                $clientAccountId,
                $backorderOnly,
                $refresh,
                $syncMode
            );

            $hasNextPage = (bool) ($payload['page_info']['has_next_page'] ?? false);
            if ($refresh && ! $hasNextPage) {
                if ($syncMode === ShipHeroInventoryService::CATALOG_SYNC_FULL) {
                    $this->inventory->markCatalogSyncCompleted($clientAccountId);
                } else {
                    $this->inventory->finalizeIncrementalCatalogSync($clientAccountId);
                }
            }

            return response()->json([
                'rows' => $payload['rows'],
                'page_info' => $payload['page_info'],
                'catalog_sync' => $this->inventory->catalogSyncMetaForAccount($clientAccountId),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if ($refresh) {
                $this->inventory->markCatalogSyncFailed($clientAccountId);
            }

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            if ($refresh) {
                $this->inventory->markCatalogSyncFailed($clientAccountId);
            }
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function syncCatalogProduct(Request $request, string $sku): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);

        $clientAccountId = (int) $validated['client_account_id'];
        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
            $rows = $this->inventory->syncCatalogProductBySku($clientAccountId, $shipheroCustomerId, $sku);
            $this->detailCache->clearForSku($clientAccountId, $sku);

            return response()->json([
                'rows' => $rows,
                'catalog_sync' => $this->inventory->catalogSyncMetaForAccount($clientAccountId),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not sync product from ShipHero.',
            ], 502);
        }
    }

    private function resolveShipHeroCustomerAccountId(int $clientAccountId, Request $request): ?string
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
                    'This client account has no ShipHero customer account ID. Set it on the account profile (Payment section), then try again.',
                ],
            ]);
        }

        return trim($sid);
    }
}
