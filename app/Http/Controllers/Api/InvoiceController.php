<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRecordPaymentRequest;
use App\Http\Requests\InvoiceReplaceLineGroupRequest;
use App\Http\Requests\InvoiceSendEmailRequest;
use App\Http\Requests\InvoiceSendWhatsappRequest;
use App\Http\Requests\InvoiceStoreRequest;
use App\Http\Requests\InvoiceUpdateRequest;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public function pdf(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $data = $this->invoices->pdfViewData($invoice);
        $filename = preg_replace(
            '/[^A-Za-z0-9._ -]+/',
            '_',
            'Fulfillment Summary - Invoice #'.$invoice->invoice_number
        ).'.pdf';

        return Pdf::loadView('billing.invoice-pdf', $data)->download($filename);
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

    public function sendEmail(InvoiceSendEmailRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        $result = $this->invoices->sendInvoiceEmail($invoice, $request->user(), $request->messageText());
        $invoice = $invoice->fresh(['items', 'clientAccount']);

        return response()->json([
            'invoice' => $this->invoices->toDetailArray($invoice),
            'recipients' => $result['recipients'],
        ]);
    }

    public function sendWhatsapp(InvoiceSendWhatsappRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        $result = $this->invoices->sendInvoiceWhatsapp(
            $invoice,
            $request->user(),
            $request->actionType(),
            $request->messageText(),
        );
        $invoice = $invoice->fresh(['items', 'clientAccount']);

        return response()->json([
            'invoice' => $this->invoices->toDetailArray($invoice),
            'whatsapp' => $result,
        ]);
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

    public function shareLink(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        $invoice = $this->invoices->ensureShareToken($invoice);

        return response()->json([
            'customer_view_url' => $this->invoices->publicCustomerViewUrl($invoice),
            'customer_pdf_url' => $this->invoices->publicCustomerPdfUrl($invoice),
        ]);
    }

    public function destroyLineGroup(Invoice $invoice, string $groupKey): JsonResponse
    {
        $this->authorize('update', $invoice);
        $groupKey = rawurldecode($groupKey);
        try {
            $invoice = $this->invoices->deleteLineGroup($invoice, $groupKey, request()->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function replaceLineGroup(InvoiceReplaceLineGroupRequest $request, Invoice $invoice, string $groupKey): JsonResponse
    {
        $this->authorize('update', $invoice);
        $groupKey = rawurldecode($groupKey);
        $invoice = $this->invoices->replaceLineGroup(
            $invoice,
            $groupKey,
            $request->itemsPayload(),
            $request->user(),
        );

        return response()->json($this->invoices->toDetailArray($invoice));
    }
}
