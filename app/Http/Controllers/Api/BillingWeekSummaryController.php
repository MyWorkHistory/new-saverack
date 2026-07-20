<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\BillingWeekSummaryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingWeekSummaryController extends Controller
{
    /** @var BillingWeekSummaryService */
    private $summaries;

    public function __construct(BillingWeekSummaryService $summaries)
    {
        $this->summaries = $summaries;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $weekStart = null;
        if ($request->filled('week_start')) {
            try {
                $weekStart = Carbon::parse((string) $request->input('week_start'))->startOfDay();
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Invalid week_start date.',
                ], 422);
            }
        }

        return response()->json($this->summaries->dashboardPayload($weekStart));
    }

    public function generate(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $validated = $request->validate([
            'week_start' => ['sometimes', 'nullable', 'date'],
        ]);

        if (! empty($validated['week_start'])) {
            $weekStart = Carbon::parse((string) $validated['week_start'])->startOfDay();
        } else {
            $weekStart = $this->summaries->defaultCompletedWeekStart();
        }

        $summary = $this->summaries->generateWeek($weekStart, $request->user());
        $payload = $this->summaries->dashboardPayload($summary->week_start);

        return response()->json(array_merge($payload, [
            'message' => 'Week summary generated.',
            'generated' => $payload['current'],
        ]));
    }
}
