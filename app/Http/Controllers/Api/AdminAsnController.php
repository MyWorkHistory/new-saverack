<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountAsnLine;
use App\Models\ClientAccountAsnTracking;
use App\Models\AsnBill;
use App\Models\AsnBillItem;
use App\Models\User;
use App\Services\AsnBillService;
use App\Services\AsnReceivingService;
use App\Services\OrderDashboardSnapshotService;
use App\Support\Billing\AsnBillChargeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAsnController extends Controller
{
    /** @var AsnReceivingService */
    private $receiving;

    /** @var AsnBillService */
    private $asnBills;

    /** @var OrderDashboardSnapshotService */
    private $orderDashboardSnapshots;

    public function __construct(
        AsnReceivingService $receiving,
        AsnBillService $asnBills,
        OrderDashboardSnapshotService $orderDashboardSnapshots
    ) {
        $this->receiving = $receiving;
        $this->asnBills = $asnBills;
        $this->orderDashboardSnapshots = $orderDashboardSnapshots;
    }

    private function assertStaff(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        if ((int) ($user->client_account_id ?? 0) > 0) {
            abort(403, 'Admin ASN endpoints are for staff only.');
        }
    }

    private function authorizeAsn(Request $request, ClientAccountAsn $asn): void
    {
        Gate::forUser($request->user())->authorize('view', $asn);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(ClientAccountAsn $asn): array
    {
        $asn->loadMissing(['trackings', 'clientAccount']);
        $trackings = $asn->trackings->map(fn (ClientAccountAsnTracking $t) => [
            'carrier' => $t->carrier,
            'tracking_number' => trim($t->tracking_number),
        ])->filter(fn ($t) => $t['tracking_number'] !== '')->values();
        $first = $trackings->first();

        return [
            'id' => $asn->id,
            'client_account_id' => $asn->client_account_id,
            'client_account_company_name' => $asn->clientAccount
                ? trim((string) $asn->clientAccount->company_name)
                : '',
            'asn_number' => $asn->asn_number,
            'status' => $asn->status,
            'created_at' => optional($asn->created_at)->toIso8601String(),
            'processed_at' => optional($asn->processed_at)->toIso8601String(),
            'expected_qty' => $asn->expected_qty,
            'accepted_qty' => $asn->accepted_qty,
            'rejected_qty' => $asn->rejected_qty,
            'total_boxes' => $asn->total_boxes,
            'total_pallets' => $asn->total_pallets,
            'tracking_display' => $first['tracking_number'] ?? '',
            'tracking_carrier' => $first['carrier'] ?? '',
            'trackings' => $trackings->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAsn(ClientAccountAsn $asn): array
    {
        $asn->loadMissing(['lines', 'trackings', 'vendorLines', 'clientAccount.feeItems', 'processedBy']);

        $companyName = $asn->clientAccount !== null
            ? trim((string) $asn->clientAccount->company_name)
            : '';
        $processedByName = $asn->processedBy !== null
            ? trim((string) $asn->processedBy->name)
            : '';

        return [
            'id' => $asn->id,
            'client_account_id' => $asn->client_account_id,
            'client_account_company_name' => $companyName,
            'asn_number' => $asn->asn_number,
            'status' => $asn->status,
            'date_received' => optional($asn->date_received)->toDateString(),
            'processed_at' => optional($asn->processed_at)->toIso8601String(),
            'processed_by_name' => $processedByName !== '' ? $processedByName : null,
            'total_boxes' => $asn->total_boxes,
            'total_pallets' => $asn->total_pallets,
            'expected_qty' => $asn->expected_qty,
            'accepted_qty' => $asn->accepted_qty,
            'rejected_qty' => $asn->rejected_qty,
            'warehouse_notes' => $asn->warehouse_notes,
            'non_compliant_fee' => $asn->non_compliant_fee,
            'custom_bill_id' => $asn->custom_bill_id,
            'asn_bill_id' => $asn->asn_bill_id,
            'asn_bill_lines' => $this->asnBills->linesForAsn($asn),
            'asn_bill_charge_options' => $asn->clientAccount
                ? AsnBillChargeCatalog::optionsForAccount($asn->clientAccount)
                : [],
            'created_at' => optional($asn->created_at)->toIso8601String(),
            'updated_at' => optional($asn->updated_at)->toIso8601String(),
            'lines' => $asn->lines->map(fn (ClientAccountAsnLine $l) => $this->receiving->serializeLine($l))->values()->all(),
            'trackings' => $asn->trackings->map(fn (ClientAccountAsnTracking $t) => [
                'id' => $t->id,
                'carrier' => $t->carrier,
                'tracking_number' => $t->tracking_number,
                'sort_order' => $t->sort_order,
            ])->values()->all(),
            'vendor_lines' => $asn->vendorLines->map(fn ($v) => [
                'id' => $v->id,
                'label' => $v->label,
                'sort_order' => $v->sort_order,
            ])->values()->all(),
        ];
    }

    public function summary(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountAsn::class);

        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);
        $accountId = isset($validated['client_account_id']) ? (int) $validated['client_account_id'] : null;

        return response()->json($this->receiving->statusSummary($accountId));
    }

    public function chargeOptions(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountAsn::class);

        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);
        $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
        Gate::authorize('view', $account);
        $account->loadMissing(['feeItems.pricingTemplate']);

        return response()->json([
            'charge_options' => AsnBillChargeCatalog::optionsForAccount($account),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountAsn::class);

        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'status' => ['nullable', 'string', Rule::in(ClientAccountAsn::STATUSES)],
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', Rule::in([
                'status',
                'asn_number',
                'created_at',
                'expected_qty',
                'accepted_qty',
                'rejected_qty',
                'total_boxes',
            ])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $q = isset($validated['q']) ? trim((string) $validated['q']) : '';
        $perPage = (int) ($validated['per_page'] ?? 25);
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDir = strtolower((string) ($validated['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = ClientAccountAsn::query()
            ->with(['trackings', 'clientAccount']);

        if (! empty($validated['client_account_id'])) {
            $query->where('client_account_id', (int) $validated['client_account_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('asn_number', 'like', $like)
                    ->orWhereHas('trackings', function ($t) use ($like, $q) {
                        $t->where('tracking_number', 'like', $like)
                            ->orWhere('tracking_number', 'like', '%'.$q.'%');
                    });
            });
        }

        $query->orderBy($sortBy, $sortDir)->orderBy('id', $sortDir);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ClientAccountAsn $a) => $this->serializeListRow($a))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);

        return response()->json($this->serializeAsn($asn));
    }

    public function updateStatus(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ClientAccountAsn::STATUSES)],
        ]);
        $asn->status = $validated['status'];
        $asn->save();

        $this->orderDashboardSnapshots->patchAccountAsnPending((int) $asn->client_account_id);

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount'])));
    }

    public function enrichSpecs(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);

        $force = $request->boolean('force');
        $result = $this->receiving->enrichLineSpecs($asn, $force);

        return response()->json(array_merge(
            $result,
            ['asn' => $this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount']))]
        ));
    }

    public function receiveLine(Request $request, ClientAccountAsn $asn, ClientAccountAsnLine $line): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);
        $this->assertLineBelongs($asn, $line);

        $validated = $request->validate([
            'delta' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        $asn->loadMissing('clientAccount');
        $updated = $this->receiving->receiveIncrement($asn, $line, (int) $validated['delta'], $request->user());

        return response()->json([
            'line' => $this->receiving->serializeLine($updated),
            'asn' => $this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount'])),
        ]);
    }

    public function receiveOverride(Request $request, ClientAccountAsn $asn, ClientAccountAsnLine $line): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);
        $this->assertLineBelongs($asn, $line);

        $validated = $request->validate([
            'accepted_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
        ]);

        $asn->loadMissing('clientAccount');
        $updated = $this->receiving->receiveOverride($asn, $line, (int) $validated['accepted_qty'], $request->user());

        return response()->json([
            'line' => $this->receiving->serializeLine($updated),
            'receiving_on_hand' => $this->receiving->receivingOnHandForSku(
                $asn->clientAccount,
                (string) $line->sku
            ),
            'asn' => $this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount'])),
        ]);
    }

    public function rejectOverride(Request $request, ClientAccountAsn $asn, ClientAccountAsnLine $line): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);
        $this->assertLineBelongs($asn, $line);

        $validated = $request->validate([
            'rejected_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
        ]);

        $updated = $this->receiving->rejectOverride($line, (int) $validated['rejected_qty']);

        return response()->json([
            'line' => $this->receiving->serializeLine($updated),
            'asn' => $this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount'])),
        ]);
    }

    public function updateLineSpecs(Request $request, ClientAccountAsn $asn, ClientAccountAsnLine $line): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);
        $this->assertLineBelongs($asn, $line);

        $validated = $request->validate([
            'barcode' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
        ]);

        $asn->loadMissing('clientAccount');

        $updated = $this->receiving->updateLineSpecs($asn->clientAccount, $line, $validated);

        return response()->json($this->receiving->serializeLine($updated));
    }

    public function receivingOnHand(Request $request, ClientAccountAsn $asn, ClientAccountAsnLine $line): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);
        $this->assertLineBelongs($asn, $line);
        $asn->loadMissing('clientAccount');

        return response()->json([
            'receiving_on_hand' => $this->receiving->receivingOnHandForSku(
                $asn->clientAccount,
                (string) $line->sku
            ),
            'accepted_qty' => (int) $line->accepted_qty,
        ]);
    }

    public function scanBarcodes(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);

        $validated = $request->validate([
            'barcodes' => ['required', 'string', 'max:500000'],
        ]);
        $lines = preg_split('/\r\n|\r|\n/', (string) $validated['barcodes']) ?: [];
        $asn->loadMissing('clientAccount');
        $result = $this->receiving->scanBarcodes($asn, $lines, $request->user());

        return response()->json(array_merge(
            $result,
            ['asn' => $this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount']))]
        ));
    }

    public function storeNonCompliant(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountAsn::class);

        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'total_boxes' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'total_pallets' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'trackings' => ['required', 'array', 'min:1'],
            'trackings.*.carrier' => ['nullable', 'string', 'max:128'],
            'trackings.*.tracking_number' => ['required', 'string', 'max:255'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['sometimes', 'array'],
            'lines.*.shiphero_product_id' => ['nullable', 'string', 'max:191'],
            'lines.*.shiphero_legacy_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:255'],
            'lines.*.name' => ['required', 'string', 'max:512'],
            'lines.*.image_url' => ['nullable', 'string', 'max:2048'],
            'lines.*.expected_qty' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);

        $clientAccountId = (int) $validated['client_account_id'];
        $account = ClientAccount::query()->findOrFail($clientAccountId);
        Gate::authorize('view', $account);
        $account->loadMissing(['feeItems.pricingTemplate']);

        $boxes = (int) ($validated['total_boxes'] ?? 0);
        $pallets = (int) ($validated['total_pallets'] ?? 0);
        if ($boxes <= 0 && $pallets <= 0) {
            throw ValidationException::withMessages([
                'total_boxes' => ['Enter total boxes or pallets.'],
            ]);
        }

        if (array_key_exists('fee', $validated)) {
            $fee = round((float) $validated['fee'], 2);
        } else {
            $defaultFeeCents = AsnBillChargeCatalog::defaultUnitPriceCents($account, AsnBill::LINE_NON_COMPLIANT);
            $fee = round($defaultFeeCents / 100, 2);
        }

        $asn = DB::transaction(function () use ($request, $account, $clientAccountId, $boxes, $pallets, $validated, $fee) {
            $asn = new ClientAccountAsn;
            $asn->client_account_id = $clientAccountId;
            $asn->asn_number = 'TMP';
            $asn->status = ClientAccountAsn::STATUS_NON_COMPLIANT;
            $asn->total_boxes = $boxes;
            $asn->total_pallets = $pallets;
            $asn->non_compliant_fee = $fee > 0 ? $fee : null;
            $asn->save();
            $asn->asn_number = AsnReceivingService::nextAsnNumber();
            $asn->save();

            $order = 0;
            foreach ($validated['trackings'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $t = new ClientAccountAsnTracking;
                $t->client_account_asn_id = $asn->id;
                $t->carrier = trim((string) ($row['carrier'] ?? ''));
                $t->tracking_number = trim((string) ($row['tracking_number'] ?? ''));
                $t->sort_order = $order++;
                $t->save();
            }

            $lineOrder = 0;
            foreach ($validated['lines'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->createNonCompliantLine($asn, $row, $lineOrder++);
            }
            if ($lineOrder > 0) {
                $this->receiving->recalcAsnAggregates($asn->fresh());
            }

            if ($fee > 0) {
                Gate::authorize('create', AsnBill::class);
                $asn->refresh();
                $feeCents = (int) round($fee * 100);
                $bill = $this->asnBills->findOrCreateOpenBillForAsn($asn, $request->user());
                $this->asnBills->addItem($bill, [
                    'line_type' => AsnBill::LINE_NON_COMPLIANT,
                    'name' => AsnReceivingService::nonCompliantBillItemName($asn),
                    'quantity' => 1,
                    'unit_price_cents' => $feeCents,
                ], $request->user());
            }

            return $asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount']);
        });

        return response()->json($this->serializeAsn($asn), 201);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createNonCompliantLine(ClientAccountAsn $asn, array $row, int $sortOrder): void
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '' && $sku !== '') {
            $name = $sku;
        }

        $line = new ClientAccountAsnLine;
        $line->client_account_asn_id = $asn->id;
        $line->shiphero_product_id = isset($row['shiphero_product_id'])
            ? trim((string) $row['shiphero_product_id'])
            : null;
        if ($line->shiphero_product_id === '') {
            $line->shiphero_product_id = null;
        }
        if (isset($row['shiphero_legacy_id']) && (int) $row['shiphero_legacy_id'] > 0) {
            $line->shiphero_legacy_id = (int) $row['shiphero_legacy_id'];
        }
        $line->sku = $sku;
        $line->name = $name;
        $line->image_url = isset($row['image_url']) ? trim((string) $row['image_url']) : null;
        if ($line->image_url === '') {
            $line->image_url = null;
        }
        $line->expected_qty = (int) ($row['expected_qty'] ?? 1);
        $line->accepted_qty = 0;
        $line->rejected_qty = 0;
        $line->sort_order = $sortOrder;
        $line->save();
    }

    public function storeBillItem(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);

        $validated = $request->validate([
            'line_type' => ['required', 'string', Rule::in([
                AsnBill::LINE_RECEIVING_PER_BOX,
                AsnBill::LINE_RECEIVING_PER_PALLET,
                AsnBill::LINE_RECEIVING_PER_ITEM,
                AsnBill::LINE_CUSTOM_HOURLY_WORK,
                AsnBill::LINE_NON_COMPLIANT,
            ])],
            'name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_price_cents' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->asnBills->addItemForAsn($asn, $validated, $request->user());

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount'])));
    }

    public function updateBillItem(Request $request, ClientAccountAsn $asn, AsnBillItem $item): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);
        $this->assertBillItemBelongs($asn, $item);

        $bill = AsnBill::query()->findOrFail($asn->asn_bill_id);

        $validated = $request->validate([
            'line_type' => ['required', 'string', Rule::in([
                AsnBill::LINE_RECEIVING_PER_BOX,
                AsnBill::LINE_RECEIVING_PER_PALLET,
                AsnBill::LINE_RECEIVING_PER_ITEM,
                AsnBill::LINE_CUSTOM_HOURLY_WORK,
                AsnBill::LINE_NON_COMPLIANT,
            ])],
            'name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_price_cents' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->asnBills->updateItem($bill, $item, $validated, $request->user());

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount'])));
    }

    public function destroyBillItem(Request $request, ClientAccountAsn $asn, AsnBillItem $item): JsonResponse
    {
        $this->assertStaff($request);
        $this->authorizeAsn($request, $asn);
        $this->assertBillItemBelongs($asn, $item);

        $bill = AsnBill::query()->findOrFail($asn->asn_bill_id);

        $this->asnBills->deleteItem($bill, $item, $request->user());

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount'])));
    }

    private function assertBillItemBelongs(ClientAccountAsn $asn, AsnBillItem $item): void
    {
        if ($asn->asn_bill_id === null || (int) $item->asn_bill_id !== (int) $asn->asn_bill_id) {
            abort(404);
        }
    }

    private function assertLineBelongs(ClientAccountAsn $asn, ClientAccountAsnLine $line): void
    {
        if ((int) $line->client_account_asn_id !== (int) $asn->id) {
            abort(404);
        }
    }
}
