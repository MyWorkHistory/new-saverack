<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WholesaleBill;
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
}
