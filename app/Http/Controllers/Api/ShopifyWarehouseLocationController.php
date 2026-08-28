<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyWarehouseLocation;
use App\Models\ShopifyWarehouseLocationItem;
use App\Support\ShopifyProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShopifyWarehouseLocationController extends Controller
{
    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null || (! $user->isAdministrator() && ! $user->isCrmOwner())) {
            abort(403, 'Shopify admin access required.');
        }
    }

    public function meta(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json([
            'types' => ShopifyWarehouseLocation::TYPES,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $perPage = max(10, min(100, (int) $request->query('per_page', 10)));
        $sort = (string) $request->query('sort', 'name');
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['name', 'type', 'pickable', 'sellable'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'name';
        }

        $query = $this->filteredQuery($request);
        $query->orderBy($sort, $dir)->orderBy('id');

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (ShopifyWarehouseLocation $row) => $this->serializeLocation($row))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $this->validateLocationPayload($request, null, true);
        $location = ShopifyWarehouseLocation::query()->create($validated);

        return response()->json(['location' => $this->serializeLocation($location->fresh())], 201);
    }

    public function show(Request $request, ShopifyWarehouseLocation $shopifyWarehouseLocation): JsonResponse
    {
        $this->assertAdmin($request);

        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $q = trim((string) $request->query('q', ''));
        $accountId = (int) $request->query('client_account_id', 0);

        $itemsQuery = ShopifyWarehouseLocationItem::query()
            ->where('location_id', $shopifyWarehouseLocation->id)
            ->where('available', '>', 0)
            ->with(['variant.product', 'variant.connection.clientAccount']);

        if ($accountId > 0) {
            $itemsQuery->whereHas('variant.connection', function ($builder) use ($accountId) {
                $builder->where('client_account_id', $accountId);
            });
        }

        if ($q !== '') {
            $itemsQuery->where(function ($builder) use ($q) {
                $builder->whereHas('variant', function ($v) use ($q) {
                    $v->where('sku', 'like', '%'.$q.'%')
                        ->orWhere('title', 'like', '%'.$q.'%');
                })->orWhereHas('variant.product', function ($p) use ($q) {
                    $p->where('title', 'like', '%'.$q.'%');
                });
            });
        }

        $itemsPage = $itemsQuery
            ->orderByDesc('available')
            ->orderBy('id')
            ->paginate($perPage);

        $totalQty = (int) ShopifyWarehouseLocationItem::query()
            ->where('location_id', $shopifyWarehouseLocation->id)
            ->sum('available');

        return response()->json([
            'location' => $this->serializeLocation($shopifyWarehouseLocation),
            'total_qty' => $totalQty,
            'items' => collect($itemsPage->items())->map(fn (ShopifyWarehouseLocationItem $item) => $this->serializeItem($item))->values(),
            'meta' => [
                'current_page' => $itemsPage->currentPage(),
                'last_page' => $itemsPage->lastPage(),
                'per_page' => $itemsPage->perPage(),
                'total' => $itemsPage->total(),
            ],
        ]);
    }

    public function update(Request $request, ShopifyWarehouseLocation $shopifyWarehouseLocation): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $this->validateLocationPayload($request, $shopifyWarehouseLocation->id, false);
        $shopifyWarehouseLocation->fill($validated);
        $shopifyWarehouseLocation->save();

        return response()->json(['location' => $this->serializeLocation($shopifyWarehouseLocation->fresh())]);
    }

    public function destroy(Request $request, ShopifyWarehouseLocation $shopifyWarehouseLocation): JsonResponse
    {
        $this->assertAdmin($request);
        if ($shopifyWarehouseLocation->items()->exists()) {
            throw ValidationException::withMessages([
                'location' => ['Remove all inventory from this location before deleting it.'],
            ]);
        }
        $shopifyWarehouseLocation->delete();

        return response()->json(['message' => 'Location deleted.']);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickable' => ['sometimes', 'boolean'],
            'sellable' => ['sometimes', 'boolean'],
        ]);
        if (! array_key_exists('type', $validated)
            && ! array_key_exists('pickable', $validated)
            && ! array_key_exists('sellable', $validated)
        ) {
            throw ValidationException::withMessages([
                'type' => ['Choose Type, Pickable, or Sellable to update.'],
            ]);
        }

        $updates = [];
        if (array_key_exists('type', $validated)) {
            $type = trim((string) ($validated['type'] ?? ''));
            $updates['type'] = $type === '' ? null : $type;
        }
        if (array_key_exists('pickable', $validated)) {
            $updates['pickable'] = (bool) $validated['pickable'];
        }
        if (array_key_exists('sellable', $validated)) {
            $updates['sellable'] = (bool) $validated['sellable'];
        }

        $count = ShopifyWarehouseLocation::query()
            ->whereIn('id', $validated['ids'])
            ->update($updates);

        return response()->json(['updated' => $count]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->assertAdmin($request);
        $ids = $request->query('ids');
        $query = $this->filteredQuery($request);
        if (is_string($ids) && trim($ids) !== '') {
            $idList = array_filter(array_map('intval', explode(',', $ids)));
            if ($idList !== []) {
                $query->whereIn('id', $idList);
            }
        }
        $rows = $query->orderBy('name')->orderBy('id')->get();

        $filename = 'shopify-locations-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Location Name', 'Type', 'Pickable', 'Sellable']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->name,
                    $row->type,
                    $row->pickable ? 'Yes' : 'No',
                    $row->sellable ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $path = $request->file('file')->getRealPath();
        $parsed = $this->parseLocationsCsv((string) $path);
        $created = 0;
        $updated = 0;
        foreach ($parsed['rows'] as $row) {
            $existing = ShopifyWarehouseLocation::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])
                ->first();
            if ($existing) {
                $existing->fill($row);
                $existing->save();
                $updated++;
            } else {
                ShopifyWarehouseLocation::query()->create($row);
                $created++;
            }
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => count($parsed['errors']),
            'errors' => $parsed['errors'],
        ]);
    }

    public function storeItem(Request $request, ShopifyWarehouseLocation $shopifyWarehouseLocation): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'sku' => ['nullable', 'string', 'max:255'],
            'shopify_variant_id' => ['nullable', 'integer', 'exists:shopify_product_variants,id'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'available' => ['required', 'integer', 'min:1'],
        ]);

        $variantId = isset($validated['shopify_variant_id']) ? (int) $validated['shopify_variant_id'] : 0;
        $accountId = isset($validated['client_account_id']) ? (int) $validated['client_account_id'] : 0;
        $sku = trim((string) ($validated['sku'] ?? ''));

        if ($variantId <= 0 && $sku === '') {
            throw ValidationException::withMessages([
                'sku' => ['Select a product SKU.'],
            ]);
        }

        $variant = null;
        if ($variantId > 0) {
            $variantQuery = ShopifyProductVariant::query()->where('id', $variantId);
            if ($accountId > 0) {
                $variantQuery->whereHas('connection', function ($builder) use ($accountId) {
                    $builder->where('client_account_id', $accountId);
                });
            }
            $variant = $variantQuery->first();
        } else {
            $variantQuery = ShopifyProductVariant::query()->where('sku', $sku);
            if ($accountId > 0) {
                $variantQuery->whereHas('connection', function ($builder) use ($accountId) {
                    $builder->where('client_account_id', $accountId);
                });
            }
            $variant = $variantQuery->orderByDesc('id')->first();
        }

        if ($variant === null) {
            throw ValidationException::withMessages([
                'sku' => ['No Shopify variant found for that SKU'
                    .($accountId > 0 ? ' on the selected account.' : '.')],
            ]);
        }
        /** @var ShopifyWarehouseLocationItem $item */
        $item = ShopifyWarehouseLocationItem::query()->firstOrNew([
            'location_id' => $shopifyWarehouseLocation->id,
            'shopify_variant_id' => $variant->id,
        ]);
        $item->available = (int) $item->available + (int) $validated['available'];
        $item->save();
        $item->load(['variant.product', 'variant.connection.clientAccount']);

        return response()->json(['item' => $this->serializeItem($item)], 201);
    }

    public function updateItemQty(
        Request $request,
        ShopifyWarehouseLocation $shopifyWarehouseLocation,
        ShopifyWarehouseLocationItem $shopifyWarehouseLocationItem
    ): JsonResponse {
        $this->assertAdmin($request);
        if ((int) $shopifyWarehouseLocationItem->location_id !== (int) $shopifyWarehouseLocation->id) {
            abort(404);
        }
        $validated = $request->validate([
            'available' => ['required', 'integer', 'min:0'],
        ]);
        $qty = (int) $validated['available'];
        if ($qty <= 0) {
            $shopifyWarehouseLocationItem->delete();

            return response()->json(['deleted' => true]);
        }
        $shopifyWarehouseLocationItem->available = $qty;
        $shopifyWarehouseLocationItem->save();

        return response()->json([
            'item' => $this->serializeItem($shopifyWarehouseLocationItem->fresh([
                'variant.product',
                'variant.connection.clientAccount',
            ])),
        ]);
    }

    public function destroyItem(
        Request $request,
        ShopifyWarehouseLocation $shopifyWarehouseLocation,
        ShopifyWarehouseLocationItem $shopifyWarehouseLocationItem
    ): JsonResponse {
        $this->assertAdmin($request);
        if ((int) $shopifyWarehouseLocationItem->location_id !== (int) $shopifyWarehouseLocation->id) {
            abort(404);
        }
        $shopifyWarehouseLocationItem->delete();

        return response()->json(['message' => 'Item removed from location.']);
    }

    public function transfer(Request $request, ShopifyWarehouseLocation $shopifyWarehouseLocation): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);
        $to = $this->resolveTransferDestination(
            $shopifyWarehouseLocation,
            (int) $validated['to_location_id']
        );

        try {
            DB::transaction(function () use ($validated, $shopifyWarehouseLocation, $to) {
                $this->performTransfer(
                    $shopifyWarehouseLocation,
                    $to,
                    (int) $validated['item_id'],
                    (int) $validated['quantity']
                );
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'quantity' => ['Could not transfer inventory.'],
            ]);
        }

        return response()->json(['message' => 'Inventory transferred.']);
    }

    public function bulkTransfer(Request $request, ShopifyWarehouseLocation $shopifyWarehouseLocation): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'distinct'],
            'to_location_id' => ['required', 'integer'],
        ]);
        $to = $this->resolveTransferDestination(
            $shopifyWarehouseLocation,
            (int) $validated['to_location_id']
        );

        $transferred = 0;
        $skipped = [];
        try {
            DB::transaction(function () use ($validated, $shopifyWarehouseLocation, $to, &$transferred, &$skipped) {
                foreach ($validated['item_ids'] as $itemId) {
                    $itemId = (int) $itemId;
                    try {
                        $this->performTransfer($shopifyWarehouseLocation, $to, $itemId, null);
                        $transferred++;
                    } catch (ValidationException $e) {
                        $skipped[] = $itemId;
                    }
                }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'item_ids' => ['Could not transfer inventory.'],
            ]);
        }

        if ($transferred === 0) {
            throw ValidationException::withMessages([
                'item_ids' => ['No items could be transferred.'],
            ]);
        }

        return response()->json([
            'message' => 'Inventory transferred.',
            'transferred' => $transferred,
            'skipped' => count($skipped),
            'skipped_ids' => $skipped,
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $exclude = (int) $request->query('exclude', 0);
        $query = ShopifyWarehouseLocation::query()->orderBy('name')->orderBy('id');
        if ($exclude > 0) {
            $query->where('id', '!=', $exclude);
        }

        return response()->json([
            'data' => $query->get(['id', 'name'])->map(function (ShopifyWarehouseLocation $row) {
                return ['id' => $row->id, 'name' => $row->name];
            })->values(),
        ]);
    }

    private function resolveTransferDestination(
        ShopifyWarehouseLocation $from,
        int $toId
    ): ShopifyWarehouseLocation {
        if ($toId === (int) $from->id) {
            throw ValidationException::withMessages([
                'to_location_id' => ['Choose a different destination location.'],
            ]);
        }

        $to = ShopifyWarehouseLocation::query()->find($toId);
        if ($to === null) {
            throw ValidationException::withMessages([
                'to_location_id' => ['Destination location was not found.'],
            ]);
        }

        return $to;
    }

    /**
     * @param  int|null  $quantity  Null transfers full available qty.
     */
    private function performTransfer(
        ShopifyWarehouseLocation $from,
        ShopifyWarehouseLocation $to,
        int $itemId,
        ?int $quantity
    ): void {
        /** @var ShopifyWarehouseLocationItem|null $fromItem */
        $fromItem = ShopifyWarehouseLocationItem::query()
            ->where('id', $itemId)
            ->where('location_id', $from->id)
            ->lockForUpdate()
            ->first();
        if ($fromItem === null) {
            throw ValidationException::withMessages([
                'item_id' => ['Item was not found at this location.'],
            ]);
        }

        $qty = $quantity ?? (int) $fromItem->available;
        if ($qty < 1) {
            throw ValidationException::withMessages([
                'quantity' => ['Nothing available to transfer for this item.'],
            ]);
        }
        if ($fromItem->available < $qty) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity exceeds available stock at this location.'],
            ]);
        }

        $fromItem->available -= $qty;
        if ($fromItem->available <= 0) {
            $fromItem->delete();
        } else {
            $fromItem->save();
        }

        /** @var ShopifyWarehouseLocationItem $toItem */
        $toItem = ShopifyWarehouseLocationItem::query()->firstOrNew([
            'location_id' => $to->id,
            'shopify_variant_id' => $fromItem->shopify_variant_id,
        ]);
        $toItem->available = (int) $toItem->available + $qty;
        $toItem->save();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<ShopifyWarehouseLocation>
     */
    private function filteredQuery(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));
        $pickable = $request->query('pickable');
        $sellable = $request->query('sellable');

        $query = ShopifyWarehouseLocation::query();
        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', '%'.$q.'%')
                    ->orWhere('type', 'like', '%'.$q.'%');
            });
        }
        if ($type !== '' && strtolower($type) !== 'all') {
            $query->where('type', $type);
        }
        if ($pickable === '1' || $pickable === 'true' || $pickable === 'yes') {
            $query->where('pickable', true);
        } elseif ($pickable === '0' || $pickable === 'false' || $pickable === 'no') {
            $query->where('pickable', false);
        }
        if ($sellable === '1' || $sellable === 'true' || $sellable === 'yes') {
            $query->where('sellable', true);
        } elseif ($sellable === '0' || $sellable === 'false' || $sellable === 'no') {
            $query->where('sellable', false);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateLocationPayload(Request $request, ?int $ignoreId, bool $creating): array
    {
        $nameRule = Rule::unique('shopify_warehouse_locations', 'name');
        if ($ignoreId !== null) {
            $nameRule = $nameRule->ignore($ignoreId);
        }

        $validated = $request->validate([
            'name' => array_merge($creating ? ['required'] : ['sometimes'], ['string', 'max:191', $nameRule]),
            'type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pickable' => ['sometimes', 'boolean'],
            'sellable' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('type', $validated)) {
            $type = trim((string) ($validated['type'] ?? ''));
            $validated['type'] = $type === '' ? null : $type;
        }
        if ($creating && ! array_key_exists('pickable', $validated)) {
            $validated['pickable'] = true;
        }
        if ($creating && ! array_key_exists('sellable', $validated)) {
            $validated['sellable'] = true;
        }

        return $validated;
    }

    /**
     * @return array{id: int, name: string, type: ?string, pickable: bool, sellable: bool, active: bool}
     */
    private function serializeLocation(ShopifyWarehouseLocation $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'type' => $location->type,
            'pickable' => (bool) $location->pickable,
            'sellable' => (bool) $location->sellable,
            'active' => (bool) $location->active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(ShopifyWarehouseLocationItem $item): array
    {
        $variant = $item->variant;
        $product = $variant ? $variant->product : null;
        $variantRaw = ($variant && is_array($variant->raw_json)) ? $variant->raw_json : null;
        $productRaw = ($product && is_array($product->raw_json)) ? $product->raw_json : null;
        $connection = $variant ? $variant->connection : null;
        $account = $connection ? $connection->clientAccount : null;

        return [
            'id' => $item->id,
            'available' => (int) $item->available,
            'variant_id' => $item->shopify_variant_id,
            'sku' => $variant ? $variant->sku : null,
            'product_title' => $product ? $product->title : ($variant ? $variant->title : null),
            'variant_title' => $variant ? $variant->title : null,
            'image_url' => ShopifyProductImage::url($variantRaw, $productRaw),
            'client_account_id' => $account ? (int) $account->id : null,
            'account_name' => $account ? $account->company_name : null,
        ];
    }

    /**
     * @return array{rows: list<array{name: string, type: ?string, pickable: bool, sellable: bool}>, errors: list<array{row: int, message: string}>}
     */
    private function parseLocationsCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw ValidationException::withMessages([
                'file' => ['Could not read the uploaded CSV file.'],
            ]);
        }

        try {
            $headerRow = fgetcsv($fh);
            if ($headerRow === false || $headerRow === [null]) {
                throw ValidationException::withMessages([
                    'file' => ['CSV is empty.'],
                ]);
            }
            $map = $this->mapCsvHeaders($headerRow);
            if (! isset($map['name'])) {
                throw ValidationException::withMessages([
                    'file' => ['CSV must include a Location Name column.'],
                ]);
            }

            $rows = [];
            $errors = [];
            $line = 1;
            while (($raw = fgetcsv($fh)) !== false) {
                $line++;
                if ($raw === [null] || $this->csvRowEmpty($raw)) {
                    continue;
                }
                $name = trim((string) ($raw[$map['name']] ?? ''));
                if ($name === '') {
                    $errors[] = ['row' => $line, 'message' => 'Location name is required.'];
                    continue;
                }
                $type = isset($map['type']) ? trim((string) ($raw[$map['type']] ?? '')) : '';
                $rows[] = [
                    'name' => $name,
                    'type' => $type === '' ? null : $type,
                    'pickable' => isset($map['pickable']) ? $this->parseCsvBool($raw[$map['pickable']] ?? true, true) : true,
                    'sellable' => isset($map['sellable']) ? $this->parseCsvBool($raw[$map['sellable']] ?? true, true) : true,
                ];
            }

            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array{name?: int, type?: int, pickable?: int, sellable?: int}
     */
    private function mapCsvHeaders(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $i => $label) {
            $key = strtolower(trim((string) $label));
            $key = str_replace(['_', '-'], ' ', $key);
            if (in_array($key, ['location name', 'location', 'name'], true)) {
                $map['name'] = $i;
            } elseif ($key === 'type') {
                $map['type'] = $i;
            } elseif ($key === 'pickable') {
                $map['pickable'] = $i;
            } elseif ($key === 'sellable') {
                $map['sellable'] = $i;
            }
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $raw
     */
    private function csvRowEmpty(array $raw): bool
    {
        foreach ($raw as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $value
     */
    private function parseCsvBool($value, bool $default): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }
        $n = strtolower(trim((string) $value));
        if (in_array($n, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($n, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
