<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WholesaleOrderCommentStoreRequest;
use App\Models\ClientAccount;
use App\Models\User;
use App\Models\WholesaleOrder;
use App\Models\WholesaleOrderComment;
use App\Models\WholesaleOrderLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    private function serializeLine(WholesaleOrderLine $line): array
    {
        return [
            'id' => $line->id,
            'sku' => $line->sku,
            'name' => $line->name,
            'image_url' => $line->image_url,
            'quantity' => $line->quantity,
            'barcode_mode' => $line->barcode_mode,
            'has_barcode' => $line->hasUploadedBarcode(),
            'barcode_original_name' => $line->barcode_original_name,
            'barcode_mime' => $line->barcode_mime,
            'sort_order' => $line->sort_order,
        ];
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

        return array_merge($this->serializeListRow($order), [
            'instructions' => $order->instructions,
            'is_editable' => $order->isEditable(),
            'lines' => $order->lines->map(fn (WholesaleOrderLine $l) => $this->serializeLine($l))->values()->all(),
            'comments' => $order->comments->map(fn (WholesaleOrderComment $c) => $this->serializeComment($c))->values()->all(),
            'statuses' => $this->statusLabels(),
            'order_types' => $this->typeLabels(),
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
        if (! $order->isEditable()) {
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

    public function update(Request $request, WholesaleOrder $wholesaleOrder): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('update', $wholesaleOrder);
        $this->assertLineEditable($wholesaleOrder);

        $validated = $request->validate([
            'order_number' => ['sometimes', 'string', 'max:128'],
            'order_type' => ['sometimes', 'string', Rule::in(WholesaleOrder::ORDER_TYPES)],
            'status' => ['sometimes', 'string', Rule::in(WholesaleOrder::STATUSES)],
            'instructions' => ['nullable', 'string', 'max:20000'],
        ]);

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
            'quantity' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        $line->quantity = (int) $validated['quantity'];
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
}
