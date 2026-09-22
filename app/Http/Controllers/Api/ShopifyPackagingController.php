<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopifyPackagingInventoryLog;
use App\Models\ShopifyPackagingItem;
use App\Models\ShopifyPackagingLocationItem;
use App\Models\ShopifyWarehouseLocation;
use App\Services\ShopifyPackagingInventoryService;
use App\Services\ShopifyWarehouseInventoryLogService;
use App\Support\Barcode\QrCodeSvg;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShopifyPackagingController extends Controller
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
            'categories' => $this->optionList(ShopifyPackagingItem::CATEGORIES),
            'types' => [
                ShopifyPackagingItem::CATEGORY_PACKAGING => $this->optionList(
                    ShopifyPackagingItem::typesFor(ShopifyPackagingItem::CATEGORY_PACKAGING)
                ),
                ShopifyPackagingItem::CATEGORY_MATERIALS => $this->optionList(
                    ShopifyPackagingItem::typesFor(ShopifyPackagingItem::CATEGORY_MATERIALS)
                ),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $perPage = in_array((int) $request->query('per_page', 25), [25, 50, 100, 250, 500, 1000], true)
            ? (int) $request->query('per_page', 25)
            : 25;
        $query = ShopifyPackagingItem::query();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('name', 'like', '%'.$q.'%');
        }

        $category = trim((string) $request->query('category', ''));
        if ($category !== '' && array_key_exists($category, ShopifyPackagingItem::CATEGORIES)) {
            $query->where('category', $category);
        }

        $type = trim((string) $request->query('type', ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        $page = $query->orderBy('name')->orderBy('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(function (ShopifyPackagingItem $row) {
                return $this->serialize($row);
            })->values(),
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
        $item = new ShopifyPackagingItem();
        $this->fillFromRequest($request, $item);
        $item->save();

        return response()->json([
            'message' => 'Packaging created.',
            'item' => $this->serialize($item),
        ], 201);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer'],
            'category' => ['required', Rule::in(array_keys(ShopifyPackagingItem::CATEGORIES))],
            'type' => ['required', 'string', 'max:64'],
        ]);
        $category = (string) $validated['category'];
        $type = (string) $validated['type'];
        if (! array_key_exists($type, ShopifyPackagingItem::typesFor($category))) {
            throw ValidationException::withMessages([
                'type' => ['This type does not match the selected category.'],
            ]);
        }
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        $updated = ShopifyPackagingItem::query()->whereIn('id', $ids)->update([
            'category' => $category,
            'type' => $type,
        ]);

        return response()->json([
            'message' => 'Updated '.$updated.' packaging item'.($updated === 1 ? '' : 's').'.',
            'updated' => $updated,
        ]);
    }

    public function show(Request $request, ShopifyPackagingItem $packaging): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json([
            'item' => $this->serialize($packaging),
        ]);
    }

    public function update(Request $request, ShopifyPackagingItem $packaging): JsonResponse
    {
        $this->assertAdmin($request);

        if ($request->boolean('remove_image')) {
            $this->deleteImage($packaging);
            $packaging->image_path = null;
        }

        $this->fillFromRequest($request, $packaging);
        $packaging->save();

        try {
            app(\App\Services\ShopifyShippingPackageSyncService::class)
                ->syncPackagingItemToShopify($packaging->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Packaging updated.',
            'item' => $this->serialize($packaging->fresh()),
        ]);
    }

    public function destroy(Request $request, ShopifyPackagingItem $packaging): JsonResponse
    {
        $this->assertAdmin($request);
        $this->deleteImage($packaging);
        $packaging->delete();

        return response()->json(['message' => 'Packaging deleted.']);
    }

    public function uploadImage(Request $request, ShopifyPackagingItem $packaging): JsonResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'image' => ['required', 'file', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['image'];
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            throw ValidationException::withMessages([
                'image' => ['Upload a JPG, PNG, GIF, WEBP, or AVIF image.'],
            ]);
        }

        $path = $file->storeAs('shopify/packaging', 'item-'.$packaging->id.'.'.$ext, 'public');
        $old = trim((string) ($packaging->image_path ?? ''));
        if ($old !== '' && $old !== $path && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);
        }

        $packaging->image_path = $path;
        $packaging->save();

        return response()->json([
            'message' => 'Icon updated.',
            'item' => $this->serialize($packaging->fresh()),
        ]);
    }

    public function assignLocation(
        Request $request,
        ShopifyPackagingItem $packaging,
        ShopifyPackagingInventoryService $inventory
    ): JsonResponse {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'location_id' => ['required', 'integer'],
            'available' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', Rule::in(ShopifyWarehouseLocation::addItemReasons())],
        ]);
        $inventory->assign(
            $packaging,
            (int) $validated['location_id'],
            (int) $validated['available'],
            (string) $validated['reason'],
            $request->user()
        );

        return response()->json([
            'message' => 'Inventory added.',
            'item' => $this->serialize($packaging->fresh()),
        ]);
    }

    public function updateLocationQty(
        Request $request,
        ShopifyPackagingItem $packaging,
        ShopifyPackagingLocationItem $locationItem,
        ShopifyPackagingInventoryService $inventory
    ): JsonResponse {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'available' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', Rule::in(ShopifyWarehouseLocation::addItemReasons())],
        ]);
        $inventory->updateQty(
            $packaging,
            $locationItem,
            (int) $validated['available'],
            (string) $validated['reason'],
            $request->user()
        );

        return response()->json([
            'message' => 'Quantity updated.',
            'item' => $this->serialize($packaging->fresh()),
        ]);
    }

    public function transferLocation(
        Request $request,
        ShopifyPackagingItem $packaging,
        ShopifyPackagingLocationItem $locationItem,
        ShopifyPackagingInventoryService $inventory
    ): JsonResponse {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'to_location_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', Rule::in(ShopifyWarehouseLocation::addItemReasons())],
        ]);
        $inventory->transfer(
            $packaging,
            $locationItem,
            (int) $validated['to_location_id'],
            (int) $validated['quantity'],
            (string) $validated['reason'],
            $request->user()
        );

        return response()->json([
            'message' => 'Inventory transferred.',
            'item' => $this->serialize($packaging->fresh()),
        ]);
    }

    public function logs(
        Request $request,
        ShopifyPackagingItem $packaging,
        ShopifyWarehouseInventoryLogService $logService
    ): JsonResponse {
        $this->assertAdmin($request);
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $q = trim((string) $request->query('q', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $changedBy = trim((string) $request->query('changed_by', ''));
        $type = trim((string) $request->query('type', ''));
        $sort = strtolower(trim((string) $request->query('sort', 'newest')));
        $dir = $sort === 'oldest' ? 'asc' : 'desc';

        $query = ShopifyPackagingInventoryLog::query()
            ->with(['user:id,name'])
            ->where('shopify_packaging_item_id', (int) $packaging->id);

        if ($q !== '') {
            $query->where('location_name', 'like', '%'.$q.'%');
        }
        if ($dateFrom !== '') {
            try {
                $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            } catch (\Throwable $e) {
            }
        }
        if ($dateTo !== '') {
            try {
                $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
            } catch (\Throwable $e) {
            }
        }
        if ($changedBy !== '') {
            $query->whereHas('user', function ($builder) use ($changedBy) {
                $builder->where('name', 'like', '%'.$changedBy.'%');
            });
        }
        if ($type !== '' && strtolower($type) !== 'all') {
            $query->where('type_label', $type);
        }

        $page = $query->orderBy('created_at', $dir)->orderBy('id', $dir)->paginate($perPage);

        return response()->json([
            'variant' => [
                'id' => (int) $packaging->id,
                'sku' => $packaging->sku,
                'title' => $packaging->name,
                'product_title' => $packaging->name,
                'image_url' => $packaging->imageUrl(),
            ],
            'types' => $logService->filterTypes(),
            'data' => collect($page->items())->map(function (ShopifyPackagingInventoryLog $row) {
                return $this->serializeLog($row);
            })->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function barcodeLabelPdf(Request $request, ShopifyPackagingItem $packaging): Response
    {
        $this->assertAdmin($request);
        $sku = trim((string) ($packaging->sku ?? ''));
        $name = trim((string) $packaging->name);
        if ($sku === '') {
            abort(422, 'Add a SKU before printing a label.');
        }
        if (mb_strlen($name) > 52) {
            $name = rtrim(mb_substr($name, 0, 49)).'…';
        }
        $pageW = (int) round(4 * 72);
        $pageH = (int) round(1.5 * 72);
        $qrSize = 86;
        $pdf = Pdf::loadView('pdf.shopify.variant-barcode-labels', [
            'labels' => [[
                'qrDataUri' => QrCodeSvg::dataUri($sku, 200),
                'displayCode' => $sku,
                'productName' => $name,
            ]],
            'pageW' => $pageW,
            'pageH' => $pageH,
            'qrSize' => $qrSize,
            'qrLeft' => 8,
            'qrTop' => round(($pageH - $qrSize) / 2, 1),
            'textLeft' => 104,
            'textW' => $pageW - 112,
            'codeTop' => round($pageH * 0.38, 1),
            'nameTop' => round($pageH * 0.58, 1),
            'codeFont' => 15,
            'nameFont' => 9,
        ])->setPaper([0, 0, $pageW, $pageH]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="packaging-label-'.$packaging->id.'.pdf"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPayload(ShopifyPackagingItem $item): array
    {
        $summary = app(ShopifyPackagingInventoryService::class)->locationSummary($item);

        return [
            'location_groups' => $summary['location_groups'],
            'total_on_hand' => $summary['total_on_hand'],
            'add_item_reasons' => ShopifyWarehouseLocation::addItemReasons(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLog(ShopifyPackagingInventoryLog $row): array
    {
        $user = $row->user;
        $name = $user !== null ? trim((string) $user->name) : '';
        $isSystem = $user === null || $name === '';

        return [
            'id' => (int) $row->id,
            'created_at' => optional($row->created_at)->toIso8601String(),
            'location_id' => $row->location_id !== null ? (int) $row->location_id : null,
            'location_name' => $row->location_name,
            'changed_by' => [
                'id' => $isSystem ? null : (int) $user->id,
                'name' => $isSystem ? 'System' : $name,
                'initials' => $isSystem ? null : $this->initials($name),
                'is_system' => $isSystem,
            ],
            'old_on_hand' => (int) $row->old_on_hand,
            'new_on_hand' => (int) $row->new_on_hand,
            'quantity_delta' => (int) $row->quantity_delta,
            'note' => $row->note,
            'type' => $row->type,
            'type_label' => $row->type_label,
            'direction_label' => $row->directionLabel(),
            'transfer_group' => $row->transfer_group,
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= strtoupper(substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : '??';
    }

    /**
     * @param mixed $value
     */
    private function nullableUrl($value): ?string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return null;
        }
        if (! preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    private function fillFromRequest(Request $request, ShopifyPackagingItem $item): void
    {
        $uniqueSku = Rule::unique('shopify_packaging_items', 'sku');
        if ($item->exists) {
            $uniqueSku = $uniqueSku->ignore($item->id);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64', $uniqueSku],
            'category' => ['required', Rule::in(array_keys(ShopifyPackagingItem::CATEGORIES))],
            'type' => ['required', 'string', 'max:64'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'on_hand' => ['nullable', 'integer', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'link_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $category = (string) $validated['category'];
        $type = (string) $validated['type'];
        if (! array_key_exists($type, ShopifyPackagingItem::typesFor($category))) {
            throw ValidationException::withMessages([
                'type' => ['This type does not match the selected category.'],
            ]);
        }

        $sku = trim((string) ($validated['sku'] ?? ''));

        $item->name = trim((string) $validated['name']);
        $item->sku = $sku === '' ? null : $sku;
        $item->category = $category;
        $item->type = $type;
        $item->cost_cents = $this->centsFromDollars($validated['cost'] ?? 0);
        $item->price_cents = $this->centsFromDollars($validated['price'] ?? 0);
        $item->on_hand = (int) ($validated['on_hand'] ?? 0);
        $item->length = $this->nullableNumber($validated['length'] ?? null);
        $item->width = $this->nullableNumber($validated['width'] ?? null);
        $item->height = $this->nullableNumber($validated['height'] ?? null);
        $item->weight = $this->nullableNumber($validated['weight'] ?? null);
        $item->link_url = $this->nullableUrl($validated['link_url'] ?? null);
    }

    /**
     * @param array<string, string> $map
     * @return list<array{value: string, label: string}>
     */
    private function optionList(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ShopifyPackagingItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'category' => $item->category,
            'category_label' => $item->categoryLabel(),
            'type' => $item->type,
            'type_label' => $item->typeLabel(),
            'cost_cents' => (int) $item->cost_cents,
            'price_cents' => (int) $item->price_cents,
            'cost' => round(((int) $item->cost_cents) / 100, 2),
            'price' => round(((int) $item->price_cents) / 100, 2),
            'on_hand' => (int) $item->on_hand,
            'length' => $item->length,
            'width' => $item->width,
            'height' => $item->height,
            'weight' => $item->weight,
            'cubic_ft' => $item->cubicFeet(),
            'image_url' => $item->imageUrl(),
            'link_url' => $item->link_url,
        ] + $this->locationPayload($item);
    }

    /**
     * @param mixed $value
     */
    private function centsFromDollars($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * @param mixed $value
     */
    private function nullableNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 3);
    }

    private function deleteImage(ShopifyPackagingItem $item): void
    {
        $path = trim((string) ($item->image_path ?? ''));
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
