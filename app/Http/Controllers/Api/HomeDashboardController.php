<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Models\OrderDashboardSection;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HomeDashboardController extends Controller
{
    /** @var OrderDashboardSnapshotService */
    private $snapshots;

    public function __construct(OrderDashboardSnapshotService $snapshots)
    {
        $this->snapshots = $snapshots;
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeHomeDashboard($request);

        return response()->json($this->snapshots->getDashboardPayload());
    }

    public function refresh(Request $request): JsonResponse
    {
        $this->authorizeHomeDashboard($request);

        $validated = $request->validate([
            'section' => ['nullable', 'string', Rule::in(array_merge(['all'], OrderDashboardSection::ALL_KEYS))],
        ]);

        $section = strtolower(trim((string) ($validated['section'] ?? 'all')));
        if ($section === '') {
            $section = 'all';
        }

        if ($section === 'all') {
            foreach (OrderDashboardSection::ALL_KEYS as $key) {
                RefreshOrderDashboardSectionJob::dispatch($key);
            }
        } else {
            RefreshOrderDashboardSectionJob::dispatch($section);
        }

        return response()->json(array_merge(
            $this->snapshots->getDashboardPayload(),
            ['refresh_enqueued' => true, 'section' => $section]
        ));
    }

    private function authorizeHomeDashboard(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        if (! $user->can('view-dashboard')) {
            abort(403);
        }
        if (! $user->can('orders.view')) {
            abort(403);
        }
    }
}
