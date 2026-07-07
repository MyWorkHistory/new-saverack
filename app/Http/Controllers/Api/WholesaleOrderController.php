<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WholesaleOrderCommentStoreRequest;
use App\Models\ClientAccount;
use App\Models\ShipHeroInventoryProductIndex;
use App\Models\User;
use App\Models\WholesaleOrder;
use App\Models\WholesaleOrderComment;
use App\Models\WholesaleOrderLine;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroOrderService;
use App\Services\WholesaleOrderShipHeroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class WholesaleOrderController extends Controller
{
    private function assertStaff(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        if ((int) ($user->client_account_id ?? 0) > 0) {
            abort(403, 'Wholesale order endpoints are for staff only.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = config('wholesale_orders.statuses', []);

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function typeLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = config('wholesale_orders.order_types', []);

        return $labels;
    }

    private function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '';
        }

        return $this->statusLabels()[$status] ?? $status;
    }

    private function typeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '';
        }

        return $this->typeLabels()[$type] ?? $type;
    }

    /**
     * @return array<string, string>
     */
    private function lineStatusLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = config('wholesale_orders.line_statuses', []);

        return $labels;
    }

    private function lineStatusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '';
        }

        return $this->lineStatusLabels()[$status] ?? $status;
    }

    /**
     * @return list<int>|null
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

    /**
     * @return array<string, mixed>
     */
    private function serializeComment(WholesaleOrderComment $comment): array
    {
        $comment->loadMissing('user');

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => optional($comment->created_at)->toIso8601String(),
            'user' => $comment->user !== null ? [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'email' => $comment->user->email,
            ] : null,
            'attachment' => $comment->hasAttachment() ? [
                'original_name' => $comment->attachment_original_name,
                'mime' => $comment->attachment_mime,
                'size' => $comment->attachment_size,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLine(WholesaleOrderLine $line, ?string $resolvedImageUrl = null): array
    {
        $imageUrl = $resolvedImageUrl ?? $line->image_url;

        return [
            'id' => $line->id,
            'sku' => $line->sku,
            'name' => $line->name,
            'image_url' => $imageUrl,
            'quantity' => $line->quantity,
            'quantity_picked' => (int) ($line->quantity_picked ?? 0),
            'is_fully_picked' => $line->isFullyPicked(),
            'status' => $line->status,
            'status_label' => $this->lineStatusLabel($line->status),
            'barcode_mode' => $line->barcode_mode,
            'has_barcode' => $line->hasUploadedBarcode(),
            'barcode_original_name' => $line->barcode_original_name,
            'barcode_mime' => $line->barcode_mime,
            'sort_order' => $line->sort_order,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function resolveLineImageUrls(WholesaleOrder $order): array
    {
        $order->loadMissing('lines');
        $clientAccountId = (int) $order->client_account_id;
        $skuKeys = [];
        foreach ($order->lines as $line) {
            if (is_string($line->image_url) && trim($line->image_url) !== '') {
                continue;
            }
            $key = mb_strtolower(trim((string) $line->sku));
            if ($key !== '') {
                $skuKeys[$key] = true;
            }
        }
        if ($skuKeys === [] || $clientAccountId <= 0) {
            return [];
        }

        $rows = ShipHeroInventoryProductIndex::query()
            ->where('client_account_id', $clientAccountId)
            ->whereIn('sku_search', array_keys($skuKeys))
            ->orderByDesc('synced_at')
            ->get(['sku_search', 'image_url']);

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row->sku_search;
            if ($key === '' || isset($map[$key])) {
                continue;
            }
            $url = is_string($row->image_url) ? trim($row->image_url) : '';
            if ($url !== '') {
                $map[$key] = $url;
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(WholesaleOrder $order): array
    {
        $order->loadMissing(['clientAccount', 'createdBy']);
        $companyName = $order->clientAccount !== null
            ? trim((string) $order->clientAccount->company_name)
            : '';
        $createdByName = $order->createdBy !== null
            ? trim((string) $order->createdBy->name)
            : '';

        return [
            'id' => $order->id,
            'status' => $order->status,
            'status_label' => $this->statusLabel($order->status),
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'order_type_label' => $this->typeLabel($order->order_type),
            'items_count' => $order->items_count,
            'client_account_id' => $order->client_account_id,
            'client_account_company_name' => $companyName,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'created_by_name' => $createdByName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(WholesaleOrder $order): array
    {
        $order->loadMissing(['clientAccount', 'createdBy', 'lines', 'comments.user']);
        $imageBySku = $this->resolveLineImageUrls($order);

        return array_merge($this->serializeListRow($order), [
            'instructions' => $order->instructions,
            'shiphero_order_id' => $order->shiphero_order_id,
            'shipping_address' => $order->shipping_address,
            'shipping_carrier' => $order->shipping_carrier,
            'shipping_method' => $order->shipping_method,
            'sku_barcode_labels' => $order->sku_barcode_labels,
            'sku_barcode_labels_comment' => $order->sku_barcode_labels_comment,
            'cover_existing_barcodes' => $order->cover_existing_barcodes,
            'cover_existing_barcodes_comment' => $order->cover_existing_barcodes_comment,
            'individual_sku_packaging' => $order->individual_sku_packaging,
            'individual_sku_packaging_comment' => $order->individual_sku_packaging_comment,
            'bundle_configuration' => $order->bundle_configuration,
            'bundle_configuration_comment' => $order->bundle_configuration_comment,
            'shipping_method_requirement' => $order->shipping_method_requirement,
            'shipping_method_requirement_comment' => $order->shipping_method_requirement_comment,
            'master_cartons' => $order->master_cartons,
            'master_cartons_comment' => $order->master_cartons_comment,
            'is_editable' => $order->isEditable(),
            'is_lines_editable' => $order->canEditLines(),
            'can_ready_to_ship' => $order->isReadyToShipEligible(),
            'has_complete_shipping_address' => $order->hasCompleteShippingAddress(),
            'has_requirements_filled' => $order->hasRequirementsFilled(),
            'has_all_lines_barcode_resolved' => $order->hasAllLinesBarcodeResolved(),
            'lines' => $order->lines->map(function (WholesaleOrderLine $line) use ($imageBySku) {
                $key = mb_strtolower(trim((string) $line->sku));
                $resolved = $imageBySku[$key] ?? null;

                return $this->serializeLine($line, $resolved);
            })->values()->all(),
            'comments' => $order->comments->map(fn (WholesaleOrderComment $c) => $this->serializeComment($c))->values()->all(),
            'statuses' => $this->statusLabels(),
            'manual_statuses' => [
                WholesaleOrder::STATUS_PENDING => $this->statusLabel(WholesaleOrder::STATUS_PENDING),
                WholesaleOrder::STATUS_COMPLETED => $this->statusLabel(WholesaleOrder::STATUS_COMPLETED),
            ],
            'order_types' => $this->typeLabels(),
            'requirement_options' => [
                'sku_barcode_labels' => config('wholesale_orders.sku_barcode_labels', []),
                'cover_existing_barcodes' => config('wholesale_orders.cover_existing_barcodes', []),
                'individual_sku_packaging' => config('wholesale_orders.individual_sku_packaging', []),
                'bundle_configuration' => config('wholesale_orders.bundle_configuration', []),
                'shipping_method_requirement' => config('wholesale_orders.shipping_method_requirement', []),
                'master_cartons' => config('wholesale_orders.master_cartons', []),
            ],
        ]);
    }

    private function recalculateItemsCount(WholesaleOrder $order): void
    {
        $sum = (int) WholesaleOrderLine::query()
            ->where('wholesale_order_id', $order->id)
            ->sum('quantity');
        $order->items_count = $sum;
        $order->saveQuietly();
    }

    private function assertLineEditable(WholesaleOrder $order): void
    {
        if (! $order->canEditLines()) {
            throw ValidationException::withMessages([
                'status' => ['This wholesale order cannot be edited.'],
            ]);
        }
    }

    private function assertLineBelongsToOrder(WholesaleOrder $order, WholesaleOrderLine $line): void
    {
        if ((int) $line->wholesale_order_id !== (int) $order->id) {
            throw ValidationException::withMessages(['line' => ['Invalid line selected.']]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', WholesaleOrder::class);

        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(WholesaleOrder::STATUSES)],
            'order_type' => ['nullable', 'string', Rule::in(WholesaleOrder::ORDER_TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $user = $request->user();

        $query = WholesaleOrder::query()
            ->with(['clientAccount', 'createdBy'])
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

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['order_type'])) {
            $query->where('order_type', $validated['order_type']);
        }

        $q = isset($validated['q']) ? trim((string) $validated['q']) : '';
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where('order_number', 'like', $like);
        }

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())
            ->filter(fn (WholesaleOrder $order) => Gate::forUser($user)->allows('view', $order))
            ->map(fn (WholesaleOrder $order) => $this->serializeListRow($order))
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

    public function store(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('create', WholesaleOrder::class);

        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'order_type' => ['required', 'string', Rule::in(WholesaleOrder::ORDER_TYPES)],
            'order_number' => ['required', 'string', 'max:128'],
            'instructions' => ['nullable', 'string', 'max:20000'],
        ]);

        $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
        Gate::authorize('view', $account);

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => trim((string) $validated['order_number']),
            'order_type' => $validated['order_type'],
            'status' => WholesaleOrder::STATUS_DRAFT,
            'instructions' => isset($validated['instructions']) ? trim((string) $validated['instructions']) : null,
            'items_count' => 0,
            'created_by_user_id' => $request->user() instanceof User ? $request->user()->id : null,
        ]);

        return response()->json($this->serializeDetail($order->fresh(['clientAccount', 'createdBy', 'lines', 'comments.user'])), 201);
    }

    public function show(Request $request, WholesaleOrder $wholesaleOrder): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('view', $wholesaleOrder);

        return response()->json($this->serializeDetail($wholesaleOrder));
    }

    public function productCatalog(
        Request $request,
        WholesaleOrder $wholesaleOrder,
        ShipHeroInventoryService $inventory
    ): JsonResponse {
        $this->assertStaff($request);
        Gate::authorize('view', $wholesaleOrder);

        $validated = $request->validate([
            'first' => ['nullable', 'integer', 'min:25', 'max:100'],
            'after' => ['nullable', 'string', 'max:500'],
            'query' => ['nullable', 'string', 'max:255'],
            'search_skip' => ['nullable', 'integer', 'min:0', 'max:500000'],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $clientAccountId = (int) $wholesaleOrder->client_account_id;
        $shipheroCustomerId = $this->shipheroCustomerIdForClientAccount($clientAccountId, $request);
        $graphqlFirst = isset($validated['first']) ? (int) $validated['first'] : 75;
        $after = isset($validated['after']) && is_string($validated['after']) ? $validated['after'] : null;
        $query = isset($validated['query']) && is_string($validated['query']) ? trim($validated['query']) : '';
        $searchSkip = isset($validated['search_skip']) ? (int) $validated['search_skip'] : 0;
        $refresh = (bool) ($validated['refresh'] ?? false);

        try {
            $payload = $inventory->listAsnProductCatalogPage(
                $shipheroCustomerId,
                $graphqlFirst,
                $after,
                $query !== '' ? $query : null,
                $searchSkip,
                $clientAccountId,
                $refresh
            );

            return response()->json([
                'products' => $payload['products'],
                'page_info' => $payload['page_info'],
                'client_account_id' => $clientAccountId,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load product catalog from ShipHero.',
            ], 502);
        }
    }

    private function shipheroCustomerIdForClientAccount(int $clientAccountId, Request $request): string
    {
        $account = ClientAccount::query()->find($clientAccountId);
        if ($account === null) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Client account not found.'],
            ]);
        }
        Gate::forUser($request->user())->authorize('view', $account);

        $sid = $account->shiphero_customer_account_id;
        if (! is_string($sid) || trim($sid) === '') {
            throw ValidationException::withMessages([
                'client_account_id' => [
                    'This client account has no ShipHero customer account ID. Set it on the account profile (Payment section), then try again.',
                ],
            ]);
        }

        return trim($sid);
    }

    public function update(Request $request, WholesaleOrder $wholesaleOrder): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);

        $validated = $request->validate([
            'order_number' => ['sometimes', 'string', 'max:128'],
            'order_type' => ['sometimes', 'string', Rule::in(WholesaleOrder::ORDER_TYPES)],
            'status' => ['sometimes', 'string', Rule::in([
                WholesaleOrder::STATUS_PENDING,
                WholesaleOrder::STATUS_COMPLETED,
            ])],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'shipping_address' => ['sometimes', 'nullable', 'array'],
            'shipping_address.first_name' => ['nullable', 'string', 'max:255'],
            'shipping_address.last_name' => ['nullable', 'string', 'max:255'],
            'shipping_address.company' => ['nullable', 'string', 'max:255'],
            'shipping_address.address1' => ['nullable', 'string', 'max:255'],
            'shipping_address.address2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:255'],
            'shipping_address.state' => ['nullable', 'string', 'max:64'],
            'shipping_address.zip' => ['nullable', 'string', 'max:32'],
            'shipping_address.country' => ['nullable', 'string', 'max:64'],
            'shipping_address.email' => ['nullable', 'string', 'max:255'],
            'shipping_address.phone' => ['nullable', 'string', 'max:64'],
            'shipping_carrier' => ['sometimes', 'nullable', 'string', 'max:128'],
            'shipping_method' => ['sometimes', 'nullable', 'string', 'max:128'],
            'sku_barcode_labels' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('wholesale_orders.sku_barcode_labels', [])))],
            'sku_barcode_labels_comment' => ['nullable', 'string', 'max:5000'],
            'cover_existing_barcodes' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('wholesale_orders.cover_existing_barcodes', [])))],
            'cover_existing_barcodes_comment' => ['nullable', 'string', 'max:5000'],
            'individual_sku_packaging' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('wholesale_orders.individual_sku_packaging', [])))],
            'individual_sku_packaging_comment' => ['nullable', 'string', 'max:5000'],
            'bundle_configuration' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('wholesale_orders.bundle_configuration', [])))],
            'bundle_configuration_comment' => ['nullable', 'string', 'max:5000'],
            'shipping_method_requirement' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('wholesale_orders.shipping_method_requirement', [])))],
            'shipping_method_requirement_comment' => ['nullable', 'string', 'max:5000'],
            'master_cartons' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('wholesale_orders.master_cartons', [])))],
            'master_cartons_comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $statusOnly = array_key_exists('status', $validated)
            && count($validated) === 1;

        if (! $statusOnly) {
            $this->assertLineEditable($wholesaleOrder);
        }

        if (array_key_exists('order_number', $validated)) {
            $wholesaleOrder->order_number = trim((string) $validated['order_number']);
        }
        if (array_key_exists('order_type', $validated)) {
            $wholesaleOrder->order_type = $validated['order_type'];
        }
        if (array_key_exists('status', $validated)) {
            $wholesaleOrder->status = $validated['status'];
        }
        if (array_key_exists('instructions', $validated)) {
            $wholesaleOrder->instructions = $validated['instructions'] !== null
                ? trim((string) $validated['instructions'])
                : null;
        }
        if (array_key_exists('shipping_address', $validated)) {
            $wholesaleOrder->shipping_address = $validated['shipping_address'];
        }
        if (array_key_exists('shipping_carrier', $validated)) {
            $wholesaleOrder->shipping_carrier = $validated['shipping_carrier'] !== null
                ? trim((string) $validated['shipping_carrier'])
                : null;
        }
        if (array_key_exists('shipping_method', $validated)) {
            $wholesaleOrder->shipping_method = $validated['shipping_method'] !== null
                ? trim((string) $validated['shipping_method'])
                : null;
        }
        if (array_key_exists('sku_barcode_labels', $validated)) {
            $wholesaleOrder->sku_barcode_labels = $validated['sku_barcode_labels'];
        }
        if (array_key_exists('sku_barcode_labels_comment', $validated)) {
            $wholesaleOrder->sku_barcode_labels_comment = $validated['sku_barcode_labels_comment'] !== null
                ? trim((string) $validated['sku_barcode_labels_comment'])
                : null;
        }
        if (array_key_exists('cover_existing_barcodes', $validated)) {
            $wholesaleOrder->cover_existing_barcodes = $validated['cover_existing_barcodes'];
        }
        if (array_key_exists('cover_existing_barcodes_comment', $validated)) {
            $wholesaleOrder->cover_existing_barcodes_comment = $validated['cover_existing_barcodes_comment'] !== null
                ? trim((string) $validated['cover_existing_barcodes_comment'])
                : null;
        }
        if (array_key_exists('individual_sku_packaging', $validated)) {
            $wholesaleOrder->individual_sku_packaging = $validated['individual_sku_packaging'];
        }
        if (array_key_exists('individual_sku_packaging_comment', $validated)) {
            $wholesaleOrder->individual_sku_packaging_comment = $validated['individual_sku_packaging_comment'] !== null
                ? trim((string) $validated['individual_sku_packaging_comment'])
                : null;
        }
        if (array_key_exists('bundle_configuration', $validated)) {
            $wholesaleOrder->bundle_configuration = $validated['bundle_configuration'];
        }
        if (array_key_exists('bundle_configuration_comment', $validated)) {
            $wholesaleOrder->bundle_configuration_comment = $validated['bundle_configuration_comment'] !== null
                ? trim((string) $validated['bundle_configuration_comment'])
                : null;
        }
        if (array_key_exists('shipping_method_requirement', $validated)) {
            $wholesaleOrder->shipping_method_requirement = $validated['shipping_method_requirement'];
        }
        if (array_key_exists('shipping_method_requirement_comment', $validated)) {
            $wholesaleOrder->shipping_method_requirement_comment = $validated['shipping_method_requirement_comment'] !== null
                ? trim((string) $validated['shipping_method_requirement_comment'])
                : null;
        }
        if (array_key_exists('master_cartons', $validated)) {
            $wholesaleOrder->master_cartons = $validated['master_cartons'];
        }
        if (array_key_exists('master_cartons_comment', $validated)) {
            $wholesaleOrder->master_cartons_comment = $validated['master_cartons_comment'] !== null
                ? trim((string) $validated['master_cartons_comment'])
                : null;
        }
        $wholesaleOrder->save();

        return response()->json($this->serializeDetail($wholesaleOrder->fresh(['clientAccount', 'createdBy', 'lines', 'comments.user'])));
    }

    public function storeLine(Request $request, WholesaleOrder $wholesaleOrder): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);
        $this->assertLineEditable($wholesaleOrder);

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:512'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99999999'],
            'image_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $maxSort = (int) WholesaleOrderLine::query()
            ->where('wholesale_order_id', $wholesaleOrder->id)
            ->max('sort_order');

        $line = new WholesaleOrderLine;
        $line->wholesale_order_id = $wholesaleOrder->id;
        $line->sku = trim((string) $validated['sku']);
        $line->name = trim((string) $validated['name']);
        $line->image_url = isset($validated['image_url']) ? trim((string) $validated['image_url']) : null;
        $line->quantity = (int) $validated['quantity'];
        $line->barcode_mode = WholesaleOrderLine::BARCODE_SHIP_AS_IS;
        $line->syncStatusFromBarcodeMode();
        $line->sort_order = $maxSort + 1;
        $line->save();

        $this->recalculateItemsCount($wholesaleOrder);

        return response()->json($this->serializeDetail($wholesaleOrder->fresh(['clientAccount', 'createdBy', 'lines', 'comments.user'])));
    }

    public function updateLine(Request $request, WholesaleOrder $wholesaleOrder, WholesaleOrderLine $line): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);
        $this->assertLineEditable($wholesaleOrder);
        $this->assertLineBelongsToOrder($wholesaleOrder, $line);

        $validated = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99999999'],
            'barcode_mode' => ['sometimes', 'string', Rule::in([
                WholesaleOrderLine::BARCODE_SHIP_AS_IS,
                WholesaleOrderLine::BARCODE_UPLOADED,
            ])],
        ]);

        if (array_key_exists('quantity', $validated)) {
            $line->quantity = (int) $validated['quantity'];
        }
        if (array_key_exists('barcode_mode', $validated)) {
            $line->barcode_mode = $validated['barcode_mode'];
            $line->syncStatusFromBarcodeMode();
        }
        $line->save();

        $this->recalculateItemsCount($wholesaleOrder);

        return response()->json($this->serializeDetail($wholesaleOrder->fresh(['clientAccount', 'createdBy', 'lines', 'comments.user'])));
    }

    public function destroyLine(Request $request, WholesaleOrder $wholesaleOrder, WholesaleOrderLine $line): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);
        $this->assertLineEditable($wholesaleOrder);
        $this->assertLineBelongsToOrder($wholesaleOrder, $line);

        if ($line->barcode_path) {
            Storage::disk('local')->delete($line->barcode_path);
        }
        $line->delete();
        $this->recalculateItemsCount($wholesaleOrder);

        return response()->json($this->serializeDetail($wholesaleOrder->fresh(['clientAccount', 'createdBy', 'lines', 'comments.user'])));
    }

    public function uploadLineBarcode(Request $request, WholesaleOrder $wholesaleOrder, WholesaleOrderLine $line): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);
        $this->assertLineEditable($wholesaleOrder);
        $this->assertLineBelongsToOrder($wholesaleOrder, $line);

        $validated = $request->validate([
            'barcode' => [
                'required',
                'file',
                'max:10240',
                'mimetypes:application/pdf,image/jpeg,image/png,image/gif,image/webp',
            ],
        ]);

        $file = $request->file('barcode');
        if ($line->barcode_path) {
            Storage::disk('local')->delete($line->barcode_path);
        }

        $path = $file->store('wholesale-order-barcodes/'.$wholesaleOrder->id, 'local');
        $line->barcode_mode = WholesaleOrderLine::BARCODE_UPLOADED;
        $line->status = WholesaleOrderLine::STATUS_BARCODE_READY;
        $line->barcode_path = $path;
        $line->barcode_original_name = $file->getClientOriginalName();
        $line->barcode_mime = $file->getClientMimeType();
        $line->save();

        return response()->json($this->serializeDetail($wholesaleOrder->fresh(['clientAccount', 'createdBy', 'lines', 'comments.user'])));
    }

    public function lineBarcodePdf(Request $request, WholesaleOrder $wholesaleOrder, WholesaleOrderLine $line)
    {
        $this->assertStaff($request);
        Gate::authorize('view', $wholesaleOrder);
        $this->assertLineBelongsToOrder($wholesaleOrder, $line);

        if (! $line->hasUploadedBarcode()) {
            return response()->json(['message' => 'No barcode uploaded for this line.'], 422);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($line->barcode_path)) {
            return response()->json(['message' => 'Barcode file not found.'], 404);
        }

        return $disk->response(
            $line->barcode_path,
            $line->barcode_original_name ?: 'barcode.pdf',
            ['Content-Type' => $line->barcode_mime ?: 'application/pdf']
        );
    }

    public function storeComment(WholesaleOrderCommentStoreRequest $request, WholesaleOrder $wholesaleOrder): JsonResponse
    {
        $this->assertStaff($request);
        $validated = $request->validated();
        $path = null;
        $original = null;
        $mime = null;
        $size = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('wholesale-order-comments/'.$wholesaleOrder->id, 'local');
            $original = $file->getClientOriginalName();
            $mime = $file->getClientMimeType();
            $size = (int) $file->getSize();
        }

        try {
            $comment = WholesaleOrderComment::query()->create([
                'wholesale_order_id' => $wholesaleOrder->id,
                'user_id' => $request->user()->id,
                'body' => $validated['body'],
                'attachment_path' => $path,
                'attachment_original_name' => $original,
                'attachment_mime' => $mime,
                'attachment_size' => $size,
            ]);
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        $comment->load('user:id,name,email');

        return response()->json($this->serializeComment($comment), 201);
    }

    public function downloadCommentAttachment(
        Request $request,
        WholesaleOrder $wholesaleOrder,
        WholesaleOrderComment $comment
    ) {
        $this->assertStaff($request);
        Gate::authorize('view', $wholesaleOrder);

        if ((int) $comment->wholesale_order_id !== (int) $wholesaleOrder->id || ! $comment->hasAttachment()) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($comment->attachment_path)) {
            abort(404);
        }

        return $disk->response(
            $comment->attachment_path,
            $comment->attachment_original_name ?: 'attachment',
            ['Content-Type' => $comment->attachment_mime ?: 'application/octet-stream']
        );
    }

    public function readyToShip(
        Request $request,
        WholesaleOrder $wholesaleOrder,
        WholesaleOrderShipHeroService $shipHero
    ): JsonResponse {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $shipHero->submitToShipHero(
            $wholesaleOrder,
            app(ShipHeroOrderService::class),
            $user
        );

        return response()->json($this->serializeDetail($wholesaleOrder->fresh(['clientAccount', 'createdBy', 'lines', 'comments.user'])));
    }

    public function pickList(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', WholesaleOrder::class);

        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $query = WholesaleOrder::query()
            ->with(['lines', 'clientAccount'])
            ->where('status', WholesaleOrder::STATUS_IN_PROGRESS)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if (! empty($validated['client_account_id'])) {
            $query->where('client_account_id', (int) $validated['client_account_id']);
        }

        $viewable = $this->viewableAccountIds($user);
        if ($viewable !== null) {
            $query->whereIn('client_account_id', $viewable);
        }

        $orders = $query->get()->map(fn (WholesaleOrder $order) => $this->serializePickListOrder($order))->values()->all();

        return response()->json(['orders' => $orders]);
    }

    public function updateLinePick(
        Request $request,
        WholesaleOrder $wholesaleOrder,
        WholesaleOrderLine $line
    ): JsonResponse {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);
        $this->assertPickableOrder($wholesaleOrder);
        $this->assertLineBelongsToOrder($wholesaleOrder, $line);

        $validated = $request->validate([
            'quantity_picked' => ['required', 'integer', 'min:0', 'max:'.$line->quantity],
        ]);

        $line->quantity_picked = (int) $validated['quantity_picked'];
        $line->save();

        $imageBySku = $this->resolveLineImageUrls($wholesaleOrder->fresh(['lines']));

        return response()->json(
            $this->serializePickListLine($line->fresh(), $imageBySku[mb_strtolower(trim((string) $line->sku))] ?? null)
        );
    }

    public function markPicked(Request $request, WholesaleOrder $wholesaleOrder): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);
        $this->assertPickableOrder($wholesaleOrder);

        $wholesaleOrder->loadMissing('lines');
        if (! $wholesaleOrder->canMarkPicked()) {
            throw ValidationException::withMessages([
                'order' => ['All line items must be fully picked before marking complete.'],
            ]);
        }

        $wholesaleOrder->status = WholesaleOrder::STATUS_COMPLETED;
        $wholesaleOrder->save();

        return response()->json($this->serializeDetail($wholesaleOrder->fresh(['clientAccount', 'createdBy', 'lines', 'comments.user'])));
    }

    private function assertPickableOrder(WholesaleOrder $order): void
    {
        if ($order->status !== WholesaleOrder::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'status' => ['Only ready-to-ship wholesale orders can be picked.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePickListOrder(WholesaleOrder $order): array
    {
        $order->loadMissing(['lines', 'clientAccount']);
        $imageBySku = $this->resolveLineImageUrls($order);
        $companyName = $order->clientAccount !== null
            ? trim((string) $order->clientAccount->company_name)
            : '';

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'client_account_id' => $order->client_account_id,
            'client_account_company_name' => $companyName,
            'is_fully_picked' => $order->isFullyPicked(),
            'lines' => $order->lines->map(function (WholesaleOrderLine $line) use ($imageBySku) {
                $key = mb_strtolower(trim((string) $line->sku));

                return $this->serializePickListLine($line, $imageBySku[$key] ?? null);
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePickListLine(WholesaleOrderLine $line, ?string $resolvedImageUrl = null): array
    {
        return [
            'id' => $line->id,
            'sku' => $line->sku,
            'name' => $line->name,
            'image_url' => $resolvedImageUrl ?? $line->image_url,
            'quantity' => (int) $line->quantity,
            'quantity_picked' => (int) ($line->quantity_picked ?? 0),
            'is_fully_picked' => $line->isFullyPicked(),
        ];
    }
}
