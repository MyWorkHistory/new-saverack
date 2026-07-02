<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsnBill;
use App\Models\AsnBillItem;
use App\Services\AsnBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AsnBillController extends Controller
{
    /** @var AsnBillService */
    private $asnBills;

    public function __construct(AsnBillService $asnBills)
    {
        $this->asnBills = $asnBills;
    }

    public function lines(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AsnBill::class);

        $data = $this->asnBills->paginateLines($request->only([
            'search',
            'status',
            'client_account_id',
            'date_from',
            'date_to',
            'sort_by',
            'sort_dir',
            'per_page',
            'page',
        ]));

        return response()->json($data);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AsnBill::class);

        $data = $this->asnBills->paginate($request->only([
            'search',
            'status',
            'client_account_id',
            'bill_number',
            'date_from',
            'date_to',
            'sort_by',
            'sort_dir',
            'per_page',
            'page',
        ]));

        return response()->json($data);
    }

    public function show(AsnBill $asnBill): JsonResponse
    {
        $this->authorize('view', $asnBill);

        return response()->json($this->asnBills->toDetailArray($asnBill));
    }

    public function update(Request $request, AsnBill $asnBill): JsonResponse
    {
        $this->authorize('update', $asnBill);
        $validated = $request->validate([
            'bill_date' => ['required', 'date'],
        ]);

        $bill = $this->asnBills->updateHeader(
            $asnBill,
            $validated,
            $request->user()
        );

        return response()->json($this->asnBills->toDetailArray($bill));
    }

    public function destroy(Request $request, AsnBill $asnBill): JsonResponse
    {
        $this->authorize('delete', $asnBill);
        $this->asnBills->delete($asnBill, $request->user());

        return response()->json(['message' => 'ASN bill deleted.']);
    }

    public function draftInvoices(AsnBill $asnBill): JsonResponse
    {
        $this->authorize('view', $asnBill);

        return response()->json([
            'invoices' => $this->asnBills->draftInvoicesForBill($asnBill),
        ]);
    }

    public function addToInvoice(Request $request, AsnBill $asnBill): JsonResponse
    {
        $this->authorize('update', $asnBill);
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'line_types' => ['nullable', 'array'],
            'line_types.*' => ['string', Rule::in([
                AsnBill::LINE_RECEIVING_PER_BOX,
                AsnBill::LINE_RECEIVING_PER_PALLET,
                AsnBill::LINE_RECEIVING_PER_ITEM,
                AsnBill::LINE_CUSTOM_HOURLY_WORK,
                AsnBill::LINE_NON_COMPLIANT,
            ])],
        ]);

        $lineTypes = isset($validated['line_types']) ? array_values($validated['line_types']) : null;

        $bill = $this->asnBills->addToInvoice(
            $asnBill,
            (int) $validated['invoice_id'],
            $request->user(),
            $lineTypes
        );

        return response()->json($this->asnBills->toDetailArray($bill));
    }

    public function storeItem(Request $request, AsnBill $asnBill): JsonResponse
    {
        $this->authorize('update', $asnBill);
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

        $bill = $this->asnBills->addItem($asnBill, $validated, $request->user());

        return response()->json($this->asnBills->toDetailArray($bill));
    }

    public function updateItem(Request $request, AsnBill $asnBill, AsnBillItem $item): JsonResponse
    {
        $this->authorize('update', $asnBill);
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

        $bill = $this->asnBills->updateItem($asnBill, $item, $validated, $request->user());

        return response()->json($this->asnBills->toDetailArray($bill));
    }

    public function destroyItem(Request $request, AsnBill $asnBill, AsnBillItem $item): JsonResponse
    {
        $this->authorize('update', $asnBill);
        $bill = $this->asnBills->deleteItem($asnBill, $item, $request->user());
        if ($bill === null) {
            return response()->json(['message' => 'Line removed.', 'bill' => null]);
        }

        return response()->json($this->asnBills->toDetailArray($bill));
    }
}
