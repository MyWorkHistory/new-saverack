<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountAsnLine;
use App\Models\ClientAccountOnDemandProduct;
use App\Services\ShipHeroClient;
use App\Services\InventoryProductDetailCacheService;
use App\Services\InventoryRestockReportService;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroOrderService;
use App\Support\Barcode\Code128Svg;
use Barryvdh\DomPDF\Facade\Pdf;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class InventoryController extends Controller
{
    /** @var ShipHeroInventoryService */
    protected $inventory;
    /** @var ShipHeroClient */
    protected $shipHeroClient;
    /** @var ShipHeroOrderService */
    protected $orders;
    /** @var InventoryProductDetailCacheService */
    protected $detailCache;

    public function __construct(
        ShipHeroInventoryService $inventory,
        ShipHeroClient $shipHeroClient,
        ShipHeroOrderService $orders,
        InventoryProductDetailCacheService $detailCache
    ) {
        $this->inventory = $inventory;
        $this->shipHeroClient = $shipHeroClient;
        $this->orders = $orders;
        $this->detailCache = $detailCache;
    }

    public function clientAccountOptions(): JsonResponse
    {
        try {
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
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load client accounts. If you recently deployed, run migrations on the server.',
                'accounts' => [],
            ], 500);
        }
    }

    public function warehouses(): JsonResponse
    {
        try {
            return response()->json([
                'warehouses' => $this->inventory->listWarehouses(),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function restockReport(Request $request, InventoryRestockReportService $reports): JsonResponse
    {
        try {
            $warehouseId = $request->query('warehouse_id');
            $warehouseId = is_string($warehouseId) && trim($warehouseId) !== '' ? trim($warehouseId) : null;
            $snapshot = $reports->latestSnapshot($warehouseId);
            if ($snapshot !== null) {
                return response()->json($snapshot);
            }

            return response()->json([
                'warehouse_id' => $reports->resolveWarehouseId($warehouseId),
                'computed_at' => null,
                'rows' => [],
                'row_count' => 0,
                'status' => null,
                'error_message' => null,
                'duration_ms' => null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load restock report.',
            ], 500);
        }
    }

    public function refreshRestockReport(Request $request, InventoryRestockReportService $reports): JsonResponse
    {
        try {
            $validated = $request->validate([
                'warehouse_id' => ['nullable', 'string', 'max:128'],
            ]);
            $warehouseId = isset($validated['warehouse_id']) ? trim((string) $validated['warehouse_id']) : null;
            $warehouseId = $warehouseId !== '' ? $warehouseId : null;
            if ($reports->isRefreshInProgress($warehouseId)) {
                $snapshot = $reports->latestSnapshot($warehouseId);
                if ($snapshot !== null) {
                    return response()->json($snapshot, 200);
                }
            }

            // Run refresh inline so the page works even when queue workers are not running.
            $result = $reports->refresh($warehouseId);

            return response()->json($result, 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not refresh restock report.',
            ], 500);
        }
    }

    public function diagnostic(): JsonResponse
    {
        $checks = [];

        $checks[] = $this->runDiagnosticCheck('env_present', function () {
            $missing = [];

            $refreshToken = config('services.shiphero.refresh_token');
            if (! is_string($refreshToken) || trim($refreshToken) === '') {
                $missing[] = 'SHIPHERO_REFRESH_TOKEN';
            }

            $authUrl = config('services.shiphero.auth_url');
            if (! is_string($authUrl) || trim($authUrl) === '') {
                $missing[] = 'SHIPHERO_AUTH_URL';
            }

            $apiUrl = config('services.shiphero.api_url');
            if (! is_string($apiUrl) || trim($apiUrl) === '') {
                $missing[] = 'SHIPHERO_API_URL';
            }

            if ($missing !== []) {
                throw new RuntimeException('Missing env keys: '.implode(', ', $missing));
            }
        });

        $checks[] = $this->runDiagnosticCheck('dns_lookup', function () {
            $host = parse_url((string) config('services.shiphero.auth_url'), PHP_URL_HOST);
            $host = is_string($host) && $host !== '' ? $host : 'public-api.shiphero.com';
            $resolved = gethostbynamel($host);

            if (! is_array($resolved) || $resolved === []) {
                throw new RuntimeException('Could not resolve host: '.$host);
            }
        });

        $checks[] = $this->runDiagnosticCheck('tcp_connect', function () {
            $host = parse_url((string) config('services.shiphero.auth_url'), PHP_URL_HOST);
            $host = is_string($host) && $host !== '' ? $host : 'public-api.shiphero.com';
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, 443, $errno, $errstr, 3.0);
            if (! is_resource($socket)) {
                throw new RuntimeException('TCP 443 connect failed: '.$host.' ('.$errno.') '.$errstr);
            }

            fclose($socket);
        });

        $checks[] = $this->runDiagnosticCheck('https_get', function () {
            $authBase = rtrim((string) config('services.shiphero.auth_url', 'https://public-api.shiphero.com/auth'), '/');
            $client = new Client(['http_errors' => false]);
            $client->get($authBase, [
                'connect_timeout' => 3,
                'timeout' => 5,
            ]);
        });

        $checks[] = $this->runDiagnosticCheck('auth_refresh', function () {
            Cache::forget('shiphero.access_token');
            $token = $this->shipHeroClient->accessToken();
            if (! is_string($token) || trim($token) === '') {
                throw new RuntimeException('Token refresh returned an empty token.');
            }
        });

        $checks[] = $this->runDiagnosticCheck('graphql_warehouses', function () {
            Cache::forget('shiphero.warehouses');

            return [
                'warehouse_count' => count($this->inventory->listWarehouses()),
            ];
        });

        $firstFailed = null;
        foreach ($checks as $check) {
            if (($check['ok'] ?? false) !== true) {
                $firstFailed = (string) ($check['step'] ?? 'unknown');
                break;
            }
        }

        return response()->json([
            'ok' => $firstFailed === null,
            'checks' => $checks,
            'summary' => $firstFailed === null
                ? 'all checks passed'
                : 'blocked at: '.$firstFailed,
        ]);
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
            // Search should be global (no customer scope) unless client_account_id is explicitly provided.
            $shipheroCustomerId = null;
            if ($clientAccountId > 0) {
                $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
            }

            $product = $this->inventory->searchProduct($validated['q'], $warehouseId, $shipheroCustomerId);

            return response()->json([
                'product' => $product,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function productDetail(Request $request, string $sku): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'string', 'max:255'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $warehouseId = isset($validated['warehouse_id']) && is_string($validated['warehouse_id']) && $validated['warehouse_id'] !== ''
            ? $validated['warehouse_id']
            : null;
        $clientAccountId = isset($validated['client_account_id'])
            ? (int) $validated['client_account_id']
            : null;
        $refresh = (bool) ($validated['refresh'] ?? false);

        try {
            if ($clientAccountId > 0 && ! $refresh) {
                $cached = $this->detailCache->getCachedProduct($clientAccountId, $sku);
                if ($cached !== null) {
                    return response()->json([
                        'product' => $cached,
                        'cached' => true,
                    ]);
                }
            }

            // Product detail should be global unless client_account_id is explicitly provided.
            $shipheroCustomerId = null;
            if ($clientAccountId > 0) {
                $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
            }
            $product = $this->inventory->getProductDetailBySku($sku, $warehouseId, $shipheroCustomerId, false);
            if (! is_array($product) && $clientAccountId > 0) {
                $product = $this->resolveProductDetailForAccountSku($clientAccountId, $sku, $shipheroCustomerId);
            }
            if (! is_array($product)) {
                return response()->json(['message' => 'Product not found.'], 404);
            }
            if ($clientAccountId > 0) {
                $this->detailCache->putProduct($clientAccountId, $sku, $product);
            }

            return response()->json(['product' => $product, 'cached' => false]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function productAllocatedOrders(Request $request, string $sku): JsonResponse
    {
        return $this->productOrdersForSku($request, $sku, 'allocated');
    }

    public function productBackorderOrders(Request $request, string $sku): JsonResponse
    {
        return $this->productOrdersForSku($request, $sku, 'backorder');
    }

    public function productParentKits(Request $request, string $sku): JsonResponse
    {
        return $this->productKitSectionForSku($request, $sku, 'parent_kits');
    }

    public function productKitComponents(Request $request, string $sku): JsonResponse
    {
        return $this->productKitSectionForSku($request, $sku, 'kit_components');
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response|JsonResponse
     */
    public function productBarcodeLabelPdf(Request $request, string $sku)
    {
        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'barcode' => ['nullable', 'string', 'max:255'],
        ]);
        $clientAccountId = isset($validated['client_account_id'])
            ? (int) $validated['client_account_id']
            : null;

        try {
            $skuLabel = trim($sku);
            $barcode = isset($validated['barcode']) ? trim((string) $validated['barcode']) : '';
            if ($barcode === '') {
                $shipheroCustomerId = null;
                if ($clientAccountId > 0) {
                    $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
                }
                $product = $this->inventory->getProductDetailBySku($sku, null, $shipheroCustomerId);
                if (! is_array($product)) {
                    return response()->json(['message' => 'Product not found.'], 404);
                }
                $barcode = isset($product['barcode']) ? trim((string) $product['barcode']) : '';
                $skuLabel = isset($product['sku']) ? trim((string) $product['sku']) : $skuLabel;
            }
            if ($barcode === '') {
                return response()->json(['message' => 'No barcode on file for this SKU in ShipHero.'], 422);
            }
            $pdf = Pdf::loadView('pdf.inventory.barcode', [
                'sku' => $skuLabel,
                'barcode' => $barcode,
                'barcodeSvg' => Code128Svg::dataUri($barcode),
            ])->setPaper([0, 0, 288, 144]);

            $safeSku = preg_replace('/[^A-Za-z0-9_-]+/', '-', $skuLabel);
            $safeSku = trim((string) $safeSku, '-');

            return $pdf->stream(($safeSku !== '' ? $safeSku : 'product').'-barcode.pdf');
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not generate barcode label.',
            ], 502);
        }
    }

    /**
     * @return JsonResponse
     */
    private function productKitSectionForSku(Request $request, string $sku, string $section): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $clientAccountId = isset($validated['client_account_id'])
            ? (int) $validated['client_account_id']
            : 0;
        $refresh = (bool) ($validated['refresh'] ?? false);

        try {
            if ($clientAccountId > 0 && ! $refresh) {
                $cached = $section === 'parent_kits'
                    ? $this->detailCache->getCachedParentKits($clientAccountId, $sku)
                    : $this->detailCache->getCachedKitComponents($clientAccountId, $sku);
                if ($cached !== null) {
                    return response()->json([
                        'rows' => $cached,
                        'cached' => true,
                    ]);
                }
            }

            $shipheroCustomerId = $clientAccountId > 0
                ? $this->resolveShipHeroCustomerAccountId($clientAccountId, $request)
                : null;
            $rows = $section === 'parent_kits'
                ? $this->inventory->getParentKitsForSku($sku, $shipheroCustomerId)
                : $this->inventory->getKitComponentsForSku($sku, $shipheroCustomerId);

            if ($clientAccountId > 0) {
                if ($section === 'parent_kits') {
                    $this->detailCache->putParentKits($clientAccountId, $sku, $rows);
                } else {
                    $this->detailCache->putKitComponents($clientAccountId, $sku, $rows);
                }
            }

            return response()->json([
                'rows' => $rows,
                'cached' => false,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);
            $label = $section === 'parent_kits' ? 'parent kits' : 'kit components';

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load '.$label.' from ShipHero.',
            ], 502);
        }
    }

    /**
     * @return JsonResponse
     */
    private function productOrdersForSku(Request $request, string $sku, string $mode)
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $clientAccountId = (int) $validated['client_account_id'];
        $refresh = (bool) ($validated['refresh'] ?? false);

        try {
            if (! $refresh) {
                $cached = $this->detailCache->getCachedOrders($clientAccountId, $sku, $mode);
                if ($cached !== null) {
                    return response()->json(array_merge($cached, ['cached' => true]));
                }
            }

            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
            if ($mode === 'backorder') {
                $payload = $this->orders->listOrdersBackorderForSku($shipheroCustomerId, $sku);
            } else {
                $payload = $this->orders->listOrdersAllocatedForSku($shipheroCustomerId, $sku);
            }

            $this->detailCache->putOrders($clientAccountId, $sku, $mode, $payload);

            return response()->json(array_merge($payload, ['cached' => false]));
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            $label = $mode === 'backorder' ? 'backorder orders' : 'allocated orders';

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load '.$label.' from ShipHero.',
            ], 502);
        }
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
        ]);
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
        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
            $payload = $this->inventory->listInventoryRows(
                $shipheroCustomerId,
                $first,
                $after,
                $kits,
                $activeStatus,
                $searchQuery !== '' ? $searchQuery : null,
                $searchSkip,
                $clientAccountId,
                $backorderOnly,
                $refresh
            );

            return response()->json([
                'rows' => $payload['rows'],
                'page_info' => $payload['page_info'],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    /**
     * Portal / staff: bulk set ShipHero warehouse_product.active (requires inventory.update).
     */
    public function bulkWarehouseProductActive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'active' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.sku' => ['required', 'string', 'max:255'],
            'items.*.warehouse_id' => ['required', 'string', 'max:255'],
        ]);
        $clientAccountId = (int) $validated['client_account_id'];
        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
            $result = $this->inventory->bulkSetWarehouseProductActive(
                (string) $shipheroCustomerId,
                (bool) $validated['active'],
                $validated['items'],
            );

            return response()->json([
                'updated' => $result['updated'],
                'errors' => $result['errors'],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function asnProductCatalog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'first' => ['nullable', 'integer', 'min:25', 'max:100'],
            'after' => ['nullable', 'string', 'max:500'],
            'query' => ['nullable', 'string', 'max:255'],
            'search_skip' => ['nullable', 'integer', 'min:0', 'max:500000'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $clientAccountId = (int) $validated['client_account_id'];
        $graphqlFirst = isset($validated['first']) ? (int) $validated['first'] : 75;
        $after = isset($validated['after']) && is_string($validated['after']) ? $validated['after'] : null;
        $query = isset($validated['query']) && is_string($validated['query']) ? trim($validated['query']) : '';
        $searchSkip = isset($validated['search_skip']) ? (int) $validated['search_skip'] : 0;
        $refresh = (bool) ($validated['refresh'] ?? false);
        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId($clientAccountId, $request);
        } catch (ValidationException $e) {
            throw $e;
        }
        try {
            $payload = $this->inventory->listAsnProductCatalogPage(
                $shipheroCustomerId,
                $graphqlFirst,
                $after,
                $query !== '' ? $query : null,
                $searchSkip,
                $clientAccountId,
                $refresh
            );

            return response()->json([
                'products' => $payload['products'],
                'page_info' => $payload['page_info'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function transferQuantity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['required', 'string', 'max:255'],
            'from_location_id' => ['required', 'string', 'max:255'],
            'to_location_id' => ['nullable', 'string', 'max:255'],
            'to_location' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);
        $reason = isset($validated['reason']) && is_string($validated['reason'])
            ? $validated['reason']
            : 'CRM inventory transfer';
        $clientAccountId = isset($validated['client_account_id'])
            ? (int) $validated['client_account_id']
            : null;

        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId(
                $clientAccountId > 0 ? $clientAccountId : null,
                $request,
            );
            $toLocationId = isset($validated['to_location_id']) && is_string($validated['to_location_id'])
                ? trim($validated['to_location_id'])
                : '';
            if ($toLocationId === '') {
                $toLocationInput = isset($validated['to_location']) && is_string($validated['to_location'])
                    ? trim($validated['to_location'])
                    : '';
                $resolved = $this->inventory->resolveWarehouseLocation(
                    $validated['warehouse_id'],
                    $toLocationInput,
                    $shipheroCustomerId
                );
                if (! is_array($resolved)) {
                    throw ValidationException::withMessages([
                        'to_location' => ['Location not found in this warehouse.'],
                    ]);
                }
                $toLocationId = (string) ($resolved['id'] ?? '');
            }

            $updated = $this->inventory->transferLocationQuantity(
                $validated['sku'],
                $validated['warehouse_id'],
                $validated['from_location_id'],
                $toLocationId,
                (int) $validated['quantity'],
                $reason,
                $shipheroCustomerId
            );

            return response()->json([
                'warehouse' => $updated,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function updateLocationPickable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'string', 'max:255'],
            'pickable' => ['required', 'boolean'],
            'sellable' => ['nullable', 'boolean'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);
        $clientAccountId = isset($validated['client_account_id']) ? (int) $validated['client_account_id'] : null;
        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId(
                $clientAccountId > 0 ? $clientAccountId : null,
                $request,
            );
            $location = $this->inventory->updateLocationPickable(
                $validated['location_id'],
                (bool) $validated['pickable'],
                array_key_exists('sellable', $validated) ? (bool) $validated['sellable'] : null,
                $shipheroCustomerId
            );
            return response()->json(['location' => $location]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function addLocationQuantity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);
        $reason = isset($validated['reason']) && is_string($validated['reason'])
            ? $validated['reason']
            : 'CRM inventory replace';
        $clientAccountId = isset($validated['client_account_id']) ? (int) $validated['client_account_id'] : null;
        try {
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId(
                $clientAccountId > 0 ? $clientAccountId : null,
                $request,
            );
            $resolved = $this->inventory->resolveWarehouseLocation(
                $validated['warehouse_id'],
                $validated['location'],
                $shipheroCustomerId
            );
            if (! is_array($resolved)) {
                throw ValidationException::withMessages([
                    'location' => ['Location not found in this warehouse.'],
                ]);
            }
            $updated = $this->inventory->replaceLocationQuantity(
                $validated['sku'],
                $validated['warehouse_id'],
                (string) ($resolved['id'] ?? ''),
                (int) $validated['quantity'],
                $reason,
                $shipheroCustomerId
            );
            return response()->json([
                'warehouse' => $updated,
                'location' => $resolved,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not reach ShipHero. Check SHIPHERO_* in .env and server logs.',
            ], 502);
        }
    }

    public function onDemandProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'category' => ['nullable', 'string', Rule::in(ClientAccountOnDemandProduct::CATEGORIES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'sort_by' => ['nullable', 'string', Rule::in(['sku', 'account', 'name', 'category', 'price_cents', 'created_at'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $sortBy = (string) ($validated['sort_by'] ?? 'sku');
        $sortDir = (string) ($validated['sort_dir'] ?? 'asc');
        $perPage = min(max((int) ($validated['per_page'] ?? 25), 1), 500);

        $query = ClientAccountOnDemandProduct::query()
            ->with('clientAccount:id,company_name');

        if (isset($validated['client_account_id']) && (int) $validated['client_account_id'] > 0) {
            $query->where('client_account_id', (int) $validated['client_account_id']);
        }

        if (isset($validated['category']) && is_string($validated['category']) && $validated['category'] !== '') {
            $query->where('category', $validated['category']);
        }

        if (isset($validated['q']) && is_string($validated['q']) && trim($validated['q']) !== '') {
            $needle = trim($validated['q']);
            $query->where(function ($q) use ($needle) {
                $q->where('sku', 'like', '%'.$needle.'%')
                    ->orWhere('name', 'like', '%'.$needle.'%')
                    ->orWhereHas('clientAccount', function ($accountQuery) use ($needle) {
                        $accountQuery->where('company_name', 'like', '%'.$needle.'%');
                    });
            });
        }

        if ($sortBy === 'account') {
            $query
                ->leftJoin('client_accounts', 'client_account_on_demand_products.client_account_id', '=', 'client_accounts.id')
                ->orderBy('client_accounts.company_name', $sortDir)
                ->select('client_account_on_demand_products.*');
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        if ($sortBy !== 'sku') {
            $query->orderBy('sku');
        }

        $products = $query->paginate($perPage);

        return response()->json([
            'products' => $products->getCollection()->map(function (ClientAccountOnDemandProduct $product) {
                return $this->onDemandProductPayload($product);
            })->values(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'categories' => ClientAccountOnDemandProduct::CATEGORIES,
        ]);
    }

    public function storeOnDemandProduct(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', ClientAccountOnDemandProduct::class);
        $data = $this->validatedOnDemandProductData($request);
        $account = ClientAccount::query()->findOrFail($data['client_account_id']);
        Gate::forUser($request->user())->authorize('view', $account);

        $product = new ClientAccountOnDemandProduct($data);
        $product->save();
        $product->load('clientAccount:id,company_name');

        return response()->json([
            'product' => $this->onDemandProductPayload($product),
        ], 201);
    }

    public function updateOnDemandProduct(Request $request, ClientAccountOnDemandProduct $onDemandProduct): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $onDemandProduct);
        $data = $this->validatedOnDemandProductData($request, $onDemandProduct);
        $account = ClientAccount::query()->findOrFail($data['client_account_id']);
        Gate::forUser($request->user())->authorize('view', $account);

        $onDemandProduct->fill($data);
        $onDemandProduct->save();
        $onDemandProduct->load('clientAccount:id,company_name');

        return response()->json([
            'product' => $this->onDemandProductPayload($onDemandProduct),
        ]);
    }

    public function destroyOnDemandProduct(Request $request, ClientAccountOnDemandProduct $onDemandProduct): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $onDemandProduct);
        Gate::forUser($request->user())->authorize('view', $onDemandProduct->clientAccount);
        $onDemandProduct->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array{client_account_id:int, sku:string, name:string, category:string, price_cents:int}
     */
    private function validatedOnDemandProductData(Request $request, ?ClientAccountOnDemandProduct $product = null): array
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'sku' => [
                'required',
                'string',
                'max:128',
                function (string $attribute, $value, \Closure $fail) use ($request, $product): void {
                    $sku = trim((string) $value);
                    if ($sku === '') {
                        return;
                    }

                    $exists = ClientAccountOnDemandProduct::query()
                        ->where('client_account_id', (int) $request->input('client_account_id'))
                        ->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
                        ->when($product !== null, function ($query) use ($product) {
                            $query->whereKeyNot($product->id);
                        })
                        ->exists();

                    if ($exists) {
                        $fail('The sku has already been taken for this account.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(ClientAccountOnDemandProduct::CATEGORIES)],
            'price_cents' => ['required', 'integer', 'min:1'],
        ]);

        return [
            'client_account_id' => (int) $validated['client_account_id'],
            'sku' => trim((string) $validated['sku']),
            'name' => trim((string) $validated['name']),
            'category' => (string) $validated['category'],
            'price_cents' => (int) $validated['price_cents'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function onDemandProductPayload(ClientAccountOnDemandProduct $product): array
    {
        return [
            'id' => $product->id,
            'client_account_id' => $product->client_account_id,
            'account_name' => optional($product->clientAccount)->company_name,
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category,
            'price_cents' => $product->price_cents,
            'created_at' => optional($product->created_at)->toIso8601String(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
        ];
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

    /**
     * When ShipHero has no product yet, link ASN lines or create the SKU in ShipHero for portal users.
     *
     * @return array<string, mixed>|null
     */
    private function resolveProductDetailForAccountSku(int $clientAccountId, string $sku, ?string $shipheroCustomerId): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $line = ClientAccountAsnLine::query()
            ->where('sku', $sku)
            ->whereHas('asn', function ($q) use ($clientAccountId) {
                $q->where('client_account_id', $clientAccountId);
            })
            ->orderByDesc('id')
            ->first();

        if ($line === null) {
            return null;
        }

        $customerId = is_string($shipheroCustomerId) ? trim($shipheroCustomerId) : '';
        if ($customerId !== '' && trim((string) ($line->shiphero_product_id ?? '')) === '') {
            try {
                $created = $this->inventory->createProduct($customerId, $sku, (string) $line->name);
                $line->shiphero_product_id = $created['id'];
                if ($line->image_url === null && ! empty($created['image_url'])) {
                    $line->image_url = $created['image_url'];
                }
                $line->save();

                $fromShipHero = $this->inventory->getProductDetailBySku($sku, null, $customerId, false);
                if (is_array($fromShipHero)) {
                    return $fromShipHero;
                }
            } catch (RuntimeException $e) {
                $existing = $this->inventory->getProductDetailBySku($sku, null, $customerId, false);
                if (is_array($existing)) {
                    if (trim((string) ($line->shiphero_product_id ?? '')) === '' && ! empty($existing['id'])) {
                        $line->shiphero_product_id = (string) $existing['id'];
                        $line->save();
                    }

                    return $existing;
                }
            }
        }

        return $this->inventory->minimalProductFromAsnLine(
            $sku,
            (string) $line->name,
            $line->shiphero_product_id,
            $line->image_url
        );
    }

    /**
     * @param  callable():mixed  $callback
     * @return array<string, mixed>
     */
    private function runDiagnosticCheck(string $step, callable $callback): array
    {
        $startedAt = microtime(true);

        try {
            $details = $callback();
            $result = [
                'step' => $step,
                'ok' => true,
                'ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];

            if (is_array($details) && $details !== []) {
                $result['details'] = $details;
            }

            return $result;
        } catch (Throwable $e) {
            report($e);

            return [
                'step' => $step,
                'ok' => false,
                'ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }
}
