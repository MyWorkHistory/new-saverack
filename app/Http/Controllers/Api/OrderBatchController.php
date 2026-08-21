<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderBatch;
use App\Models\User;
use App\Support\OrderBatchNumberParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderBatchController extends Controller
{
    private function assertStaff(Request $request): void
    {
        $user = $request->user();
        if ($user === null || (int) ($user->client_account_id ?? 0) > 0) {
            abort(403, 'Order batch access requires staff.');
        }
    }

    private function assertCanView(Request $request): void
    {
        $this->assertStaff($request);
        $user = $request->user();
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return;
        }
        if ($user->hasPermission('orders_batches.view')) {
            return;
        }
        abort(403);
    }

    private function assertCanCreate(Request $request): void
    {
        $this->assertStaff($request);
        $user = $request->user();
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return;
        }
        if ($user->hasPermission('orders_batches.create') || $user->hasPermission('orders_batches.update')) {
            return;
        }
        abort(403);
    }

    private function assertCanUpdate(Request $request): void
    {
        $this->assertStaff($request);
        $user = $request->user();
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return;
        }
        if ($user->hasPermission('orders_batches.update')) {
            return;
        }
        abort(403);
    }

    private function assertCanDelete(Request $request): void
    {
        $this->assertStaff($request);
        $user = $request->user();
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return;
        }
        if ($user->hasPermission('orders_batches.delete') || $user->hasPermission('orders_batches.update')) {
            return;
        }
        abort(403);
    }

    public function meta(Request $request): JsonResponse
    {
        $this->assertCanView($request);

        $users = User::query()
            ->whereNull('client_account_id')
            ->where('status', 'active')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        return response()->json([
            'statuses' => OrderBatch::STATUSES,
            'users' => $users->map(function (User $u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                ];
            })->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertCanView($request);

        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $userId = (int) $request->query('user_id', 0);

        $query = OrderBatch::query()
            ->with(['createdBy:id,name,email', 'completedBy:id,name,email']);

        if ($q !== '') {
            $query->where('batch_number', 'like', '%'.$q.'%');
        }
        if ($status !== '' && $status !== 'all' && in_array($status, OrderBatch::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($userId > 0) {
            $query->where(function ($builder) use ($userId) {
                $builder->where('created_by_user_id', $userId)
                    ->orWhere('completed_by_user_id', $userId);
            });
        }

        $query->orderByDesc('id');
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(function (OrderBatch $row) {
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
        $this->assertCanCreate($request);

        $validated = $request->validate([
            'lines' => ['nullable', 'string'],
            'batch_numbers' => ['nullable', 'array'],
            'batch_numbers.*' => ['string', 'max:512'],
        ]);

        $numbers = [];
        if (! empty($validated['batch_numbers']) && is_array($validated['batch_numbers'])) {
            $numbers = OrderBatchNumberParser::normalizeList($validated['batch_numbers']);
        } else {
            $parsed = OrderBatchNumberParser::parseLines((string) ($validated['lines'] ?? ''));
            if ($parsed['invalid'] !== []) {
                throw ValidationException::withMessages([
                    'lines' => ['Each non-empty line must be a ShipHero batch link (or batch ID).'],
                ]);
            }
            $numbers = $parsed['numbers'];
        }

        if ($numbers === []) {
            throw ValidationException::withMessages([
                'lines' => ['Enter at least one ShipHero batch link.'],
            ]);
        }

        $actorId = (int) $request->user()->id;
        $existing = OrderBatch::query()
            ->whereIn('batch_number', $numbers)
            ->pluck('batch_number')
            ->all();
        $existingMap = array_fill_keys($existing, true);

        $created = 0;
        $skipped = 0;
        DB::transaction(function () use ($numbers, $existingMap, $actorId, &$created, &$skipped) {
            foreach ($numbers as $number) {
                if (isset($existingMap[$number])) {
                    $skipped++;
                    continue;
                }
                OrderBatch::query()->create([
                    'batch_number' => $number,
                    'status' => OrderBatch::STATUS_PENDING,
                    'created_by_user_id' => $actorId,
                    'completed_by_user_id' => null,
                    'completed_at' => null,
                ]);
                $created++;
            }
        });

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
            'message' => $created === 1
                ? '1 batch created.'
                : $created.' batches created.',
        ], 201);
    }

    public function update(Request $request, OrderBatch $orderBatch): JsonResponse
    {
        $this->assertCanUpdate($request);

        $validated = $request->validate([
            'batch_number' => [
                'sometimes',
                'string',
                'max:512',
            ],
            'status' => ['sometimes', 'string', Rule::in(OrderBatch::STATUSES)],
        ]);

        if (array_key_exists('batch_number', $validated)) {
            $parsed = OrderBatchNumberParser::parseOne(trim((string) $validated['batch_number']));
            if ($parsed === null) {
                throw ValidationException::withMessages([
                    'batch_number' => ['Enter a ShipHero batch link or numeric batch ID.'],
                ]);
            }
            $clash = OrderBatch::query()
                ->where('batch_number', $parsed)
                ->where('id', '!=', $orderBatch->id)
                ->exists();
            if ($clash) {
                throw ValidationException::withMessages([
                    'batch_number' => ['That batch ID is already in use.'],
                ]);
            }
            $orderBatch->batch_number = $parsed;
        }

        if (array_key_exists('status', $validated)) {
            $this->applyStatus($orderBatch, (string) $validated['status'], $request->user());
        }

        $orderBatch->save();
        $orderBatch->load(['createdBy:id,name,email', 'completedBy:id,name,email']);

        return response()->json(['batch' => $this->serialize($orderBatch)]);
    }

    public function complete(Request $request): JsonResponse
    {
        $this->assertCanUpdate($request);

        $validated = $request->validate([
            'lines' => ['nullable', 'string'],
            'batch_numbers' => ['nullable', 'array'],
            'batch_numbers.*' => ['string', 'max:512'],
        ]);

        if (! empty($validated['batch_numbers']) && is_array($validated['batch_numbers'])) {
            $numbers = OrderBatchNumberParser::normalizeList($validated['batch_numbers']);
        } else {
            $parsed = OrderBatchNumberParser::parseLines((string) ($validated['lines'] ?? ''));
            if ($parsed['invalid'] !== []) {
                throw ValidationException::withMessages([
                    'lines' => ['Each non-empty line must be a ShipHero batch link (or batch ID).'],
                ]);
            }
            $numbers = $parsed['numbers'];
        }

        if ($numbers === []) {
            throw ValidationException::withMessages([
                'lines' => ['Enter at least one ShipHero batch link.'],
            ]);
        }

        $actor = $request->user();
        $rows = OrderBatch::query()->whereIn('batch_number', $numbers)->get()->keyBy('batch_number');
        $updated = 0;
        $missing = [];
        $already = 0;

        DB::transaction(function () use ($numbers, $rows, $actor, &$updated, &$missing, &$already) {
            foreach ($numbers as $number) {
                /** @var OrderBatch|null $row */
                $row = $rows->get($number);
                if ($row === null) {
                    $missing[] = $number;
                    continue;
                }
                if ($row->isCompleted()) {
                    $already++;
                    // Still refresh completer to the user who ran Update Batch.
                    $this->applyStatus($row, OrderBatch::STATUS_COMPLETED, $actor);
                    $row->save();
                    continue;
                }
                $this->applyStatus($row, OrderBatch::STATUS_COMPLETED, $actor);
                $row->save();
                $updated++;
            }
        });

        return response()->json([
            'updated' => $updated,
            'already_completed' => $already,
            'missing' => $missing,
            'missing_count' => count($missing),
        ]);
    }

    public function destroy(Request $request, OrderBatch $orderBatch): JsonResponse
    {
        $this->assertCanDelete($request);
        $orderBatch->delete();

        return response()->json(['message' => 'Batch deleted.']);
    }

    private function applyStatus(OrderBatch $batch, string $status, User $actor): void
    {
        $batch->status = $status;
        if ($status === OrderBatch::STATUS_COMPLETED) {
            $batch->completed_by_user_id = (int) $actor->id;
            $batch->completed_at = now();
        } else {
            $batch->completed_by_user_id = null;
            $batch->completed_at = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OrderBatch $batch): array
    {
        $createdBy = $batch->createdBy;
        $completedBy = $batch->completedBy;
        $displayUser = $batch->isCompleted() ? $completedBy : $createdBy;

        return [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'batch_url' => OrderBatchNumberParser::shipHeroUrl((string) $batch->batch_number),
            'status' => $batch->status,
            'created_by_user_id' => $batch->created_by_user_id,
            'completed_by_user_id' => $batch->completed_by_user_id,
            'completed_at' => optional($batch->completed_at)->toIso8601String(),
            'created_by_name' => $createdBy ? $createdBy->name : null,
            'completed_by_name' => $completedBy ? $completedBy->name : null,
            'user_name' => $displayUser ? $displayUser->name : null,
            'user_id' => $displayUser ? $displayUser->id : null,
            'created_at' => optional($batch->created_at)->toIso8601String(),
            'updated_at' => optional($batch->updated_at)->toIso8601String(),
        ];
    }
}
