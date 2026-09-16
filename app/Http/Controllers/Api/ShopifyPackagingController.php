<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopifyPackagingItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'image' => ['required', 'file', 'image', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['image'];
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = 'jpg';
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
        ];
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
