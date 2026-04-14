<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceCsvImportRequest;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Services\InvoiceImportService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;

class InvoiceImportController extends Controller
{
    /** @var InvoiceImportService */
    private $imports;

    /** @var InvoiceService */
    private $invoices;

    public function __construct(InvoiceImportService $imports, InvoiceService $invoices)
    {
        $this->imports = $imports;
        $this->invoices = $invoices;
    }

    public function importCharges(InvoiceCsvImportRequest $request, ClientAccount $client_account): JsonResponse
    {
        $this->authorize('view', $client_account);
        $this->authorize('create', Invoice::class);

        $result = $this->imports->importChargeCsv(
            $client_account,
            $request->file('file'),
            $request->dueDateString(),
            $request->optionalInvoiceNumber(),
            $request->user(),
        );

        return response()->json([
            'invoice' => $this->invoices->toDetailArray($result['invoice']),
            'import' => [
                'id' => $result['import']->id,
                'status' => $result['import']->status,
                'import_type' => $result['import']->import_type,
                'rows_processed' => $result['import']->rows_processed,
            ],
        ], 201);
    }

    public function importStorage(InvoiceCsvImportRequest $request, ClientAccount $client_account): JsonResponse
    {
        $this->authorize('view', $client_account);
        $this->authorize('create', Invoice::class);

        $result = $this->imports->importStorageCsv(
            $client_account,
            $request->file('file'),
            $request->dueDateString(),
            $request->optionalInvoiceNumber(),
            $request->user(),
        );

        return response()->json([
            'invoice' => $this->invoices->toDetailArray($result['invoice']),
            'import' => [
                'id' => $result['import']->id,
                'status' => $result['import']->status,
                'import_type' => $result['import']->import_type,
                'rows_processed' => $result['import']->rows_processed,
            ],
            'skipped' => $result['skipped'],
        ], 201);
    }
}
