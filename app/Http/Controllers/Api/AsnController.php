<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountAsnLine;
use App\Models\ClientAccountAsnTracking;
use App\Models\ClientAccountAsnVendorLine;
use App\Models\User;
use App\Services\ShipHeroInventoryService;
use App\Support\Code128Barcode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AsnController extends Controller
{
    /** @var ShipHeroInventoryService */
    private $inventory;

    public function __construct(ShipHeroInventoryService $inventory)
    {
        $this->inventory = $inventory;
    }

    private function isPortalUser(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return false;
        }

        return (int) ($user->client_account_id ?? 0) > 0;
    }

    private function nextAsnNumberForAccount(int $clientAccountId): string
    {
        $max = 0;
        $numbers = ClientAccountAsn::query()
            ->where('client_account_id', $clientAccountId)
            ->pluck('asn_number');
        foreach ($numbers as $raw) {
            $s = trim((string) $raw);
            if ($s === '' || $s === 'TMP') {
                continue;
            }
            if (preg_match('/^(\d{1,4})$/', $s, $m)) {
                $max = max($max, (int) $m[1]);

                continue;
            }
            if (preg_match('/(\d+)$/', $s, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function assertDeletableStatus(ClientAccountAsn $asn): void
    {
        if (! in_array($asn->status, ClientAccountAsn::DELETABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or pending ASNs can be deleted.'],
            ]);
        }
    }

    private function formatAsnLabel($raw): string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return '';
        }
        $stripped = preg_replace('/^ASN[#\s-]*/i', '', $s);
        $stripped = trim((string) $stripped);

        return $stripped !== '' ? 'ASN #'.$stripped : 'ASN #'.$s;
    }

    private function safePdfName($raw): string
    {
        $s = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string) $raw));
        $s = trim((string) $s, '-');

        return $s !== '' ? $s : 'document';
    }

    private function code128SvgDataUri(string $value): string
    {
        return Code128Barcode::svgDataUri($value);
    }

    /**
     * @param  array<int, array<string, mixed>>  $trackings
     */
    private function persistTrackings(ClientAccountAsn $asn, array $trackings): void
    {
        ClientAccountAsnTracking::query()->where('client_account_asn_id', $asn->id)->delete();
        $order = 0;
        foreach ($trackings as $row) {
            if (! is_array($row)) {
                continue;
            }
            $carrier = trim((string) ($row['carrier'] ?? ''));
            $num = trim((string) ($row['tracking_number'] ?? ''));
            if ($carrier === '' && $num === '') {
                continue;
            }
            $t = new ClientAccountAsnTracking;
            $t->client_account_asn_id = $asn->id;
            $t->carrier = $carrier;
            $t->tracking_number = $num;
            $t->sort_order = $order++;
            $t->save();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizedTrackingRows(array $trackings): array
    {
        $out = [];
        foreach ($trackings as $row) {
            if (! is_array($row)) {
                continue;
            }
            $carrier = trim((string) ($row['carrier'] ?? ''));
            $num = trim((string) ($row['tracking_number'] ?? ''));
            if ($carrier === '' && $num === '') {
                continue;
            }
            $out[] = ['carrier' => $carrier, 'tracking_number' => $num];
        }

        return $out;
    }
    private function resolveShipHeroCustomerAccountId(int $clientAccountId, Request $request): string
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
                    'This client account has no ShipHero customer account ID.',
                ],
            ]);
        }

        return trim($sid);
    }

    /** @noinspection PhpSameParameterValueInspection */
    private function authorizeAsn(Request $request, ClientAccountAsn $asn): void
    {
        Gate::forUser($request->user())->authorize('view', $asn);
    }

    private function recalcLineAggregates(ClientAccountAsn $asn): void
    {
        $sums = ClientAccountAsnLine::query()
            ->where('client_account_asn_id', $asn->id)
            ->selectRaw('COALESCE(SUM(expected_qty),0) as e, COALESCE(SUM(accepted_qty),0) as a, COALESCE(SUM(rejected_qty),0) as r')
            ->first();
        $asn->expected_qty = (int) ($sums->e ?? 0);
        $asn->accepted_qty = (int) ($sums->a ?? 0);
        $asn->rejected_qty = (int) ($sums->r ?? 0);
        $asn->saveQuietly();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAsn(ClientAccountAsn $asn): array
    {
        $asn->loadMissing(['lines', 'trackings', 'vendorLines', 'clientAccount']);

        $companyName = $asn->clientAccount !== null
            ? trim((string) $asn->clientAccount->company_name)
            : '';

        return [
            'id' => $asn->id,
            'client_account_id' => $asn->client_account_id,
            'client_account_company_name' => $companyName,
            'asn_number' => $asn->asn_number,
            'status' => $asn->status,
            'date_received' => optional($asn->date_received)->toDateString(),
            'total_boxes' => $asn->total_boxes,
            'total_pallets' => $asn->total_pallets,
            'expected_qty' => $asn->expected_qty,
            'accepted_qty' => $asn->accepted_qty,
            'rejected_qty' => $asn->rejected_qty,
            'warehouse_notes' => $asn->warehouse_notes,
            'created_at' => optional($asn->created_at)->toIso8601String(),
            'updated_at' => optional($asn->updated_at)->toIso8601String(),
            'lines' => $asn->lines->map(fn (ClientAccountAsnLine $l) => $this->serializeLine($l))->values()->all(),
            'trackings' => $asn->trackings->map(fn (ClientAccountAsnTracking $t) => [
                'id' => $t->id,
                'carrier' => $t->carrier,
                'tracking_number' => $t->tracking_number,
                'sort_order' => $t->sort_order,
            ])->values()->all(),
            'vendor_lines' => $asn->vendorLines->map(fn (ClientAccountAsnVendorLine $v) => [
                'id' => $v->id,
                'label' => $v->label,
                'sort_order' => $v->sort_order,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLine(ClientAccountAsnLine $line): array
    {
        return [
            'id' => $line->id,
            'shiphero_product_id' => $line->shiphero_product_id,
            'sku' => $line->sku,
            'name' => $line->name,
            'image_url' => $line->image_url,
            'expected_qty' => $line->expected_qty,
            'accepted_qty' => $line->accepted_qty,
            'rejected_qty' => $line->rejected_qty,
            'sort_order' => $line->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(ClientAccountAsn $asn): array
    {
        $asn->loadMissing('trackings');
        $trackings = $asn->trackings->map(fn (ClientAccountAsnTracking $t) => trim($t->tracking_number))->filter()->values();
        $trackingDisplay = $trackings->isEmpty() ? '' : ($trackings->count() > 1 ? $trackings->first().' +'.($trackings->count() - 1) : $trackings->first());

        return [
            'id' => $asn->id,
            'client_account_id' => $asn->client_account_id,
            'asn_number' => $asn->asn_number,
            'status' => $asn->status,
            'created_at' => optional($asn->created_at)->toIso8601String(),
            'expected_qty' => $asn->expected_qty,
            'accepted_qty' => $asn->accepted_qty,
            'rejected_qty' => $asn->rejected_qty,
            'total_boxes' => $asn->total_boxes,
            'tracking_display' => $trackingDisplay,
        ];
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
                'asn_number',
                'created_at',
                'expected_qty',
                'accepted_qty',
                'rejected_qty',
                'total_boxes',
            ])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);
        $clientAccountId = (int) $validated['client_account_id'];
        Gate::authorize('view', ClientAccount::query()->findOrFail($clientAccountId));
        $q = isset($validated['q']) ? trim((string) $validated['q']) : '';
        $perPage = (int) ($validated['per_page'] ?? 25);
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDir = strtolower((string) ($validated['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = ClientAccountAsn::query()
            ->where('client_account_id', $clientAccountId)
            ->with('trackings');
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like) {
                $w->where('asn_number', 'like', $like)
                    ->orWhereHas('trackings', function ($t) use ($like) {
                        $t->where('tracking_number', 'like', $like);
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);
        $clientAccountId = (int) $validated['client_account_id'];
        $account = ClientAccount::query()->findOrFail($clientAccountId);
        Gate::authorize('view', $account);

        $asn = DB::transaction(function () use ($clientAccountId, $account) {
            $asn = new ClientAccountAsn;
            $asn->client_account_id = $clientAccountId;
            $asn->asn_number = 'TMP';
            $asn->status = ClientAccountAsn::STATUS_DRAFT;
            $asn->save();
            $asn->asn_number = $this->nextAsnNumberForAccount($clientAccountId);
            $asn->save();

            $vendor = new ClientAccountAsnVendorLine;
            $vendor->client_account_asn_id = $asn->id;
            $vendor->label = (string) $account->company_name;
            $vendor->sort_order = 0;
            $vendor->save();

            return $asn->fresh(['lines', 'trackings', 'vendorLines']);
        });

        return response()->json($this->serializeAsn($asn), 201);
    }

    public function show(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->authorizeAsn($request, $asn);

        return response()->json($this->serializeAsn($asn));
    }

    public function update(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(ClientAccountAsn::STATUSES)],
            'date_received' => ['nullable', 'string', 'max:32'],
            'total_boxes' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'total_pallets' => ['sometimes', 'integer', 'min:0', 'max:999999'],
        ]);
        if ($this->isPortalUser($request)) {
            if (array_key_exists('status', $validated)) {
                throw ValidationException::withMessages([
                    'status' => ['Status cannot be changed directly. Use Mark as Ready.'],
                ]);
            }
            if (array_key_exists('date_received', $validated)) {
                throw ValidationException::withMessages([
                    'date_received' => ['Date received is managed by warehouse staff.'],
                ]);
            }
        } else {
            if (isset($validated['status'])) {
                $asn->status = $validated['status'];
            }
            if (array_key_exists('date_received', $validated)) {
                $dr = $validated['date_received'];
                $asn->date_received = ($dr === null || $dr === '') ? null : $dr;
            }
        }
        if (isset($validated['total_boxes'])) {
            $asn->total_boxes = (int) $validated['total_boxes'];
        }
        if (isset($validated['total_pallets'])) {
            $asn->total_pallets = (int) $validated['total_pallets'];
        }
        $asn->save();

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines'])));
    }

    public function updateWarehouseNotes(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        $validated = $request->validate([
            'warehouse_notes' => ['nullable', 'string', 'max:20000'],
        ]);
        $asn->warehouse_notes = $validated['warehouse_notes'] ?? null;
        $asn->save();

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines'])));
    }

    public function destroy(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        try {
            $this->assertDeletableStatus($asn);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Only draft or pending ASNs can be deleted.'], 422);
        }
        $asn->delete();

        return response()->json(['ok' => true]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        $clientAccountId = (int) $validated['client_account_id'];
        Gate::authorize('view', ClientAccount::query()->findOrFail($clientAccountId));
        $ids = array_map('intval', $validated['ids']);
        $asns = ClientAccountAsn::query()
            ->where('client_account_id', $clientAccountId)
            ->whereIn('id', $ids)
            ->get();
        foreach ($asns as $asn) {
            Gate::authorize('delete', $asn);
            try {
                $this->assertDeletableStatus($asn);
            } catch (ValidationException $e) {
                return response()->json(['message' => 'Only draft or pending ASNs can be deleted.'], 422);
            }
        }
        ClientAccountAsn::query()
            ->where('client_account_id', $clientAccountId)
            ->whereIn('id', $ids)
            ->delete();

        return response()->json(['ok' => true, 'deleted' => count($ids)]);
    }

    public function markReady(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        if ($asn->status !== ClientAccountAsn::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft ASNs can be marked as ready.'], 422);
        }

        $validated = $request->validate([
            'tracking_mode' => ['required', 'string', Rule::in(['entered', 'update_later', 'id_label'])],
            'trackings' => ['sometimes', 'array'],
            'trackings.*.carrier' => ['nullable', 'string', 'max:128'],
            'trackings.*.tracking_number' => ['nullable', 'string', 'max:255'],
            'total_boxes' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'total_pallets' => ['sometimes', 'integer', 'min:0', 'max:999999'],
        ]);

        if (isset($validated['total_boxes'])) {
            $asn->total_boxes = (int) $validated['total_boxes'];
        }
        if (isset($validated['total_pallets'])) {
            $asn->total_pallets = (int) $validated['total_pallets'];
        }

        if ($asn->total_boxes <= 0 && $asn->total_pallets <= 0) {
            throw ValidationException::withMessages([
                'total_boxes' => ['Please enter total number of boxes or pallets being shipped in this ASN.'],
            ]);
        }

        $mode = (string) $validated['tracking_mode'];
        $trackingRows = $this->normalizedTrackingRows($validated['trackings'] ?? []);

        if ($mode === 'entered') {
            if ($trackingRows === []) {
                throw ValidationException::withMessages([
                    'trackings' => [
                        'Tracking # is required. All ASNs must include either a valid tracking number or an ASN identification label attached to each box.',
                    ],
                ]);
            }
            DB::transaction(function () use ($asn, $trackingRows) {
                $this->persistTrackings($asn, $trackingRows);
            });
        } elseif ($mode === 'update_later' || $mode === 'id_label') {
            // No tracking required; optional existing rows remain.
        }

        $asn->status = ClientAccountAsn::STATUS_PENDING;
        $asn->save();

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines', 'clientAccount'])));
    }

    public function packingSlipPdf(Request $request, ClientAccountAsn $asn)
    {
        $this->authorizeAsn($request, $asn);
        $asn->loadMissing(['lines', 'clientAccount']);
        $pdf = Pdf::loadView('pdf.asn.packing-slip', [
            'asn' => $asn,
            'accountName' => $asn->clientAccount !== null ? trim((string) $asn->clientAccount->company_name) : 'Save Rack',
            'asnLabel' => $this->formatAsnLabel($asn->asn_number),
        ])->setPaper('letter');

        return $pdf->stream('asn-'.$this->safePdfName($asn->asn_number).'-packing-slip.pdf');
    }

    public function identificationLabelPdf(Request $request, ClientAccountAsn $asn)
    {
        $this->authorizeAsn($request, $asn);
        $asn->loadMissing('clientAccount');
        $pdf = Pdf::loadView('pdf.asn.identification-label', [
            'asn' => $asn,
            'accountName' => $asn->clientAccount !== null ? trim((string) $asn->clientAccount->company_name) : 'Save Rack',
            'asnLabel' => $this->formatAsnLabel($asn->asn_number),
            'addressLines' => ['3135 Drane Field Rd #20', 'Lakeland, FL 33811'],
        ])->setPaper([0, 0, 288, 432]);

        return $pdf->stream('asn-'.$this->safePdfName($asn->asn_number).'-identification-label.pdf');
    }

    public function barcodePdf(Request $request, ClientAccountAsn $asn, ClientAccountAsnLine $line)
    {
        $this->authorizeAsn($request, $asn);
        if ((int) $line->client_account_asn_id !== (int) $asn->id) {
            abort(404);
        }
        $asn->loadMissing('clientAccount');
        $customerId = $asn->clientAccount !== null ? trim((string) $asn->clientAccount->shiphero_customer_account_id) : '';
        $product = $this->inventory->getProductDetailBySku(
            (string) $line->sku,
            null,
            $customerId !== '' ? $customerId : null
        );
        $barcode = is_array($product) && isset($product['barcode']) ? trim((string) $product['barcode']) : '';
        if ($barcode === '') {
            return response()->json(['message' => 'No barcode on file for this SKU in ShipHero.'], 422);
        }

        $pdf = Pdf::loadView('pdf.asn.barcode', [
            'asn' => $asn,
            'line' => $line,
            'barcode' => $barcode,
            'barcodeSvg' => $this->code128SvgDataUri($barcode),
        ])->setPaper([0, 0, 288, 144]);

        return $pdf->stream('asn-'.$this->safePdfName($asn->asn_number).'-'.$this->safePdfName($line->sku).'-barcode.pdf');
    }

    public function storeLine(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        $validated = $request->validate([
            'shiphero_product_id' => ['nullable', 'string', 'max:191'],
            'sku' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:512'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'expected_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
            'accepted_qty' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
            'rejected_qty' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
        ]);
        $portal = $this->isPortalUser($request);
        $maxSort = (int) ClientAccountAsnLine::query()->where('client_account_asn_id', $asn->id)->max('sort_order');
        $line = new ClientAccountAsnLine;
        $line->client_account_asn_id = $asn->id;
        $line->shiphero_product_id = $validated['shiphero_product_id'] ?? null;
        $line->sku = $validated['sku'];
        $line->name = $validated['name'];
        $line->image_url = isset($validated['image_url']) ? trim((string) $validated['image_url']) : null;
        if ($line->image_url === '') {
            $line->image_url = null;
        }
        $line->expected_qty = (int) $validated['expected_qty'];
        $line->accepted_qty = $portal ? 0 : (int) ($validated['accepted_qty'] ?? 0);
        $line->rejected_qty = $portal ? 0 : (int) ($validated['rejected_qty'] ?? 0);
        $line->sort_order = $maxSort + 1;
        $line->save();
        $this->recalcLineAggregates($asn->fresh());

        return response()->json($this->serializeLine($line), 201);
    }

    public function updateLine(Request $request, ClientAccountAsn $asn, ClientAccountAsnLine $line): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        if ((int) $line->client_account_asn_id !== (int) $asn->id) {
            abort(404);
        }
        $validated = $request->validate([
            'expected_qty' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
            'accepted_qty' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
            'rejected_qty' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
            'sku' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:512'],
        ]);
        $portal = $this->isPortalUser($request);
        foreach (['expected_qty', 'sku', 'name'] as $k) {
            if (array_key_exists($k, $validated)) {
                $line->{$k} = $validated[$k];
            }
        }
        if (! $portal) {
            foreach (['accepted_qty', 'rejected_qty'] as $k) {
                if (array_key_exists($k, $validated)) {
                    $line->{$k} = $validated[$k];
                }
            }
        }
        $line->save();
        $this->recalcLineAggregates($asn);

        return response()->json($this->serializeLine($line->fresh()));
    }

    public function destroyLine(Request $request, ClientAccountAsn $asn, ClientAccountAsnLine $line): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        if ((int) $line->client_account_asn_id !== (int) $asn->id) {
            abort(404);
        }
        $line->delete();
        $this->recalcLineAggregates($asn);

        return response()->json(['ok' => true]);
    }

    public function syncTrackings(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        $validated = $request->validate([
            'trackings' => ['required', 'array'],
            'trackings.*.carrier' => ['nullable', 'string', 'max:128'],
            'trackings.*.tracking_number' => ['nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($asn, $validated) {
            $this->persistTrackings($asn, $validated['trackings']);
        });

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines'])));
    }

    public function syncVendorLines(Request $request, ClientAccountAsn $asn): JsonResponse
    {
        $this->authorizeAsn($request, $asn);
        $asn->loadMissing('clientAccount');
        $validated = $request->validate([
            'vendor_lines' => ['required', 'array'],
            'vendor_lines.*.label' => ['nullable', 'string', 'max:512'],
        ]);
        DB::transaction(function () use ($asn, $validated) {
            ClientAccountAsnVendorLine::query()->where('client_account_asn_id', $asn->id)->delete();
            $order = 0;
            $lines = [];
            foreach ($validated['vendor_lines'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $label = trim((string) ($row['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $lines[] = $label;
            }
            if ($lines === []) {
                $account = $asn->clientAccount;
                $lines[] = $account !== null ? trim((string) $account->company_name) : 'Vendor';
            }
            foreach ($lines as $label) {
                $v = new ClientAccountAsnVendorLine;
                $v->client_account_asn_id = $asn->id;
                $v->label = $label;
                $v->sort_order = $order++;
                $v->save();
            }
        });

        return response()->json($this->serializeAsn($asn->fresh(['lines', 'trackings', 'vendorLines'])));
    }
}
