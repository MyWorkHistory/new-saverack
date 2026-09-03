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
use Illuminate\Validation\Rule;
use Throwable;

class SupplyOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupplyOrder::class);

        $q = trim((string) $request->query('q', ''));
        $perPage = min(500, max(1, (int) $request->query('per_page', 100)));

        // Team-wide history: only submitted orders (exclude the shared draft).
        $query = SupplyOrderLine::query()
            ->with(['order.user:id,name'])
            ->whereHas('order', function ($q) {
                $q->whereNotNull('submitted_at');
            })
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
                'submitted_by_user_id' => optional($line->order)->user_id,
                'submitted_by_name' => optional(optional($line->order)->user)->name,
                'order_note' => optional($line->order)->note,
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
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
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
            $note = isset($validated['note']) ? trim((string) $validated['note']) : '';
            $order = SupplyOrder::query()->create([
                'user_id' => $user->id,
                'submitted_at' => now(),
                'note' => $note !== '' ? $note : null,
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
            'note' => $order->note,
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

    public function updateLine(Request $request, SupplyOrderLine $supplyOrderLine): JsonResponse
    {
        $this->authorize('update', SupplyOrder::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(Supply::TYPES)],
            'quantity' => ['required', 'integer', 'min:1', 'max:99999999'],
            'link' => ['nullable', 'string', 'max:2048'],
        ]);

        $supplyOrderLine->name = trim((string) $validated['name']);
        $supplyOrderLine->type = (string) $validated['type'];
        $supplyOrderLine->quantity = (int) $validated['quantity'];
        $link = trim((string) ($validated['link'] ?? ''));
        $supplyOrderLine->link = $link !== '' ? $link : null;
        $supplyOrderLine->save();
        $supplyOrderLine->load(['order.user:id,name']);

        return response()->json($this->serializeOrderLine($supplyOrderLine));
    }

    public function destroyLine(Request $request, SupplyOrderLine $supplyOrderLine): JsonResponse
    {
        $this->authorize('update', SupplyOrder::class);

        $supplyOrderLine->delete();

        return response()->json(['message' => 'History row deleted.']);
    }

    // -------------------------------------------------------
    //  Shared Draft (team-wide pending cart)
    // -------------------------------------------------------

    /**
     * Get (or create) the single shared draft order that the whole team sees.
     */
    private function sharedDraft(?Request $request = null): SupplyOrder
    {
        $draft = SupplyOrder::query()
            ->whereNull('submitted_at')
            ->orderBy('id')
            ->first();

        if ($draft === null) {
            $userId = $request !== null && $request->user() !== null
                ? (int) $request->user()->id
                : 1;

            $draft = SupplyOrder::query()->create([
                'user_id' => $userId,
                'submitted_at' => null,
            ]);
        }

        return $draft;
    }

    /**
     * GET /api/admin/supply-orders/draft
     */
    public function showDraft(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupplyOrder::class);

        $draft = $this->sharedDraft($request);
        $draft->load('lines');

        return response()->json([
            'id' => $draft->id,
            'note' => $draft->note,
            'lines' => $draft->lines->map(function (SupplyOrderLine $line) {
                return $this->serializeDraftLine($line);
            })->values()->all(),
        ]);
    }

    /**
     * POST /api/admin/supply-orders/draft/lines
     */
    public function addDraftLine(Request $request): JsonResponse
    {
        $this->authorize('editDraft', SupplyOrder::class);

        $validated = $request->validate([
            'supply_id' => ['required', 'integer', 'exists:supplies,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99999999'],
        ]);

        $draft = $this->sharedDraft($request);
        $supply = Supply::query()->find((int) $validated['supply_id']);
        if ($supply === null) {
            return response()->json(['message' => 'Supply not found.'], 422);
        }

        $qty = (int) ($validated['quantity'] ?? 1);

        // If the supply is already in the draft, bump its quantity instead of duplicating.
        $existing = SupplyOrderLine::query()
            ->where('supply_order_id', $draft->id)
            ->where('supply_id', $supply->id)
            ->first();

        if ($existing !== null) {
            $existing->quantity = min(99999999, (int) $existing->quantity + $qty);
            $existing->save();
        } else {
            SupplyOrderLine::query()->create([
                'supply_order_id' => $draft->id,
                'supply_id' => $supply->id,
                'name' => $supply->name,
                'type' => $supply->type,
                'link' => $supply->link,
                'quantity' => $qty,
            ]);
        }

        // Return the refreshed draft.
        $draft->load('lines');

        return response()->json([
            'id' => $draft->id,
            'note' => $draft->note,
            'lines' => $draft->lines->map(function (SupplyOrderLine $line) {
                return $this->serializeDraftLine($line);
            })->values()->all(),
        ]);
    }

    /**
     * PATCH /api/admin/supply-orders/draft/lines/{supplyOrderLine}
     */
    public function updateDraftLine(Request $request, SupplyOrderLine $supplyOrderLine): JsonResponse
    {
        $this->authorize('editDraft', SupplyOrder::class);

        $draft = $this->sharedDraft($request);
        if ((int) $supplyOrderLine->supply_order_id !== (int) $draft->id) {
            abort(404);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        $supplyOrderLine->quantity = (int) $validated['quantity'];
        $supplyOrderLine->save();

        return response()->json($this->serializeDraftLine($supplyOrderLine));
    }

    /**
     * DELETE /api/admin/supply-orders/draft/lines/{supplyOrderLine}
     */
    public function removeDraftLine(Request $request, SupplyOrderLine $supplyOrderLine): JsonResponse
    {
        $this->authorize('editDraft', SupplyOrder::class);

        $draft = $this->sharedDraft($request);
        if ((int) $supplyOrderLine->supply_order_id !== (int) $draft->id) {
            abort(404);
        }

        $supplyOrderLine->delete();

        return response()->json(['message' => 'Removed from draft.']);
    }

    /**
     * PATCH /api/admin/supply-orders/draft/note
     */
    public function updateDraftNote(Request $request): JsonResponse
    {
        $this->authorize('editDraft', SupplyOrder::class);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $draft = $this->sharedDraft($request);
        $note = trim((string) ($validated['note'] ?? ''));
        $draft->note = $note !== '' ? $note : null;
        $draft->save();

        return response()->json(['note' => $draft->note]);
    }

    /**
     * POST /api/admin/supply-orders/draft/submit
     *
     * Promotes the shared draft to a submitted order and sends the Slack notification.
     */
    public function submitDraft(Request $request, SuppliesOrderedSlackService $slack): JsonResponse
    {
        $this->authorize('submitDraft', SupplyOrder::class);

        $draft = $this->sharedDraft($request);
        $draft->load('lines');

        if ($draft->lines->isEmpty()) {
            return response()->json(['message' => 'Add at least one supply to the order.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $draft->user_id = $user->id;
        $draft->submitted_at = now();
        $draft->save();

        $slackWarning = null;
        try {
            $slack->send($draft);
        } catch (Throwable $e) {
            report($e);
            $slackWarning = 'Order saved, but Slack notification failed.';
        }

        return response()->json([
            'id' => $draft->id,
            'submitted_at' => optional($draft->submitted_at)->toIso8601String(),
            'note' => $draft->note,
            'lines' => $draft->lines->map(fn (SupplyOrderLine $line) => [
                'id' => $line->id,
                'name' => $line->name,
                'type' => $line->type,
                'type_label' => Supply::typeLabel($line->type),
                'quantity' => (int) $line->quantity,
            ])->values()->all(),
            'slack_warning' => $slackWarning,
        ], 201);
    }

    // -------------------------------------------------------
    //  Serialization helpers
    // -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function serializeDraftLine(SupplyOrderLine $line): array
    {
        return [
            'id' => $line->id,
            'supply_id' => $line->supply_id,
            'name' => $line->name,
            'type' => $line->type,
            'type_label' => Supply::typeLabel($line->type),
            'link' => $line->link,
            'quantity' => (int) $line->quantity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrderLine(SupplyOrderLine $line): array
    {
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
            'submitted_by_user_id' => optional($line->order)->user_id,
            'submitted_by_name' => optional(optional($line->order)->user)->name,
            'order_note' => optional($line->order)->note,
            'created_at' => optional($line->created_at)->toIso8601String(),
        ];
    }
}
