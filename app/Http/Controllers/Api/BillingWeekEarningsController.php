<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingWeekEarning;
use App\Models\Invoice;
use App\Services\BillingWeekEarningsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingWeekEarningsController extends Controller
{
    /** @var BillingWeekEarningsService */
    private $earnings;

    public function __construct(BillingWeekEarningsService $earnings)
    {
        $this->earnings = $earnings;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $from = ! empty($validated['from'])
            ? Carbon::parse((string) $validated['from'])->startOfDay()
            : null;
        $to = ! empty($validated['to'])
            ? Carbon::parse((string) $validated['to'])->startOfDay()
            : null;

        return response()->json($this->earnings->listWeeks($from, $to));
    }

    public function show(BillingWeekEarning $weekEarning): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        return response()->json($this->earnings->toApiArray($weekEarning));
    }

    public function unmatched(BillingWeekEarning $weekEarning): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        return response()->json([
            'earning' => $this->earnings->toApiArray($weekEarning),
            'items' => $this->earnings->unmatchedPayload($weekEarning),
        ]);
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
            $weekStart = $this->earnings->defaultCompletedWeekStart();
        }

        $earning = $this->earnings->queueGenerate($weekStart, $request->user());

        return response()->json([
            'message' => 'Earnings generation queued.',
            'id' => $earning->id,
            'status' => $earning->status,
            'earning' => $this->earnings->toApiArray($earning),
            'default_week_start' => $this->earnings->defaultCompletedWeekStart()->toDateString(),
        ], 202);
    }
}
