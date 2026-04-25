<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceAllocatePaymentRequest;
use App\Http\Requests\InvoiceRecordPaymentRequest;
use App\Http\Requests\InvoiceReplaceLineGroupRequest;
use App\Http\Requests\InvoiceAddItemRequest;
use App\Http\Requests\InvoiceAddCcFeeRequest;
use App\Http\Requests\InvoiceSendEmailRequest;
use App\Http\Requests\InvoiceSendWhatsappRequest;
use App\Http\Requests\InvoiceStoreRequest;
use App\Http\Requests\InvoiceStripeChargeRequest;
use App\Http\Requests\InvoiceUpdateItemRequest;
use App\Http\Requests\InvoiceUpdateRequest;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoiceService;
use App\Services\StripeInvoicePaymentService;
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
            ['draft', 'open', 'past_due', 'collection', 'paid', 'void', 'all'],
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
        $user = request()->user();
        $force = $user !== null && ($user->isAdministrator() || $user->isCrmOwner());
        $this->invoices->deleteInvoice($invoice, $force);

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
        try {
            $result = $this->invoices->sendInvoiceEmail(
                $invoice,
                $request->user(),
                $request->messageText(),
                $request->recipientEmails(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $invoice = $invoice->fresh(['items', 'clientAccount']);

        return response()->json([
            'invoice' => $this->invoices->toDetailArray($invoice),
            'recipients' => $result['recipients'],
        ]);
    }

    public function sendWhatsapp(InvoiceSendWhatsappRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        try {
            $result = $this->invoices->sendInvoiceWhatsapp(
                $invoice,
                $request->user(),
                $request->actionType(),
                $request->messageText(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
            $request->paymentMeta(),
        );

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function pay(InvoiceRecordPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        return $this->recordPayment($request, $invoice);
    }

    public function payContext(Invoice $invoice): JsonResponse
    {
        $this->authorize('recordPayment', $invoice);

        return response()->json($this->invoices->paymentAllocationContext($invoice));
    }

    public function payAllocate(InvoiceAllocatePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('recordPayment', $invoice);

        $result = $this->invoices->allocatePaymentAcrossInvoices(
            $invoice,
            $request->invoiceIds(),
            $request->amountCents(),
            $request->user(),
            $request->paymentMeta(),
        );

        return response()->json([
            'invoice' => $this->invoices->toDetailArray($result['invoice']),
            'allocations' => $result['allocations'],
            'remaining_amount_cents' => $result['remaining_amount_cents'],
        ]);
    }

    public function stripePaymentMethods(Invoice $invoice, StripeInvoicePaymentService $stripePayments): JsonResponse
    {
        $this->authorize('recordPayment', $invoice);
        try {
            $rows = $stripePayments->listPaymentMethods($invoice);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'invoice_id' => (int) $invoice->id,
            'methods' => $rows,
        ]);
    }

    public function stripeCharge(
        InvoiceStripeChargeRequest $request,
        Invoice $invoice,
        StripeInvoicePaymentService $stripePayments
    ): JsonResponse {
        $this->authorize('recordPayment', $invoice);
        try {
            $result = $stripePayments->chargeInvoice(
                $invoice,
                $request->paymentMethodId(),
                $request->amountCents(),
                $request->user(),
                $request->paymentMeta(),
                $this->invoices
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /** @var \App\Models\Invoice $updated */
        $updated = $result['invoice'];

        return response()->json([
            'result' => $result['result'],
            'status' => $result['status'] ?? null,
            'applied_amount_cents' => (int) ($result['applied_amount_cents'] ?? 0),
            'payment_intent_id' => $result['payment_intent_id'] ?? null,
            'invoice' => $this->invoices->toDetailArray($updated),
        ]);
    }

    public function addItem(InvoiceAddItemRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('addCharge', $invoice);
        $invoice = $this->invoices->addInvoiceItem($invoice, $request->itemPayload(), $request->user());

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function addCcFee(InvoiceAddCcFeeRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('addCharge', $invoice);
        $invoice = $this->invoices->addCcFee($invoice, $request->amountCents(), $request->label(), $request->user());

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function void(Invoice $invoice): JsonResponse
    {
        $this->authorize('void', $invoice);
        $invoice = $this->invoices->voidInvoice($invoice, request()->user());

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('updateStatus', $invoice);
        $validated = $request->validate([
            'status' => ['required', 'string'],
        ]);
        try {
            $invoice = $this->invoices->updateLegacyStatus(
                $invoice,
                (string) $validated['status'],
                $request->user()
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

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

    public function updateItem(InvoiceUpdateItemRequest $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        $this->authorize('update', $invoice);
        if ((int) $item->invoice_id !== (int) $invoice->id) {
            return response()->json(['message' => 'Line item does not belong to this invoice.'], 422);
        }
        $invoice = $this->invoices->updateInvoiceItem(
            $invoice,
            (int) $item->id,
            $request->itemPayload(),
            $request->user(),
        );

        return response()->json($this->invoices->toDetailArray($invoice));
    }

    public function destroyItem(Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        $this->authorize('update', $invoice);
        if ((int) $item->invoice_id !== (int) $invoice->id) {
            return response()->json(['message' => 'Line item does not belong to this invoice.'], 422);
        }
        $invoice = $this->invoices->deleteInvoiceItem($invoice, (int) $item->id, request()->user());

        return response()->json($this->invoices->toDetailArray($invoice));
    }
}
