<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShipHeroInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InventoryController extends Controller
{
    public function __construct(
        private readonly ShipHeroInventoryService $inventory,
    ) {}

    public function warehouses(): JsonResponse
    {
        try {
            return response()->json([
                'warehouses' => $this->inventory->listWarehouses(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'string', 'max:255'],
        ]);

        $warehouseId = isset($validated['warehouse_id']) && is_string($validated['warehouse_id']) && $validated['warehouse_id'] !== ''
            ? $validated['warehouse_id']
            : null;

        try {
            $product = $this->inventory->searchProduct($validated['q'], $warehouseId);

            return response()->json([
                'product' => $product,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
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
        ]);

        $reason = isset($validated['reason']) && is_string($validated['reason'])
            ? $validated['reason']
            : 'CRM inventory replace';

        try {
            $updated = $this->inventory->replaceLocationQuantity(
                $validated['sku'],
                $validated['warehouse_id'],
                $validated['location_id'],
                (int) $validated['quantity'],
                $reason,
            );

            return response()->json([
                'warehouse' => $updated,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
