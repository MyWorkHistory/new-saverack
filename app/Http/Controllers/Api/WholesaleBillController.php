<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WholesaleBill;
use App\Models\WholesaleBillItem;
use App\Services\WholesaleBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WholesaleBillController extends Controller
{
    /** @var WholesaleBillService */
    private $bills;

    public function __construct(WholesaleBillService $bills)
    {
        $this->bills = $bills;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WholesaleBill::class);

        return response()->json($this->bills->paginate($request->only([
            'search', 'status', 'client_account_id', 'per_page', 'page',
        ])));
    }

    public function show(WholesaleBill $wholesaleBill): JsonResponse
    {
        $this->authorize('view', $wholesaleBill);

        return response()->json($this->bills->toDetailArray($wholesaleBill));
    }

    public function update(Request $request, WholesaleBill $wholesaleBill): JsonResponse
    {
        $this->authorize('update', $wholesaleBill);
        $validated = $request->validate([
            'bill_date' => ['required', 'date'],
        ]);

        $bill = $this->bills->updateHeader(
            $wholesaleBill,
            $validated,
            $request->user()
        );

        return response()->json($this->bills->toDetailArray($bill));
    }

    public function destroy(Request $request, WholesaleBill $wholesaleBill): JsonResponse
    {
        $this->authorize('delete', $wholesaleBill);
        $this->bills->delete($wholesaleBill, $request->user());

        return response()->json(['message' => 'Wholesale bill deleted.']);
    }

    public function draftInvoices(Request $request, WholesaleBill $wholesaleBill): JsonResponse
    {
        $this->authorize('view', $wholesaleBill);

        return response()->json([
            'invoices' => $this->bills->draftInvoices(
                $wholesaleBill,
                $request->boolean('ensure'),
                $request->user()
            ),
        ]);
    }

    public function addToInvoice(Request $request, WholesaleBill $wholesaleBill): JsonResponse
    {
        $this->authorize('update', $wholesaleBill);
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
        ]);

        $bill = $this->bills->addToInvoice(
            $wholesaleBill,
            (int) $validated['invoice_id'],
            $request->user()
        );

        return response()->json($this->bills->toDetailArray($bill));
    }

    public function storeItem(Request $request, WholesaleBill $wholesaleBill): JsonResponse
    {
        $this->authorize('update', $wholesaleBill);
        $validated = $request->validate([
            'line_type' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_price_cents' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:50'],
            'client_account_fee_id' => ['nullable', 'integer'],
        ]);

        $bill = $this->bills->addItem($wholesaleBill, $validated, $request->user());

        return response()->json($this->bills->toDetailArray($bill));
    }

    public function updateItem(Request $request, WholesaleBill $wholesaleBill, WholesaleBillItem $item): JsonResponse
    {
        $this->authorize('update', $wholesaleBill);
        $validated = $request->validate([
            'line_type' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_price_cents' => ['nullable', 'integer', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:50'],
            'client_account_fee_id' => ['nullable', 'integer'],
        ]);

        $bill = $this->bills->updateItem($wholesaleBill, $item, $validated, $request->user());

        return response()->json($this->bills->toDetailArray($bill));
    }

    public function destroyItem(Request $request, WholesaleBill $wholesaleBill, WholesaleBillItem $item): JsonResponse
    {
        $this->authorize('update', $wholesaleBill);
        $bill = $this->bills->deleteItem($wholesaleBill, $item, $request->user());

        return response()->json($this->bills->toDetailArray($bill));
    }
}
