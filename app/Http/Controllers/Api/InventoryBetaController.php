<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\CrossAccountInventoryListService;
use App\Services\InventoryProductDetailCacheService;
use App\Services\ShipHeroInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

    /** @var CrossAccountInventoryListService */
    protected $crossAccountInventory;

    public function __construct(
        ShipHeroInventoryService $inventory,
        InventoryProductDetailCacheService $detailCache,
        CrossAccountInventoryListService $crossAccountInventory
    ) {
        $this->inventory = $inventory;
        $this->detailCache = $detailCache;
        $this->crossAccountInventory = $crossAccountInventory;
    }

    public function catalogSync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $clientAccountId = (int) $validated['client_account_id'];
        Log::info('inventory_beta.catalog_sync.start', [
            'client_account_id' => $clientAccountId,
        ]);
        $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
        $this->inventory->resolveStaleRunningCatalogSync($clientAccountId);

        $meta = $this->inventory->catalogSyncMetaForAccount($clientAccountId);
        Log::info('inventory_beta.catalog_sync.completed', [
            'client_account_id' => $clientAccountId,
            'status' => $meta['inventory_catalog_sync_status'] ?? 'idle',
        ]);

        return response()->json([
            'catalog_sync' => $meta,
        ]);
    }

    public function revision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);

        $clientAccountId = (int) $validated['client_account_id'];
        $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);

        return response()->json([
            'revision' => $this->inventory->getCatalogRevision($clientAccountId),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
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

        $clientAccountId = isset($validated['client_account_id'])
            ? (int) $validated['client_account_id']
            : 0;
        $searchQuery = isset($validated['query']) && is_string($validated['query']) ? trim($validated['query']) : '';
        $refresh = (bool) ($validated['refresh'] ?? false);

        if ($clientAccountId <= 0) {
            if ($refresh) {
                throw ValidationException::withMessages([
                    'client_account_id' => ['Select an account to sync catalog.'],
                ]);
            }

            if ($searchQuery === '') {
                return response()->json([
                    'rows' => [],
                    'page_info' => [
                        'has_next_page' => false,
                        'end_cursor' => null,
                    ],
                    'meta' => [
                        'cross_account' => true,
                    ],
                ]);
            }

            if (! empty($validated['after'])) {
                throw ValidationException::withMessages([
                    'after' => ['Load more is not available when searching all accounts. Use Search to load the next page.'],
                ]);
            }

            try {
                $payload = $this->crossAccountInventory->listCatalog($user, $validated);

                return response()->json([
                    'rows' => $payload['rows'],
                    'page_info' => $payload['page_info'],
                    'meta' => $payload['meta'],
                ]);
            } catch (ValidationException $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);

                return response()->json([
                    'message' => config('app.debug')
                        ? $e->getMessage()
                        : 'Could not search inventory catalog.',
                ], 502);
            }
        }

        $first = isset($validated['first']) ? (int) $validated['first'] : 100;
        $after = isset($validated['after']) && is_string($validated['after']) ? $validated['after'] : null;
        $kits = isset($validated['kits']) && is_string($validated['kits']) ? $validated['kits'] : 'all';
        $activeStatus = isset($validated['active_status']) && is_string($validated['active_status'])
            ? $validated['active_status']
            : 'active';
        $searchSkip = isset($validated['search_skip']) ? (int) $validated['search_skip'] : 0;
        $backorderOnly = (bool) ($validated['backorder_only'] ?? false);
        $syncMode = isset($validated['sync_mode']) && is_string($validated['sync_mode'])
            ? $validated['sync_mode']
            : ShipHeroInventoryService::CATALOG_SYNC_INCREMENTAL;

        $this->inventory->resolveStaleRunningCatalogSync($clientAccountId);

        if ($refresh) {
            try {
                $this->inventory->assertCanBeginCatalogSync($clientAccountId);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'Catalog sync is already in progress.') {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'catalog_sync' => $this->inventory->catalogSyncMetaForAccount($clientAccountId),
                    ], 409);
                }

                throw $e;
            }

            try {
                $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
                $this->inventory->dispatchCatalogSyncJob($clientAccountId, $shipheroCustomerId, $syncMode);

                return response()->json([
                    'rows' => [],
                    'page_info' => [
                        'has_next_page' => false,
                        'end_cursor' => null,
                    ],
                    'catalog_sync' => $this->inventory->catalogSyncMetaForAccount($clientAccountId),
                    'message' => 'Catalog sync started in the background.',
                ], 202);
            } catch (ValidationException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->inventory->markCatalogSyncFailed($clientAccountId);
                report($e);

                return response()->json([
                    'message' => config('app.debug')
                        ? $e->getMessage()
                        : 'Could not start catalog sync.',
                ], 502);
            }
        }

        Log::info('inventory_beta.list.start', [
            'client_account_id' => $clientAccountId,
            'refresh' => false,
            'backorder_only' => $backorderOnly,
            'first' => $first,
            'kits' => $kits,
            'active_status' => $activeStatus,
            'has_query' => $searchQuery !== '',
        ]);

        $listStartedAt = microtime(true);
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
                false,
                $syncMode
            );

            $account = ClientAccount::query()->find($clientAccountId);
            $companyName = $account !== null ? (string) $account->company_name : '';
            $rows = array_map(static function ($row) use ($clientAccountId, $companyName) {
                if (! is_array($row)) {
                    return $row;
                }
                $row['client_account_id'] = $clientAccountId;
                if (trim((string) ($row['client_account_company_name'] ?? '')) === '') {
                    $row['client_account_company_name'] = $companyName;
                }

                return $row;
            }, $payload['rows'] ?? []);

            Log::info('inventory_beta.list.completed', [
                'client_account_id' => $clientAccountId,
                'refresh' => false,
                'backorder_only' => $backorderOnly,
                'row_count' => count($rows),
                'index_ms' => (int) round((microtime(true) - $listStartedAt) * 1000),
            ]);

            return response()->json([
                'rows' => array_values($rows),
                'page_info' => $payload['page_info'],
                'catalog_sync' => $this->inventory->catalogSyncMetaForAccount($clientAccountId),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            Log::warning('inventory_beta.list.failed', [
                'client_account_id' => $clientAccountId,
                'exception' => RuntimeException::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);
            Log::warning('inventory_beta.list.failed', [
                'client_account_id' => $clientAccountId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

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
