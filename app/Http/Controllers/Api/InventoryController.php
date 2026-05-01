<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountOnDemandProduct;
use App\Services\ShipHeroClient;
use App\Services\ShipHeroInventoryService;
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

    public function __construct(ShipHeroInventoryService $inventory, ShipHeroClient $shipHeroClient)
    {
        $this->inventory = $inventory;
        $this->shipHeroClient = $shipHeroClient;
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
            $shipheroCustomerId = $this->resolveShipHeroCustomerAccountId(
                $clientAccountId > 0 ? $clientAccountId : null,
                $request,
            );

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
            $product = $this->inventory->getProductDetailBySku($sku, $warehouseId, $shipheroCustomerId);
            if (! is_array($product)) {
                return response()->json(['message' => 'Product not found.'], 404);
            }
            return response()->json(['product' => $product]);
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
            'to_location_id' => ['required', 'string', 'max:255'],
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

            $updated = $this->inventory->transferLocationQuantity(
                $validated['sku'],
                $validated['warehouse_id'],
                $validated['from_location_id'],
                $validated['to_location_id'],
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
