<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supply;
use App\Models\SupplyOrder;
use App\Models\SupplyOrderLine;
use App\Models\User;
use App\Services\SuppliesOrderedSlackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SupplyOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupplyOrder::class);

        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $query = SupplyOrderLine::query()
            ->with('order')
            ->whereHas('order')
            ->orderByDesc('id');

        if ($q !== '') {
            $like = '%'.$q.'%';
            $matchingTypes = [];
            foreach (Supply::TYPE_LABELS as $key => $label) {
                if (stripos($label, $q) !== false || stripos($key, $q) !== false) {
                    $matchingTypes[] = $key;
                }
            }
            $query->where(function ($builder) use ($like, $matchingTypes) {
                $builder->where('name', 'like', $like)
                    ->orWhere('type', 'like', $like);
                if ($matchingTypes !== []) {
                    $builder->orWhereIn('type', $matchingTypes);
                }
            });
        }

        $paginator = $query->paginate($perPage);

        $rows = collect($paginator->items())->map(function (SupplyOrderLine $line) {
            return [
                'id' => $line->id,
                'supply_order_id' => $line->supply_order_id,
                'supply_id' => $line->supply_id,
                'name' => $line->name,
                'type' => $line->type,
                'type_label' => Supply::typeLabel($line->type),
                'display_name' => trim(Supply::typeLabel($line->type).' '.$line->name),
                'link' => $line->link,
                'quantity' => (int) $line->quantity,
                'submitted_at' => optional(optional($line->order)->submitted_at)->toIso8601String(),
                'created_at' => optional($line->created_at)->toIso8601String(),
            ];
        })->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'types' => Supply::TYPES,
            'type_labels' => Supply::TYPE_LABELS,
        ]);
    }

    public function store(Request $request, SuppliesOrderedSlackService $slack): JsonResponse
    {
        $this->authorize('create', SupplyOrder::class);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.supply_id' => ['required', 'integer', 'exists:supplies,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $supplyIds = collect($validated['lines'])->pluck('supply_id')->map(fn ($id) => (int) $id)->unique()->all();
        $supplies = Supply::query()->whereIn('id', $supplyIds)->get()->keyBy('id');
        if ($supplies->count() !== count($supplyIds)) {
            return response()->json([
                'message' => 'One or more supplies were not found.',
            ], 422);
        }

        $order = DB::transaction(function () use ($validated, $user, $supplies) {
            $order = SupplyOrder::query()->create([
                'user_id' => $user->id,
                'submitted_at' => now(),
            ]);

            foreach ($validated['lines'] as $row) {
                $supply = $supplies->get((int) $row['supply_id']);
                SupplyOrderLine::query()->create([
                    'supply_order_id' => $order->id,
                    'supply_id' => $supply->id,
                    'name' => $supply->name,
                    'type' => $supply->type,
                    'link' => $supply->link,
                    'quantity' => (int) $row['quantity'],
                ]);
            }

            return $order->fresh(['lines']);
        });

        $slackWarning = null;
        try {
            $slack->send($order);
        } catch (Throwable $e) {
            report($e);
            $slackWarning = 'Order saved, but Slack notification failed.';
        }

        return response()->json([
            'id' => $order->id,
            'submitted_at' => optional($order->submitted_at)->toIso8601String(),
            'lines' => $order->lines->map(fn (SupplyOrderLine $line) => [
                'id' => $line->id,
                'name' => $line->name,
                'type' => $line->type,
                'type_label' => Supply::typeLabel($line->type),
                'quantity' => (int) $line->quantity,
            ])->values()->all(),
            'slack_warning' => $slackWarning,
        ], 201);
    }
}
