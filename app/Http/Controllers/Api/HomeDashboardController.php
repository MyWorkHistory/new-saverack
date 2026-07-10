<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderDashboardSection;
use App\Services\HomeDashboardWidgetsService;
use App\Services\OrderDashboardSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class HomeDashboardController extends Controller
{
    /** @var OrderDashboardSnapshotService */
    private $snapshots;

    /** @var HomeDashboardWidgetsService */
    private $widgets;

    public function __construct(OrderDashboardSnapshotService $snapshots, HomeDashboardWidgetsService $widgets)
    {
        $this->snapshots = $snapshots;
        $this->widgets = $widgets;
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeHomeDashboard($request);

        $this->snapshots->bootstrapIfNeeded();

        return response()->json(array_merge(
            $this->snapshots->getDashboardPayload(),
            $this->widgets->widgetsForUser($request->user())
        ));
    }

    public function revision(Request $request): JsonResponse
    {
        $this->authorizeHomeDashboard($request);

        return response()->json([
            'revision' => $this->snapshots->getDashboardRevision(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $this->authorizeHomeDashboard($request);

        $validated = $request->validate([
            'section' => ['nullable', 'string', Rule::in(array_merge(['all'], OrderDashboardSection::ALL_KEYS))],
            'sync' => ['sometimes', 'boolean'],
        ]);

        $section = strtolower(trim((string) ($validated['section'] ?? 'all')));
        if ($section === '') {
            $section = 'all';
        }

        $sync = (bool) ($validated['sync'] ?? false);

        if ($section === 'all') {
            $this->refreshAllSections($sync);
        } elseif ($sync && $section !== OrderDashboardSection::KEY_ASN_PENDING) {
            $this->snapshots->refreshSection($section);
        } elseif ($sync || $section === OrderDashboardSection::KEY_ASN_PENDING) {
            $this->snapshots->refreshSection($section);
        } else {
            $this->snapshots->dispatchSectionRefresh($section);
        }

        return response()->json(array_merge(
            $this->snapshots->getDashboardPayload(),
            $this->widgets->widgetsForUser($request->user()),
            [
                'section' => $section,
                'refresh_enqueued' => ! $sync && $section !== OrderDashboardSection::KEY_ASN_PENDING,
                'refresh_synced' => $sync || $section === OrderDashboardSection::KEY_ASN_PENDING,
                'refresh_index_only' => false,
            ]
        ));
    }

    private function refreshAllSections(bool $sync): void
    {
        if ($sync) {
            $this->snapshots->refreshPrimaryTotals(false);
            $this->snapshots->refreshSection(OrderDashboardSection::KEY_ASN_PENDING);

            return;
        }

        try {
            $this->snapshots->refreshSection(OrderDashboardSection::KEY_ASN_PENDING);
        } catch (Throwable $e) {
            // ASN refresh is fast; ShipHero sections still queue below.
        }

        $this->snapshots->dispatchPrimaryTotalsRefresh();
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
