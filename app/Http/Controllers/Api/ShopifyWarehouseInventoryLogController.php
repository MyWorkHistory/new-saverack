<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyWarehouseInventoryLog;
use App\Services\ShopifyWarehouseInventoryLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ShopifyWarehouseInventoryLogController extends Controller
{
    /** @var ShopifyWarehouseInventoryLogService */
    private $logs;

    public function __construct(ShopifyWarehouseInventoryLogService $logs)
    {
        $this->logs = $logs;
    }

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
            'types' => $this->logs->filterTypes(),
        ]);
    }

    public function index(Request $request, ShopifyProductVariant $shopifyVariant): JsonResponse
    {
        $this->assertAdmin($request);

        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $q = trim((string) $request->query('q', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $changedBy = trim((string) $request->query('changed_by', ''));
        $type = trim((string) $request->query('type', ''));
        $sort = strtolower(trim((string) $request->query('sort', 'newest')));
        $dir = $sort === 'oldest' ? 'asc' : 'desc';

        $query = ShopifyWarehouseInventoryLog::query()
            ->with(['user:id,name'])
            ->where('shopify_variant_id', (int) $shopifyVariant->id);

        if ($q !== '') {
            $query->where('location_name', 'like', '%'.$q.'%');
        }

        if ($dateFrom !== '') {
            try {
                $from = Carbon::parse($dateFrom)->startOfDay();
                $query->where('created_at', '>=', $from);
            } catch (\Throwable $e) {
                // ignore invalid date
            }
        }

        if ($dateTo !== '') {
            try {
                $to = Carbon::parse($dateTo)->endOfDay();
                $query->where('created_at', '<=', $to);
            } catch (\Throwable $e) {
                // ignore invalid date
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

        $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        $page = $query->paginate($perPage);

        $shopifyVariant->loadMissing(['product', 'connection.clientAccount']);

        return response()->json([
            'variant' => [
                'id' => (int) $shopifyVariant->id,
                'sku' => $shopifyVariant->sku,
                'title' => $shopifyVariant->title,
                'product_title' => $shopifyVariant->product->title ?? null,
                'image_url' => $shopifyVariant->displayImageUrl(),
            ],
            'data' => collect($page->items())->map(function (ShopifyWarehouseInventoryLog $row) {
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

    /**
     * @return array<string, mixed>
     */
    private function serializeLog(ShopifyWarehouseInventoryLog $row): array
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
}
