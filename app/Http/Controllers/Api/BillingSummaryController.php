<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;

class BillingSummaryController extends Controller
{
    private InvoiceService $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        return response()->json($this->invoices->summary());
    }
}
