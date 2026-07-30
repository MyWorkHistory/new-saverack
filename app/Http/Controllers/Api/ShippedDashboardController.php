<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShippedDaySnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippedDashboardController extends Controller
{
    /** @var ShippedDaySnapshotService */
    private $snapshots;

    public function __construct(ShippedDaySnapshotService $snapshots)
    {
        $this->snapshots = $snapshots;
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeShippedDashboard($request);

        return response()->json($this->snapshots->getDashboardPayload());
    }

    public function refresh(Request $request): JsonResponse
    {
        $this->authorizeShippedDashboard($request);

        $validated = $request->validate([
            'sync' => ['sometimes', 'boolean'],
            'from_index' => ['sometimes', 'boolean'],
        ]);

        $fromIndex = array_key_exists('from_index', $validated)
            ? (bool) $validated['from_index']
            : true;

        $payload = $this->snapshots->refreshToday($fromIndex);

        return response()->json(array_merge($payload, [
            'refresh_synced' => true,
            'refresh_index_only' => $fromIndex,
        ]));
    }

    private function authorizeShippedDashboard(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        if (! $user->can('orders.view')) {
            abort(403);
        }
    }
}
