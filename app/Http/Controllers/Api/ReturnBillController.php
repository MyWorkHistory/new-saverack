<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReturnBill;
use App\Models\ReturnBillItem;
use App\Services\ReturnBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReturnBillController extends Controller
{
    /** @var ReturnBillService */
    private $returnBills;

    public function __construct(ReturnBillService $returnBills)
    {
        $this->returnBills = $returnBills;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReturnBill::class);

        $data = $this->returnBills->paginate($request->only([
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

    public function chargeOptions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReturnBill::class);
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);

        return response()->json([
            'options' => $this->returnBills->chargeOptionsForAccount((int) $validated['client_account_id']),
        ]);
    }

    public function show(ReturnBill $returnBill): JsonResponse
    {
        $this->authorize('view', $returnBill);

        return response()->json($this->returnBills->toDetailArray($returnBill));
    }

    public function update(Request $request, ReturnBill $returnBill): JsonResponse
    {
        $this->authorize('update', $returnBill);
        $validated = $request->validate([
            'bill_date' => ['required', 'date'],
        ]);

        $bill = $this->returnBills->updateHeader(
            $returnBill,
            $validated,
            $request->user()
        );

        return response()->json($this->returnBills->toDetailArray($bill));
    }

    public function destroy(ReturnBill $returnBill): JsonResponse
    {
        $this->authorize('delete', $returnBill);
        $this->returnBills->delete($returnBill, request()->user());

        return response()->json(['message' => 'Return bill deleted.']);
    }

    public function draftInvoices(ReturnBill $returnBill): JsonResponse
    {
        $this->authorize('view', $returnBill);

        return response()->json([
            'invoices' => $this->returnBills->draftInvoicesForBill($returnBill),
        ]);
    }

    public function addToInvoice(Request $request, ReturnBill $returnBill): JsonResponse
    {
        $this->authorize('update', $returnBill);
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'line_types' => ['nullable', 'array'],
            'line_types.*' => ['string', Rule::in([
                ReturnBill::LINE_FIRST_ITEM,
                ReturnBill::LINE_ADDITIONAL_ITEMS,
                ReturnBill::LINE_ASSEMBLY,
                ReturnBill::LINE_REPACKAGING,
                ReturnBill::LINE_DISPOSAL,
            ])],
        ]);

        $lineTypes = isset($validated['line_types']) ? array_values($validated['line_types']) : null;

        $bill = $this->returnBills->addToInvoice(
            $returnBill,
            (int) $validated['invoice_id'],
            $request->user(),
            $lineTypes
        );

        return response()->json($this->returnBills->toDetailArray($bill));
    }

    public function storeItem(Request $request, ReturnBill $returnBill): JsonResponse
    {
        $this->authorize('update', $returnBill);
        $validated = $request->validate([
            'line_type' => ['required', 'string', Rule::in([
                ReturnBill::LINE_FIRST_ITEM,
                ReturnBill::LINE_ADDITIONAL_ITEMS,
                ReturnBill::LINE_ASSEMBLY,
                ReturnBill::LINE_REPACKAGING,
                ReturnBill::LINE_DISPOSAL,
            ])],
            'name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_price_cents' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $bill = $this->returnBills->addItem($returnBill, $validated, $request->user());

        return response()->json($this->returnBills->toDetailArray($bill));
    }

    public function updateItem(Request $request, ReturnBill $returnBill, ReturnBillItem $item): JsonResponse
    {
        $this->authorize('update', $returnBill);
        $validated = $request->validate([
            'line_type' => ['required', 'string', Rule::in([
                ReturnBill::LINE_FIRST_ITEM,
                ReturnBill::LINE_ADDITIONAL_ITEMS,
                ReturnBill::LINE_ASSEMBLY,
                ReturnBill::LINE_REPACKAGING,
                ReturnBill::LINE_DISPOSAL,
            ])],
            'name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_price_cents' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $bill = $this->returnBills->updateItem($returnBill, $item, $validated, $request->user());

        return response()->json($this->returnBills->toDetailArray($bill));
    }

    public function destroyItem(ReturnBill $returnBill, ReturnBillItem $item): JsonResponse
    {
        $this->authorize('update', $returnBill);
        $bill = $this->returnBills->deleteItem($returnBill, $item, $request->user());

        return response()->json($this->returnBills->toDetailArray($bill));
    }
}
