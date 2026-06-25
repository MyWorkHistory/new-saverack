<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReturnBill;
use App\Services\ReturnBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function show(ReturnBill $returnBill): JsonResponse
    {
        $this->authorize('view', $returnBill);

        return response()->json($this->returnBills->toDetailArray($returnBill));
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
        ]);

        $bill = $this->returnBills->addToInvoice(
            $returnBill,
            (int) $validated['invoice_id'],
            $request->user()
        );

        return response()->json($this->returnBills->toDetailArray($bill));
    }
}
