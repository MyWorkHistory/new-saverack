<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRecordPaymentRequest;
use App\Http\Requests\InvoiceStoreRequest;
use App\Http\Requests\InvoiceUpdateRequest;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    private InvoiceService $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    public function meta(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $clientAccounts = ClientAccount::query()
            ->orderBy('company_name')
            ->get(['id', 'company_name']);

        $statuses = array_values(array_unique(array_merge(
            Invoice::STATUSES,
            ['overdue', 'all'],
        )));

        return response()->json([
            'statuses' => $statuses,
            'client_accounts' => $clientAccounts->map(static function (ClientAccount $c) {
                return [
                    'id' => $c->id,
                    'name' => $c->company_name,
                ];
            })->values()->all(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $data = $this->invoices->paginate($request->only([
            'search',
            'status',
            'client_account_id',
            'issued_from',
            'issued_to',
            'sort_by',
            'sort_dir',
            'per_page',
            'page',
        ]));

        return response()->json($data);
    }

    public function store(InvoiceStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $invoice = $this->invoices->createDraft(
            $request->headerPayload(),
            $request->itemsPayload(),
            $request->user(),
            $request->optionalInvoiceNumber(),
        );

        return response()->json($this->invoices->toDetailArray($invoice), 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function update(InvoiceUpdateRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $invoice = $this->invoices->updateDraft(
            $invoice,
            $request->headerPayload(),
            $request->itemsPayload(),
            $request->user(),
        );

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);
        $this->invoices->deleteDraft($invoice);

        return response()->json(['message' => 'Invoice deleted.']);
    }

    public function send(Invoice $invoice): JsonResponse
    {
        $this->authorize('send', $invoice);
        $invoice = $this->invoices->markSent($invoice, request()->user());

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function recordPayment(InvoiceRecordPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('recordPayment', $invoice);
        $invoice = $this->invoices->recordPayment(
            $invoice,
            (int) $request->validated()['amount_cents'],
            $request->user(),
        );

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function void(Invoice $invoice): JsonResponse
    {
        $this->authorize('void', $invoice);
        $invoice = $this->invoices->voidInvoice($invoice, request()->user());

        return response()->json($this->invoices->toDetailArray($invoice));
    }
}
