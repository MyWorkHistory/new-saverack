<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountAsnLine;
use App\Models\ClientAccountAsnTracking;
use App\Models\CustomBill;
use App\Models\PricingFeeTemplate;
use App\Models\User;
use App\Services\AsnReceivingService;
use App\Services\CustomBillService;
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

    /** @var CustomBillService */
    private $customBills;

    public function __construct(AsnReceivingService $receiving, CustomBillService $customBills)
    {
        $this->receiving = $receiving;
        $this->customBills = $customBills;
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
        $asn->loadMissing(['lines', 'trackings', 'vendorLines', 'clientAccount', 'processedBy']);

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
            'receiving_fees' => $this->receivingFeeRows(),
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

    /**
     * @return list<array<string, mixed>>
     */
    private function receivingFeeRows(): array
    {
        return PricingFeeTemplate::query()
            ->where('category', PricingFeeTemplate::CATEGORY_RECEIVING)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (PricingFeeTemplate $template) {
                return [
                    'id' => $template->id,
                    'name' => $template->name,
                    'description' => $template->description,
                    'amount' => 0.0,
                ];
            })
            ->values()
            ->all();
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

        $updated = $this->receiving->updateLineSpecs($line, $validated);

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
        ]);

        $clientAccountId = (int) $validated['client_account_id'];
        $account = ClientAccount::query()->findOrFail($clientAccountId);
        Gate::authorize('view', $account);

        $boxes = (int) ($validated['total_boxes'] ?? 0);
        $pallets = (int) ($validated['total_pallets'] ?? 0);
        if ($boxes <= 0 && $pallets <= 0) {
            throw ValidationException::withMessages([
                'total_boxes' => ['Enter total boxes or pallets.'],
            ]);
        }

        $fee = isset($validated['fee']) ? round((float) $validated['fee'], 2) : 0.0;

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

            if ($fee > 0) {
                Gate::authorize('create', CustomBill::class);
                $feeCents = (int) round($fee * 100);
                $bill = $this->customBills->create(
                    [
                        'client_account_id' => $clientAccountId,
                        'bill_date' => now()->toDateString(),
                    ],
                    [
                        [
                            'line_type' => AsnReceivingService::receivingBillLineType(),
                            'name' => AsnReceivingService::nonCompliantBillItemName($asn),
                            'quantity' => 1,
                            'unit_price_cents' => $feeCents,
                            'sku' => null,
                        ],
                    ],
                    $request->user()
                );
                $asn->custom_bill_id = $bill->id;
                $asn->save();
            }

            return $asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount']);
        });

        return response()->json($this->serializeAsn($asn), 201);
    }

    private function assertLineBelongs(ClientAccountAsn $asn, ClientAccountAsnLine $line): void
    {
        if ((int) $line->client_account_asn_id !== (int) $asn->id) {
            abort(404);
        }
    }
}
