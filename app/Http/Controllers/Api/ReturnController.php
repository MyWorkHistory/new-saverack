<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\User;
use App\Services\ReturnFeeService;
use App\Support\Barcode\Code128Svg;
use App\Support\Returns\ReturnRmaGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\Returns\ReturnReasonOptions;
use Illuminate\Validation\ValidationException;

class ReturnController extends Controller
{
    private function isPortalUser(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return false;
        }

        return (int) ($user->client_account_id ?? 0) > 0;
    }

    private function resolveClientAccountId(Request $request, array $validated): int
    {
        if ($this->isPortalUser($request)) {
            return (int) $request->user()->client_account_id;
        }

        return (int) $validated['client_account_id'];
    }

    private function authorizeReturn(Request $request, ClientAccountReturn $return): void
    {
        Gate::forUser($request->user())->authorize('view', $return);
    }

    private function safePdfName($raw): string
    {
        $s = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string) $raw));
        $s = trim((string) $s, '-');

        return $s !== '' ? $s : 'document';
    }

    private function formatRmaLabel(string $rmaNumber): string
    {
        $s = trim($rmaNumber);
        if ($s === '') {
            return '';
        }

        return 'RMA #'.$s;
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

    private function validateReturnReason(?string $reason): void
    {
        if ($reason === null || $reason === '') {
            return;
        }
        if (! array_key_exists($reason, $this->returnReasonOptions())) {
            throw ValidationException::withMessages([
                'lines' => ['Invalid return reason selected.'],
            ]);
        }
    }

    private function recalcItemsCount(ClientAccountReturn $return): void
    {
        $sum = (int) ClientAccountReturnLine::query()
            ->where('client_account_return_id', $return->id)
            ->sum('return_qty');
        $return->items_count = $sum;
        $return->saveQuietly();
    }

    private function displayStatusForReturn(ClientAccountReturn $return): string
    {
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

        return $status === ClientAccountReturn::STATUS_PENDING ? 'pending' : $status;
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

        return [
            'third_party_type' => ClientAccountReturn::thirdPartyTypeFromReturnType($return->return_type),
            'third_party_type_label' => ClientAccountReturn::thirdPartyTypeLabel($return->return_type),
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
    private function serializeReturn(ClientAccountReturn $return): array
    {
        $return->loadMissing(['lines', 'clientAccount', 'returnBin']);
        $companyName = $return->clientAccount !== null
            ? trim((string) $return->clientAccount->company_name)
            : '';
        $binName = $return->returnBin !== null
            ? trim((string) $return->returnBin->name)
            : '';

        return array_merge([
            'id' => $return->id,
            'client_account_id' => $return->client_account_id,
            'client_account_company_name' => $companyName,
            'rma_number' => $return->rma_number,
            'rma_label' => $this->formatRmaLabel($return->rma_number),
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
            'created_at' => optional($return->created_at)->toIso8601String(),
            'processed_at' => optional($return->processed_at)->toIso8601String(),
            'updated_at' => optional($return->updated_at)->toIso8601String(),
            'return_bin_id' => $return->return_bin_id,
            'return_bin_name' => $binName !== '' ? $binName : null,
            'return_bin_number' => $return->return_bin_number,
            'lines' => $return->lines->map(fn (ClientAccountReturnLine $l) => $this->serializeLine($l, $return->created_source, $return))->values()->all(),
            'non_compliant_reasons' => ReturnReasonOptions::nonCompliant(),
            'return_reasons' => $this->returnReasonOptions(),
            'return_warehouse_address' => config('returns.return_warehouse_address', []),
            'created_source' => $return->created_source,
            'return_fees' => app(ReturnFeeService::class)->serializeReturnFees($return),
            'return_bill_id' => $return->return_bill_id,
        ], $this->thirdPartyMeta($return));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(ClientAccountReturn $return): array
    {
        return [
            'id' => $return->id,
            'client_account_id' => $return->client_account_id,
            'rma_number' => $return->rma_number,
            'status' => $return->status,
            'return_type' => $return->return_type,
            'order_number' => $return->order_number,
            'customer_name' => $return->customer_name,
            'items_count' => $return->items_count,
            'created_at' => optional($return->created_at)->toIso8601String(),
            'processed_at' => optional($return->processed_at)->toIso8601String(),
        ];
    }

    private function isManualReturn(ClientAccountReturn $return): bool
    {
        return str_starts_with((string) $return->shiphero_order_id, 'manual:');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validateAndNormalizeLines(array $lines, bool $manual = false): array
    {
        $normalized = [];
        $hasPositive = false;
        $order = 0;
        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }
            $orderQty = (int) ($row['order_qty'] ?? 0);
            $returnQty = (int) ($row['return_qty'] ?? 0);
            if ($returnQty < 0) {
                throw ValidationException::withMessages([
                    'lines' => ['Return quantity cannot be negative.'],
                ]);
            }
            if ($manual && $returnQty > $orderQty) {
                $orderQty = $returnQty;
            }
            if ($returnQty > $orderQty) {
                throw ValidationException::withMessages([
                    'lines' => ['Return quantity cannot exceed order quantity.'],
                ]);
            }
            $reason = isset($row['return_reason']) ? trim((string) $row['return_reason']) : null;
            if ($returnQty > 0) {
                $hasPositive = true;
                if ($reason === null || $reason === '') {
                    throw ValidationException::withMessages([
                        'lines' => ['Return reason is required when return quantity is greater than zero.'],
                    ]);
                }
                $this->validateReturnReason($reason);
            } else {
                $reason = null;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($sku === '' || $name === '') {
                continue;
            }
            $normalized[] = [
                'shiphero_line_item_id' => isset($row['shiphero_line_item_id']) ? trim((string) $row['shiphero_line_item_id']) : null,
                'sku' => $sku,
                'name' => $name,
                'image_url' => isset($row['image_url']) ? trim((string) $row['image_url']) : null,
                'order_qty' => $orderQty,
                'return_qty' => $returnQty,
                'return_reason' => $reason,
                'sort_order' => $order++,
            ];
        }
        if (! $hasPositive) {
            throw ValidationException::withMessages([
                'lines' => ['Select at least one item with a return quantity greater than zero.'],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalized
     */
    private function persistLines(ClientAccountReturn $return, array $normalized): void
    {
        ClientAccountReturnLine::query()->where('client_account_return_id', $return->id)->delete();
        foreach ($normalized as $row) {
            $line = new ClientAccountReturnLine;
            $line->client_account_return_id = $return->id;
            $line->shiphero_line_item_id = $row['shiphero_line_item_id'] ?? null;
            $line->sku = $row['sku'];
            $line->name = $row['name'];
            $line->image_url = $row['image_url'] ?? null;
            $line->order_qty = (int) $row['order_qty'];
            $line->return_qty = (int) $row['return_qty'];
            $line->return_reason = $row['return_reason'] ?? null;
            $line->restock = array_key_exists('restock', $row) ? (bool) $row['restock'] : true;
            $line->sort_order = (int) $row['sort_order'];
            $line->save();
        }
        $this->recalcItemsCount($return);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
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
            ])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);
        $clientAccountId = $this->isPortalUser($request)
            ? (int) $request->user()->client_account_id
            : (int) $validated['client_account_id'];
        Gate::authorize('view', ClientAccount::query()->findOrFail($clientAccountId));

        $q = isset($validated['q']) ? trim((string) $validated['q']) : '';
        $perPage = (int) ($validated['per_page'] ?? 25);
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDir = strtolower((string) ($validated['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = ClientAccountReturn::query()
            ->where('client_account_id', $clientAccountId)
            ->whereIn('status', ClientAccountReturn::LIST_STATUSES);
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

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ClientAccountReturn $r) => $this->serializeListRow($r))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function itemsIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $clientAccountId = $this->isPortalUser($request)
            ? (int) $request->user()->client_account_id
            : (int) $validated['client_account_id'];
        Gate::authorize('view', ClientAccount::query()->findOrFail($clientAccountId));

        $q = isset($validated['q']) ? trim((string) $validated['q']) : '';
        $perPage = (int) ($validated['per_page'] ?? 25);

        $query = ClientAccountReturnLine::query()
            ->whereHas('clientAccountReturn', function ($r) use ($clientAccountId) {
                $r->where('client_account_id', $clientAccountId)
                    ->whereIn('status', ClientAccountReturn::LIST_STATUSES);
            })
            ->where('return_qty', '>', 0)
            ->with('clientAccountReturn');

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

        $rows = collect($paginator->items())->map(function (ClientAccountReturnLine $line) {
            $ret = $line->clientAccountReturn;
            $reasonKey = $line->return_reason;
            $reasonLabel = $reasonKey !== null && $reasonKey !== ''
                ? ($this->returnReasonOptions()[$reasonKey] ?? $reasonKey)
                : null;

            return [
                'id' => $line->id,
                'return_id' => $ret->id,
                'status' => $ret->status,
                'order_number' => $ret->order_number,
                'sku' => $line->sku,
                'name' => $line->name,
                'rma_number' => $ret->rma_number,
                'return_qty' => $line->return_qty,
                'return_type' => $ret->return_type,
                'return_reason' => $line->return_reason,
                'return_reason_label' => $reasonLabel,
                'created_at' => optional($ret->created_at)->toIso8601String(),
                'processed_at' => optional($ret->processed_at)->toIso8601String(),
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
        ]);
    }

    public function storeDraft(Request $request): JsonResponse
    {
        $manual = $request->boolean('manual');
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'manual' => ['sometimes', 'boolean'],
            'shiphero_order_id' => [Rule::requiredIf(! $manual), 'nullable', 'string', 'max:64'],
            'order_number' => ['required', 'string', 'max:128'],
            'customer_name' => [Rule::requiredIf($manual), 'nullable', 'string', 'max:512'],
            'return_type' => ['sometimes', 'string', Rule::in(ClientAccountReturn::RETURN_TYPES)],
        ]);
        $clientAccountId = $this->resolveClientAccountId($request, $validated);
        if ($this->isPortalUser($request) && (int) $validated['client_account_id'] !== $clientAccountId) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Invalid client account.'],
            ]);
        }
        Gate::authorize('view', ClientAccount::query()->findOrFail($clientAccountId));

        $return = DB::transaction(function () use ($clientAccountId, $validated, $manual, $request) {
            $return = new ClientAccountReturn;
            $return->client_account_id = $clientAccountId;
            $return->rma_number = ReturnRmaGenerator::generateUniqueForAccount($clientAccountId);
            $return->status = ClientAccountReturn::STATUS_DRAFT;
            $return->created_source = $this->isPortalUser($request)
                ? ClientAccountReturn::SOURCE_PORTAL
                : ClientAccountReturn::SOURCE_ADMIN;
            $return->return_type = $validated['return_type'] ?? ClientAccountReturn::TYPE_DIRECT;
            $return->shiphero_order_id = $manual
                ? 'manual:'.(string) Str::uuid()
                : trim((string) $validated['shiphero_order_id']);
            $return->order_number = trim((string) $validated['order_number']);
            $return->customer_name = trim((string) ($validated['customer_name'] ?? ''));
            $return->items_count = 0;
            $return->save();

            app(ReturnFeeService::class)->seedReturnFees($return);

            return $return;
        });

        $payload = $this->serializeReturn($return->fresh(['lines', 'clientAccount']));
        if ($return->isAdminCreated()) {
            $payload['admin_return_reasons'] = ReturnReasonOptions::admin();
            $payload['admin_default_return_reason'] = ReturnReasonOptions::adminDefaultKey();
        }

        return response()->json($payload, 201);
    }

    public function show(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->authorizeReturn($request, $clientAccountReturn);

        return response()->json($this->serializeReturn($clientAccountReturn));
    }

    public function update(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->authorizeReturn($request, $clientAccountReturn);
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(ClientAccountReturn::LIST_STATUSES)],
            'return_type' => ['sometimes', 'string', Rule::in(ClientAccountReturn::RETURN_TYPES)],
            'warehouse_private_note' => ['nullable', 'string', 'max:20000'],
        ]);

        if (isset($validated['status'])) {
            $clientAccountReturn->status = $validated['status'];
            $nextStatus = (string) $validated['status'];
            if (in_array($nextStatus, [ClientAccountReturn::STATUS_RECEIVED, ClientAccountReturn::STATUS_COMPLETED], true)) {
                if ($clientAccountReturn->processed_at === null) {
                    $clientAccountReturn->processed_at = now();
                }
            } elseif ($nextStatus === ClientAccountReturn::STATUS_PENDING || $nextStatus === ClientAccountReturn::STATUS_DRAFT) {
                $clientAccountReturn->processed_at = null;
            }
        }
        if (isset($validated['return_type'])) {
            $clientAccountReturn->return_type = $validated['return_type'];
        }
        if (array_key_exists('warehouse_private_note', $validated)) {
            $clientAccountReturn->warehouse_private_note = $validated['warehouse_private_note'];
        }
        $clientAccountReturn->save();

        return response()->json($this->serializeReturn($clientAccountReturn->fresh(['lines', 'clientAccount'])));
    }

    public function updateWarehouseNote(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->authorizeReturn($request, $clientAccountReturn);
        $validated = $request->validate([
            'warehouse_private_note' => ['nullable', 'string', 'max:20000'],
        ]);
        $clientAccountReturn->warehouse_private_note = $validated['warehouse_private_note'] ?? null;
        $clientAccountReturn->save();

        return response()->json($this->serializeReturn($clientAccountReturn->fresh(['lines', 'clientAccount'])));
    }

    public function submit(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->authorizeReturn($request, $clientAccountReturn);
        if ($clientAccountReturn->status !== ClientAccountReturn::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft returns can be submitted.'],
            ]);
        }

        $validated = $request->validate([
            'return_type' => ['sometimes', 'string', Rule::in(ClientAccountReturn::RETURN_TYPES)],
            'warehouse_private_note' => ['nullable', 'string', 'max:20000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:255'],
            'lines.*.name' => ['required', 'string', 'max:512'],
            'lines.*.order_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
            'lines.*.return_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
            'lines.*.shiphero_line_item_id' => ['nullable', 'string', 'max:64'],
            'lines.*.image_url' => ['nullable', 'string', 'max:2048'],
            'lines.*.return_reason' => ['nullable', 'string', 'max:64'],
        ]);

        $normalized = $this->validateAndNormalizeLines(
            $validated['lines'],
            $this->isManualReturn($clientAccountReturn),
        );

        DB::transaction(function () use ($clientAccountReturn, $validated, $normalized) {
            if (isset($validated['return_type'])) {
                $clientAccountReturn->return_type = $validated['return_type'];
            }
            if (array_key_exists('warehouse_private_note', $validated)) {
                $clientAccountReturn->warehouse_private_note = $validated['warehouse_private_note'];
            }
            $this->persistLines($clientAccountReturn, $normalized);
            $clientAccountReturn->status = ClientAccountReturn::STATUS_PENDING;
            $clientAccountReturn->processed_at = null;
            $clientAccountReturn->save();
            app(ReturnFeeService::class)->seedReturnFees($clientAccountReturn);
        });

        return response()->json($this->serializeReturn($clientAccountReturn->fresh(['lines', 'clientAccount'])));
    }

    public function destroy(Request $request, ClientAccountReturn $clientAccountReturn): JsonResponse
    {
        $this->authorizeReturn($request, $clientAccountReturn);
        if (! in_array($clientAccountReturn->status, [ClientAccountReturn::STATUS_DRAFT, ClientAccountReturn::STATUS_PENDING], true)) {
            return response()->json(['message' => 'Only draft or pending returns can be deleted.'], 422);
        }
        $clientAccountReturn->delete();

        return response()->json(['ok' => true]);
    }

    public function packingSlipPdf(Request $request, ClientAccountReturn $clientAccountReturn)
    {
        $this->authorizeReturn($request, $clientAccountReturn);
        $clientAccountReturn->loadMissing(['lines', 'clientAccount']);
        $lines = $clientAccountReturn->lines->filter(fn (ClientAccountReturnLine $l) => (int) $l->return_qty > 0);
        $accountName = $clientAccountReturn->clientAccount !== null
            ? trim((string) $clientAccountReturn->clientAccount->company_name)
            : 'Save Rack';

        $pdf = Pdf::loadView('pdf.returns.packing-slip', [
            'return' => $clientAccountReturn,
            'lines' => $lines,
            'accountName' => $accountName !== '' ? $accountName : 'Save Rack',
            'rmaLabel' => $this->formatRmaLabel($clientAccountReturn->rma_number),
            'barcodeSvg' => Code128Svg::dataUri($clientAccountReturn->rma_number),
        ])->setPaper('letter');

        return $pdf->stream('return-'.$this->safePdfName($clientAccountReturn->rma_number).'-packing-slip.pdf');
    }

    public function shippingLabelPdf(Request $request, ClientAccountReturn $clientAccountReturn)
    {
        $this->authorizeReturn($request, $clientAccountReturn);
        $clientAccountReturn->loadMissing('clientAccount');
        $accountName = $clientAccountReturn->clientAccount !== null
            ? trim((string) $clientAccountReturn->clientAccount->company_name)
            : 'Save Rack';
        $addr = config('returns.return_warehouse_address', []);

        $pdf = Pdf::loadView('pdf.returns.shipping-label', [
            'return' => $clientAccountReturn,
            'accountName' => $accountName !== '' ? $accountName : 'Save Rack',
            'rmaLabel' => $this->formatRmaLabel($clientAccountReturn->rma_number),
            'addressLines' => array_values(array_filter([
                $addr['line1'] ?? null,
                $addr['line2'] ?? null,
            ])),
            'barcodeSvg' => Code128Svg::dataUri($clientAccountReturn->rma_number),
        ])->setPaper([0, 0, 288, 432]);

        return $pdf->stream('return-'.$this->safePdfName($clientAccountReturn->rma_number).'-shipping-label.pdf');
    }

    public function rmaBarcodePdf(Request $request, ClientAccountReturn $clientAccountReturn)
    {
        $this->authorizeReturn($request, $clientAccountReturn);
        $pdf = Pdf::loadView('pdf.returns.rma-barcode', [
            'rmaNumber' => $clientAccountReturn->rma_number,
            'barcodeSvg' => Code128Svg::dataUri($clientAccountReturn->rma_number),
        ])->setPaper([0, 0, 288, 108]);

        return $pdf->stream('return-'.$this->safePdfName($clientAccountReturn->rma_number).'-barcode.pdf');
    }
}
