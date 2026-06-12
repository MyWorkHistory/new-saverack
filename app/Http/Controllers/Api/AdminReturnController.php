<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\User;
use App\Services\ShipHeroOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminReturnController extends Controller
{
    /** @var ShipHeroOrderService */
    private $orders;

    public function __construct(ShipHeroOrderService $orders)
    {
        $this->orders = $orders;
    }

    private function assertStaff(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        if ((int) ($user->client_account_id ?? 0) > 0) {
            abort(403, 'Admin return endpoints are for staff only.');
        }
    }

    private function normalizeOrderNumber(?string $raw): string
    {
        $s = strtolower(trim((string) $raw));
        $s = ltrim($s, '#');

        return trim($s);
    }

    private function normalizeRmaNumber(?string $raw): string
    {
        $s = strtolower(trim((string) $raw));
        $s = ltrim($s, '#');
        if (str_starts_with($s, 'rma')) {
            $s = ltrim(substr($s, 3), " \t#");
        }

        return trim($s);
    }

    private function orderNumberMatches(ClientAccountReturn $return, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return $this->normalizeOrderNumber($return->order_number) === $needle;
    }

    private function rmaNumberMatches(ClientAccountReturn $return, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return $this->normalizeRmaNumber($return->rma_number) === $needle;
    }

    private function displayStatusForReturn(?ClientAccountReturn $return): string
    {
        if ($return === null) {
            return 'not_returned';
        }
        $status = (string) $return->status;
        if (in_array($status, [ClientAccountReturn::STATUS_RECEIVED, ClientAccountReturn::STATUS_COMPLETED], true)) {
            return 'returned';
        }

        return 'pending';
    }

    /**
     * @return array<string, string>
     */
    private function returnReasonOptions(): array
    {
        /** @var array<string, string> $reasons */
        $reasons = config('returns.return_reasons', []);

        return $reasons;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(ClientAccountReturn $return, ?string $displayStatus = null): array
    {
        $return->loadMissing('clientAccount');
        $companyName = $return->clientAccount !== null
            ? trim((string) $return->clientAccount->company_name)
            : '';

        return [
            'id' => $return->id,
            'client_account_id' => $return->client_account_id,
            'client_account_company_name' => $companyName,
            'rma_number' => $return->rma_number,
            'status' => $return->status,
            'display_status' => $displayStatus ?? $this->displayStatusForReturn($return),
            'return_type' => $return->return_type,
            'order_number' => $return->order_number,
            'customer_name' => $return->customer_name,
            'items_count' => $return->items_count,
            'shiphero_order_id' => $return->shiphero_order_id,
            'created_at' => optional($return->created_at)->toIso8601String(),
            'processed_at' => optional($return->processed_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLine(ClientAccountReturnLine $line): array
    {
        $reasonKey = $line->return_reason;
        $reasonLabel = $reasonKey !== null && $reasonKey !== ''
            ? ($this->returnReasonOptions()[$reasonKey] ?? $reasonKey)
            : null;

        return [
            'id' => $line->id,
            'shiphero_line_item_id' => $line->shiphero_line_item_id,
            'sku' => $line->sku,
            'name' => $line->name,
            'image_url' => $line->image_url,
            'order_qty' => $line->order_qty,
            'return_qty' => $line->return_qty,
            'return_reason' => $line->return_reason,
            'return_reason_label' => $reasonLabel,
            'sort_order' => $line->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReturnDetail(ClientAccountReturn $return): array
    {
        $return->loadMissing(['lines', 'clientAccount']);
        $companyName = $return->clientAccount !== null
            ? trim((string) $return->clientAccount->company_name)
            : '';

        return [
            'id' => $return->id,
            'client_account_id' => $return->client_account_id,
            'client_account_company_name' => $companyName,
            'rma_number' => $return->rma_number,
            'rma_label' => $return->rma_number !== '' ? 'RMA #'.$return->rma_number : '',
            'status' => $return->status,
            'display_status' => $this->displayStatusForReturn($return),
            'return_type' => $return->return_type,
            'shiphero_order_id' => $return->shiphero_order_id,
            'order_number' => $return->order_number,
            'customer_name' => $return->customer_name,
            'items_count' => $return->items_count,
            'warehouse_private_note' => $return->warehouse_private_note,
            'created_at' => optional($return->created_at)->toIso8601String(),
            'processed_at' => optional($return->processed_at)->toIso8601String(),
            'lines' => $return->lines->map(fn (ClientAccountReturnLine $l) => $this->serializeLine($l))->values()->all(),
            'return_reasons' => $this->returnReasonOptions(),
            'return_warehouse_address' => config('returns.return_warehouse_address', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function orderRowMatchesQuery(array $row, string $query): bool
    {
        $needle = $this->normalizeOrderNumber($query);
        if ($needle === '') {
            return false;
        }
        $fields = [
            $row['order_number'] ?? null,
            $row['partner_order_id'] ?? null,
            isset($row['legacy_id']) ? (string) $row['legacy_id'] : null,
        ];

        foreach ($fields as $field) {
            if ($this->normalizeOrderNumber((string) $field) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  User  $user
     * @return list<int>
     */
    private function viewableAccountIds(User $user): ?array
    {
        if ($user->isAdministrator() || $user->isCrmOwner()) {
            return null;
        }

        return ClientAccount::query()
            ->select('id')
            ->get()
            ->filter(fn (ClientAccount $a) => Gate::forUser($user)->allows('view', $a))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function pending(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $user = $request->user();

        $query = ClientAccountReturn::query()
            ->where('status', ClientAccountReturn::STATUS_PENDING)
            ->with('clientAccount')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($validated['client_account_id'])) {
            $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
            Gate::authorize('view', $account);
            $query->where('client_account_id', $account->id);
        } else {
            $allowedIds = $this->viewableAccountIds($user);
            if ($allowedIds !== null) {
                $query->whereIn('client_account_id', $allowedIds);
            }
        }

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())
            ->filter(fn (ClientAccountReturn $return) => Gate::forUser($user)->allows('view', $return))
            ->map(fn (ClientAccountReturn $return) => $this->serializeListRow($return, 'pending'))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function orderLookup(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:255'],
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);

        $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
        Gate::authorize('view', $account);

        $customerId = trim((string) $account->shiphero_customer_account_id);
        if ($customerId === '') {
            throw ValidationException::withMessages([
                'client_account_id' => ['This account is not linked to ShipHero.'],
            ]);
        }

        $orderQuery = trim((string) $validated['order_number']);
        $normalizedOrder = $this->normalizeOrderNumber($orderQuery);

        try {
            $payload = $this->orders->listOrders([
                'customer_account_id' => $customerId,
                'tab' => 'manage',
                'order_number' => ltrim($orderQuery, '#'),
                'first' => 25,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $matched = array_values(array_filter($rows, fn ($row) => is_array($row) && $this->orderRowMatchesQuery($row, $orderQuery)));
        if ($matched === [] && count($rows) === 1 && is_array($rows[0])) {
            $matched = [$rows[0]];
        }

        if ($matched === []) {
            return response()->json([
                'message' => 'Order not found.',
            ], 404);
        }

        $orderRow = $matched[0];
        $shipheroOrderId = trim((string) ($orderRow['id'] ?? ''));

        $return = ClientAccountReturn::query()
            ->where('client_account_id', $account->id)
            ->where('status', '!=', ClientAccountReturn::STATUS_DRAFT)
            ->where(function ($q) use ($normalizedOrder, $shipheroOrderId) {
                $q->whereRaw('LOWER(TRIM(BOTH "#" FROM order_number)) = ?', [$normalizedOrder]);
                if ($shipheroOrderId !== '') {
                    $q->orWhere('shiphero_order_id', $shipheroOrderId);
                }
            })
            ->orderByDesc('id')
            ->first();

        if ($return !== null) {
            Gate::authorize('view', $return);
        }

        $recipient = trim((string) ($orderRow['recipient_name'] ?? ''));
        $ship = is_array($orderRow['shipping_address'] ?? null) ? $orderRow['shipping_address'] : [];

        return response()->json([
            'client_account_id' => $account->id,
            'display_status' => $this->displayStatusForReturn($return),
            'order' => [
                'id' => $shipheroOrderId,
                'order_number' => $orderRow['order_number'] ?? null,
                'partner_order_id' => $orderRow['partner_order_id'] ?? null,
                'legacy_id' => $orderRow['legacy_id'] ?? null,
                'recipient_name' => $recipient !== '' ? $recipient : null,
                'email' => $orderRow['email'] ?? null,
                'shipping_address' => $ship,
            ],
            'return' => $return !== null ? $this->serializeListRow($return) : null,
        ]);
    }

    public function rmaLookup(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'rma_number' => ['required', 'string', 'max:255'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);

        $rmaNumber = $this->normalizeRmaNumber($validated['rma_number']);
        if ($rmaNumber === '') {
            throw ValidationException::withMessages([
                'rma_number' => ['Enter a valid RMA number.'],
            ]);
        }

        $query = ClientAccountReturn::query()
            ->where('status', '!=', ClientAccountReturn::STATUS_DRAFT)
            ->with('clientAccount');

        if (! empty($validated['client_account_id'])) {
            $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
            Gate::authorize('view', $account);
            $query->where('client_account_id', $account->id);
        }

        $returns = $query
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->filter(fn (ClientAccountReturn $return) => $this->rmaNumberMatches($return, $rmaNumber)
                && Gate::forUser($request->user())->allows('view', $return));

        $match = $returns->first();
        if ($match === null) {
            return response()->json(['message' => 'Return not found.'], 404);
        }

        return response()->json([
            'data' => $this->serializeListRow($match),
        ]);
    }

    public function process(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('view', $clientAccountReturn);

        if ($clientAccountReturn->status !== ClientAccountReturn::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending returns can be processed.'],
            ]);
        }

        $validated = $request->validate([
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer'],
        ]);

        $lineIds = array_map('intval', $validated['line_ids']);
        $lineIds = array_values(array_unique(array_filter($lineIds, fn ($id) => $id > 0)));

        if ($lineIds === []) {
            throw ValidationException::withMessages([
                'line_ids' => ['Select at least one item to process.'],
            ]);
        }

        $lines = ClientAccountReturnLine::query()
            ->where('client_account_return_id', $clientAccountReturn->id)
            ->get();

        $validIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($lineIds as $id) {
            if (! in_array($id, $validIds, true)) {
                throw ValidationException::withMessages([
                    'line_ids' => ['Invalid line selected.'],
                ]);
            }
        }

        DB::transaction(function () use ($clientAccountReturn, $lines, $lineIds) {
            foreach ($lines as $line) {
                if (! in_array((int) $line->id, $lineIds, true)) {
                    $line->return_qty = 0;
                    $line->return_reason = null;
                    $line->save();
                }
            }

            $sum = (int) ClientAccountReturnLine::query()
                ->where('client_account_return_id', $clientAccountReturn->id)
                ->sum('return_qty');

            $clientAccountReturn->items_count = $sum;
            $clientAccountReturn->status = ClientAccountReturn::STATUS_RECEIVED;
            if ($clientAccountReturn->processed_at === null) {
                $clientAccountReturn->processed_at = now();
            }
            $clientAccountReturn->save();
        });

        return response()->json($this->serializeReturnDetail($clientAccountReturn->fresh(['lines', 'clientAccount'])));
    }

    public function returnedOrders(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', Rule::in([
                'status',
                'order_number',
                'customer_name',
                'rma_number',
                'items_count',
                'return_type',
                'created_at',
                'processed_at',
            ])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $sortBy = (string) ($validated['sort_by'] ?? 'processed_at');
        $sortDir = strtolower((string) ($validated['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $user = $request->user();

        $query = ClientAccountReturn::query()
            ->whereIn('status', [ClientAccountReturn::STATUS_RECEIVED, ClientAccountReturn::STATUS_COMPLETED])
            ->with('clientAccount');

        if (! empty($validated['client_account_id'])) {
            $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
            Gate::authorize('view', $account);
            $query->where('client_account_id', $account->id);
        } else {
            $allowedIds = $this->viewableAccountIds($user);
            if ($allowedIds !== null) {
                $query->whereIn('client_account_id', $allowedIds);
            }
        }

        $q = isset($validated['q']) ? trim((string) $validated['q']) : '';
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like) {
                $w->where('rma_number', 'like', $like)
                    ->orWhere('order_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like);
            });
        }

        $query->orderBy($sortBy, $sortDir)->orderBy('id', $sortDir);
        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())
            ->filter(fn (ClientAccountReturn $return) => Gate::forUser($user)->allows('view', $return))
            ->map(fn (ClientAccountReturn $return) => $this->serializeListRow($return, 'returned'))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function returnedItems(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $user = $request->user();

        $query = ClientAccountReturnLine::query()
            ->where('return_qty', '>', 0)
            ->whereHas('clientAccountReturn', function ($r) use ($validated, $user) {
                $r->whereIn('status', [ClientAccountReturn::STATUS_RECEIVED, ClientAccountReturn::STATUS_COMPLETED]);
                if (! empty($validated['client_account_id'])) {
                    $r->where('client_account_id', (int) $validated['client_account_id']);
                } else {
                    $allowedIds = $this->viewableAccountIds($user);
                    if ($allowedIds !== null) {
                        $r->whereIn('client_account_id', $allowedIds);
                    }
                }
            })
            ->with(['clientAccountReturn.clientAccount']);

        if (! empty($validated['client_account_id'])) {
            $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
            Gate::authorize('view', $account);
        }

        $q = isset($validated['q']) ? trim((string) $validated['q']) : '';
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like) {
                $w->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('clientAccountReturn', function ($r) use ($like) {
                        $r->where('rma_number', 'like', $like)
                            ->orWhere('order_number', 'like', $like);
                    });
            });
        }

        $query->orderByDesc('id');
        $paginator = $query->paginate($perPage);

        $rows = collect($paginator->items())
            ->filter(function (ClientAccountReturnLine $line) use ($user) {
                $ret = $line->clientAccountReturn;

                return $ret !== null && Gate::forUser($user)->allows('view', $ret);
            })
            ->map(function (ClientAccountReturnLine $line) {
                $ret = $line->clientAccountReturn;
                $reasonKey = $line->return_reason;
                $reasonLabel = $reasonKey !== null && $reasonKey !== ''
                    ? ($this->returnReasonOptions()[$reasonKey] ?? $reasonKey)
                    : null;
                $companyName = '';
                if ($ret !== null && $ret->clientAccount !== null) {
                    $companyName = trim((string) $ret->clientAccount->company_name);
                }

                return [
                    'id' => $line->id,
                    'return_id' => $ret !== null ? $ret->id : null,
                    'status' => $ret !== null ? $ret->status : null,
                    'display_status' => 'returned',
                    'order_number' => $ret !== null ? $ret->order_number : null,
                    'sku' => $line->sku,
                    'name' => $line->name,
                    'rma_number' => $ret !== null ? $ret->rma_number : null,
                    'return_qty' => $line->return_qty,
                    'return_type' => $ret !== null ? $ret->return_type : null,
                    'return_reason' => $line->return_reason,
                    'return_reason_label' => $reasonLabel,
                    'client_account_id' => $ret !== null ? $ret->client_account_id : null,
                    'client_account_company_name' => $companyName,
                    'created_at' => $ret !== null ? optional($ret->created_at)->toIso8601String() : null,
                    'processed_at' => $ret !== null ? optional($ret->processed_at)->toIso8601String() : null,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProcessLookupRow(ClientAccountReturn $return): array
    {
        return $this->serializeListRow($return, 'pending');
    }

    public function processLookup(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'order_number' => ['nullable', 'string', 'max:255', 'required_without:rma_number'],
            'rma_number' => ['nullable', 'string', 'max:255', 'required_without:order_number'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);

        $orderNumber = isset($validated['order_number'])
            ? $this->normalizeOrderNumber($validated['order_number'])
            : '';
        $rmaNumber = isset($validated['rma_number'])
            ? $this->normalizeRmaNumber($validated['rma_number'])
            : '';

        if ($orderNumber === '' && $rmaNumber === '') {
            throw ValidationException::withMessages([
                'order_number' => ['Enter an order number or RMA number.'],
            ]);
        }

        $query = ClientAccountReturn::query()
            ->where('status', ClientAccountReturn::STATUS_PENDING)
            ->with('clientAccount');

        if (! empty($validated['client_account_id'])) {
            $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
            Gate::authorize('view', $account);
            $query->where('client_account_id', $account->id);
        }

        if ($orderNumber !== '') {
            $query->where('order_number', 'like', '%'.$orderNumber.'%');
        }
        if ($rmaNumber !== '') {
            $query->where('rma_number', 'like', '%'.$rmaNumber.'%');
        }

        $user = $request->user();
        $returns = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(function (ClientAccountReturn $return) use ($user, $orderNumber, $rmaNumber) {
                if (! Gate::forUser($user)->allows('view', $return)) {
                    return false;
                }
                if (! $this->orderNumberMatches($return, $orderNumber)) {
                    return false;
                }

                return $this->rmaNumberMatches($return, $rmaNumber);
            })
            ->take(25)
            ->values()
            ->map(fn (ClientAccountReturn $return) => $this->serializeProcessLookupRow($return));

        return response()->json([
            'data' => $returns->all(),
        ]);
    }
}
