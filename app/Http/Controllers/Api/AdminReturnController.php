<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\User;
use App\Services\ReturnFeeService;
use App\Services\ReturnProcessingService;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroOrderService;
use App\Support\Barcode\Code128Svg;
use App\Support\Returns\ReturnReasonOptions;
use App\Support\Returns\ReturnRmaGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
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

    /** @var ReturnProcessingService */
    private $processing;

    /** @var ReturnFeeService */
    private $returnFees;

    /** @var ShipHeroInventoryService */
    private $inventory;

    public function __construct(
        ShipHeroOrderService $orders,
        ReturnProcessingService $processing,
        ReturnFeeService $returnFees,
        ShipHeroInventoryService $inventory
    ) {
        $this->orders = $orders;
        $this->processing = $processing;
        $this->returnFees = $returnFees;
        $this->inventory = $inventory;
    }

    private function safePdfName($raw): string
    {
        $s = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string) $raw));
        $s = trim((string) $s, '-');

        return $s !== '' ? $s : 'document';
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
        if ($return->isNonCompliant() && $status === ClientAccountReturn::STATUS_PENDING) {
            return 'non_compliant_return';
        }
        if ($return->isThirdParty() && $status === ClientAccountReturn::STATUS_PENDING) {
            return 'third_party_return';
        }

        return 'pending';
    }

    private function assertPendingStaffManagedLines(ClientAccountReturn $return): void
    {
        if ($return->status !== ClientAccountReturn::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Line changes are only allowed on pending staff-managed returns.'],
            ]);
        }
        if (! $return->isNonCompliant() && ! $return->isThirdParty()) {
            throw ValidationException::withMessages([
                'status' => ['Line changes are only allowed on pending non-compliant or third-party returns.'],
            ]);
        }
    }

    /**
     * @return array{third_party_type: string|null, third_party_type_label: string|null}
     */
    private function thirdPartyMeta(ClientAccountReturn $return): array
    {
        if (! $return->isThirdParty()) {
            return [
                'third_party_type' => null,
                'third_party_type_label' => null,
            ];
        }

        $channel = ClientAccountReturn::thirdPartyTypeFromReturnType($return->return_type);

        return [
            'third_party_type' => $channel,
            'third_party_type_label' => ClientAccountReturn::thirdPartyTypeLabel($return->return_type),
        ];
    }

    private function lineReturnReasonForStaffManagedReturn(ClientAccountReturn $return): ?string
    {
        if ($return->isNonCompliant()) {
            return $return->non_compliant_reason;
        }
        if ($return->isThirdParty()) {
            return ReturnReasonOptions::adminDefaultKey();
        }

        return null;
    }

    private function pendingReturnsQuery(Request $request, bool $thirdPartyOnly): \Illuminate\Database\Eloquent\Builder
    {
        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $query = ClientAccountReturn::query()
            ->where('status', ClientAccountReturn::STATUS_PENDING)
            ->with('clientAccount')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($thirdPartyOnly) {
            $query->where('is_third_party', true);
        } else {
            $query->where('is_third_party', false);
        }

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

        return $query;
    }

    private function paginatedPendingResponse(Request $request, \Illuminate\Database\Eloquent\Builder $query): JsonResponse
    {
        $perPage = (int) ($request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])['per_page'] ?? 25);
        $user = $request->user();

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())
            ->filter(fn (ClientAccountReturn $return) => Gate::forUser($user)->allows('view', $return))
            ->map(fn (ClientAccountReturn $return) => array_merge(
                $this->serializeListRow($return),
                $this->thirdPartyMeta($return)
            ))
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

    private function recalculateItemsCount(ClientAccountReturn $return): void
    {
        $sum = (int) ClientAccountReturnLine::query()
            ->where('client_account_return_id', $return->id)
            ->sum('return_qty');
        $return->items_count = $sum;
        $return->saveQuietly();
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
            'is_non_compliant' => $return->isNonCompliant(),
            'is_third_party' => $return->isThirdParty(),
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
    private function serializeLine(ClientAccountReturnLine $line, ?string $createdSource = null, ?ClientAccountReturn $return = null): array
    {
        $reasonLabel = null;
        if ($return !== null && $return->isNonCompliant()) {
            $reasonKey = $line->return_reason ?: $return->non_compliant_reason;
            $reasonLabel = ReturnReasonOptions::nonCompliantLabel($reasonKey);
        } else {
            $reasonLabel = ReturnReasonOptions::labelFor($line->return_reason, $createdSource);
        }

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
            'restock' => (bool) $line->restock,
            'sort_order' => $line->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReturnDetail(ClientAccountReturn $return): array
    {
        $return->loadMissing(['lines', 'clientAccount', 'returnBill']);
        $companyName = $return->clientAccount !== null
            ? trim((string) $return->clientAccount->company_name)
            : '';

        $payload = [
            'id' => $return->id,
            'client_account_id' => $return->client_account_id,
            'client_account_company_name' => $companyName,
            'rma_number' => $return->rma_number,
            'rma_label' => $return->rma_number !== '' ? 'RMA #'.$return->rma_number : '',
            'status' => $return->status,
            'display_status' => $this->displayStatusForReturn($return),
            'is_non_compliant' => $return->isNonCompliant(),
            'is_third_party' => $return->isThirdParty(),
            'non_compliant_reason' => $return->non_compliant_reason,
            'non_compliant_reason_label' => ReturnReasonOptions::nonCompliantLabel($return->non_compliant_reason),
            'non_compliant_declared_items' => $return->non_compliant_declared_items,
            'return_type' => $return->return_type,
            'shiphero_order_id' => $return->shiphero_order_id,
            'order_number' => $return->order_number,
            'customer_name' => $return->customer_name,
            'items_count' => $return->items_count,
            'warehouse_private_note' => $return->warehouse_private_note,
            'created_source' => $return->created_source,
            'created_at' => optional($return->created_at)->toIso8601String(),
            'processed_at' => optional($return->processed_at)->toIso8601String(),
            'lines' => $return->lines->map(fn (ClientAccountReturnLine $l) => $this->serializeLine($l, $return->created_source, $return))->values()->all(),
            'non_compliant_reasons' => ReturnReasonOptions::nonCompliant(),
            'return_reasons' => $return->isAdminCreated() ? ReturnReasonOptions::admin() : $this->returnReasonOptions(),
            'admin_return_reasons' => ReturnReasonOptions::admin(),
            'admin_default_return_reason' => ReturnReasonOptions::adminDefaultKey(),
            'return_fees' => $this->returnFees->serializeReturnFees($return),
            'return_bill_id' => $return->return_bill_id,
            'return_warehouse_address' => config('returns.return_warehouse_address', []),
        ];

        return array_merge($payload, $this->thirdPartyMeta($return));
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

        return $this->paginatedPendingResponse($request, $this->pendingReturnsQuery($request, false));
    }

    public function thirdPartyPending(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        return $this->paginatedPendingResponse($request, $this->pendingReturnsQuery($request, true));
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

    public function feeDefaults(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);
        $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
        Gate::authorize('view', $account);
        $defaults = $this->returnFees->accountDefaults($account);

        return response()->json([
            'first_item' => $defaults['first_item'],
            'additional_item' => $defaults['additional_item'],
            'non_compliant' => $defaults['non_compliant'],
            'first_item_label' => 'Returns (First Item)',
            'additional_item_label' => 'Returns (Additional Items)',
            'non_compliant_label' => 'Non-Compliant Return',
        ]);
    }

    public function updateFees(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('view', $clientAccountReturn);
        $validated = $request->validate([
            'first_item' => ['nullable', 'numeric', 'min:0'],
            'additional_item' => ['nullable', 'numeric', 'min:0'],
            'non_compliant' => ['nullable', 'numeric', 'min:0'],
        ]);
        $first = array_key_exists('first_item', $validated) ? (float) $validated['first_item'] : null;
        $additional = array_key_exists('additional_item', $validated) ? (float) $validated['additional_item'] : null;
        $nonCompliant = array_key_exists('non_compliant', $validated) ? (float) $validated['non_compliant'] : null;
        $this->returnFees->updateReturnFees($clientAccountReturn, $first, $additional, $nonCompliant);

        return response()->json($this->serializeReturnDetail($clientAccountReturn->fresh(['lines', 'clientAccount', 'returnBill'])));
    }

    public function processFromDraft(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('view', $clientAccountReturn);

        $validated = $request->validate([
            'return_type' => ['sometimes', 'string', Rule::in(ClientAccountReturn::RETURN_TYPES)],
            'warehouse_private_note' => ['nullable', 'string', 'max:20000'],
            'first_item_fee' => ['nullable', 'numeric', 'min:0'],
            'additional_item_fee' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:255'],
            'lines.*.name' => ['required', 'string', 'max:512'],
            'lines.*.order_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
            'lines.*.return_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
            'lines.*.shiphero_line_item_id' => ['nullable', 'string', 'max:64'],
            'lines.*.image_url' => ['nullable', 'string', 'max:2048'],
            'lines.*.return_reason' => ['nullable', 'string', 'max:64'],
            'lines.*.restock' => ['nullable', 'boolean'],
        ]);

        $normalized = $this->processing->validateAndNormalizeAdminLines($validated['lines']);
        $return = $this->processing->processFromDraft(
            $clientAccountReturn,
            $normalized,
            $validated['return_type'] ?? null,
            array_key_exists('warehouse_private_note', $validated) ? ($validated['warehouse_private_note'] ?? null) : null,
            isset($validated['first_item_fee']) ? (float) $validated['first_item_fee'] : null,
            isset($validated['additional_item_fee']) ? (float) $validated['additional_item_fee'] : null,
            $request->user() instanceof User ? $request->user() : null,
        );

        return response()->json($this->serializeReturnDetail($return));
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
            'restock_by_line_id' => ['nullable', 'array'],
            'restock_by_line_id.*' => ['boolean'],
            'first_item_fee' => ['nullable', 'numeric', 'min:0'],
            'additional_item_fee' => ['nullable', 'numeric', 'min:0'],
            'non_compliant_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lineIds = array_map('intval', $validated['line_ids']);
        $lineIds = array_values(array_unique(array_filter($lineIds, fn ($id) => $id > 0)));

        if (isset($validated['first_item_fee']) || isset($validated['additional_item_fee']) || isset($validated['non_compliant_fee'])) {
            $this->returnFees->updateReturnFees(
                $clientAccountReturn,
                isset($validated['first_item_fee']) ? (float) $validated['first_item_fee'] : null,
                isset($validated['additional_item_fee']) ? (float) $validated['additional_item_fee'] : null,
                isset($validated['non_compliant_fee']) ? (float) $validated['non_compliant_fee'] : null,
            );
            $clientAccountReturn->refresh();
        }

        $restockMap = [];
        if (isset($validated['restock_by_line_id']) && is_array($validated['restock_by_line_id'])) {
            foreach ($validated['restock_by_line_id'] as $key => $value) {
                $restockMap[(int) $key] = (bool) $value;
            }
        }

        $return = $this->processing->processPendingReturn(
            $clientAccountReturn,
            $lineIds,
            $restockMap,
            $request->user() instanceof User ? $request->user() : null,
        );

        return response()->json($this->serializeReturnDetail($return));
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
                    ? ReturnReasonOptions::labelFor($reasonKey, $ret !== null ? $ret->created_source : null)
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

    private function serializeProcessLookupRow(ClientAccountReturn $return): array
    {
        return $this->serializeListRow($return);
    }

    public function storeNonCompliant(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'declared_items' => ['required', 'integer', 'min:1', 'max:99999999'],
            'reason' => ['required', 'string', Rule::in(array_keys(ReturnReasonOptions::nonCompliant()))],
        ]);

        $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
        Gate::authorize('view', $account);

        $return = DB::transaction(function () use ($account, $validated) {
            $return = new ClientAccountReturn;
            $return->client_account_id = $account->id;
            $return->rma_number = ReturnRmaGenerator::generateUniqueForAccount($account->id);
            $return->status = ClientAccountReturn::STATUS_PENDING;
            $return->created_source = ClientAccountReturn::SOURCE_ADMIN;
            $return->return_type = ClientAccountReturn::TYPE_DIRECT;
            $return->is_non_compliant = true;
            $return->non_compliant_reason = $validated['reason'];
            $return->non_compliant_declared_items = (int) $validated['declared_items'];
            $return->items_count = 0;
            $return->save();

            $this->returnFees->seedReturnFees($return);

            return $return;
        });

        return response()->json($this->serializeReturnDetail($return->fresh(['lines', 'clientAccount'])), 201);
    }

    public function storeThirdParty(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'third_party_type' => ['required', 'string', Rule::in(['amazon', 'other'])],
        ]);

        $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
        Gate::authorize('view', $account);

        $return = DB::transaction(function () use ($account, $validated) {
            $return = new ClientAccountReturn;
            $return->client_account_id = $account->id;
            $return->rma_number = ReturnRmaGenerator::generateUniqueForAccount($account->id);
            $return->status = ClientAccountReturn::STATUS_PENDING;
            $return->created_source = ClientAccountReturn::SOURCE_ADMIN;
            $return->is_third_party = true;
            $return->return_type = ClientAccountReturn::returnTypeForThirdPartyType($validated['third_party_type']);
            $return->items_count = 0;
            $return->save();

            $this->returnFees->seedReturnFees($return);

            return $return;
        });

        return response()->json($this->serializeReturnDetail($return->fresh(['lines', 'clientAccount'])), 201);
    }

    public function storeLine(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('view', $clientAccountReturn);
        $this->assertPendingStaffManagedLines($clientAccountReturn);

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:512'],
            'return_qty' => ['required', 'integer', 'min:1', 'max:99999999'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'shiphero_line_item_id' => ['nullable', 'string', 'max:64'],
        ]);

        $returnQty = (int) $validated['return_qty'];
        $maxSort = (int) ClientAccountReturnLine::query()
            ->where('client_account_return_id', $clientAccountReturn->id)
            ->max('sort_order');

        $line = new ClientAccountReturnLine;
        $line->client_account_return_id = $clientAccountReturn->id;
        $line->shiphero_line_item_id = isset($validated['shiphero_line_item_id'])
            ? trim((string) $validated['shiphero_line_item_id'])
            : null;
        $line->sku = trim((string) $validated['sku']);
        $line->name = trim((string) $validated['name']);
        $line->image_url = isset($validated['image_url']) ? trim((string) $validated['image_url']) : null;
        $line->order_qty = $returnQty;
        $line->return_qty = $returnQty;
        $line->return_reason = $this->lineReturnReasonForStaffManagedReturn($clientAccountReturn);
        $line->restock = true;
        $line->sort_order = $maxSort + 1;
        $line->save();

        $this->recalculateItemsCount($clientAccountReturn);

        return response()->json($this->serializeReturnDetail($clientAccountReturn->fresh(['lines', 'clientAccount', 'returnBill'])));
    }

    public function updateLine(
        Request $request,
        ClientAccountReturn $clientAccountReturn,
        ClientAccountReturnLine $line
    ): JsonResponse {
        $this->assertStaff($request);
        Gate::authorize('view', $clientAccountReturn);
        $this->assertPendingStaffManagedLines($clientAccountReturn);

        if ((int) $line->client_account_return_id !== (int) $clientAccountReturn->id) {
            throw ValidationException::withMessages(['line' => ['Invalid line selected.']]);
        }

        $validated = $request->validate([
            'return_qty' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        $returnQty = (int) $validated['return_qty'];
        $line->return_qty = $returnQty;
        $line->order_qty = $returnQty;
        $line->save();

        $this->recalculateItemsCount($clientAccountReturn);

        return response()->json($this->serializeReturnDetail($clientAccountReturn->fresh(['lines', 'clientAccount', 'returnBill'])));
    }

    public function destroyLine(
        Request $request,
        ClientAccountReturn $clientAccountReturn,
        ClientAccountReturnLine $line
    ): JsonResponse {
        $this->assertStaff($request);
        Gate::authorize('view', $clientAccountReturn);
        $this->assertPendingStaffManagedLines($clientAccountReturn);

        if ((int) $line->client_account_return_id !== (int) $clientAccountReturn->id) {
            throw ValidationException::withMessages(['line' => ['Invalid line selected.']]);
        }

        $line->delete();
        $this->recalculateItemsCount($clientAccountReturn);

        return response()->json($this->serializeReturnDetail($clientAccountReturn->fresh(['lines', 'clientAccount', 'returnBill'])));
    }

    public function lineBarcodePdf(
        Request $request,
        ClientAccountReturn $clientAccountReturn,
        ClientAccountReturnLine $line
    ) {
        $this->assertStaff($request);
        Gate::authorize('view', $clientAccountReturn);

        if ((int) $line->client_account_return_id !== (int) $clientAccountReturn->id) {
            abort(404);
        }

        $clientAccountReturn->loadMissing('clientAccount');
        $customerId = $clientAccountReturn->clientAccount !== null
            ? trim((string) $clientAccountReturn->clientAccount->shiphero_customer_account_id)
            : '';
        $sku = trim((string) $line->sku);
        $barcode = '';

        if ($sku !== '' && $sku !== ClientAccountReturn::UNKNOWN_SKU && $customerId !== '') {
            $product = $this->inventory->getProductDetailBySku($sku, null, $customerId);
            $barcode = is_array($product) && isset($product['barcode'])
                ? trim((string) $product['barcode'])
                : '';
        }

        if ($barcode === '' && $sku !== '') {
            $barcode = $sku;
        }

        if ($barcode === '') {
            return response()->json(['message' => 'No barcode available for this line.'], 422);
        }

        $pdf = Pdf::loadView('pdf.asn.barcode', [
            'line' => $line,
            'barcode' => $barcode,
            'barcodeSvg' => Code128Svg::dataUri($barcode),
        ])->setPaper([0, 0, 288, 144]);

        return $pdf->stream(
            'return-'.$this->safePdfName($clientAccountReturn->rma_number).'-'.$this->safePdfName($line->sku).'-barcode.pdf'
        );
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
