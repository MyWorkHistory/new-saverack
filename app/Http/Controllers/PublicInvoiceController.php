<?php

namespace App\Http\Controllers;

use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PublicInvoiceController extends Controller
{
    /** @var InvoiceService */
    private $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    public function show(string $slug, string $token): Response
    {
        $invoice = $this->invoices->resolvePublicInvoice($slug, $token);
        abort_if($invoice === null, 404);

        $data = $this->invoices->publicInvoiceHtmlData($invoice);
        $data['public_pdf_path'] = url('/billing-invoice/'.$slug.'/'.$token.'/pdf');
        $data['public_pay_path'] = url('/billing-invoice/'.$slug.'/'.$token.'/pay');
        $data['status_label'] = $this->invoices->legacyStatusLabel($invoice);

        return response()->view('public.invoice', $data);
    }

    public function pay(string $slug, string $token, \App\Services\StripeInvoicePaymentService $stripePayments)
    {
        $invoice = $this->invoices->resolvePublicInvoice($slug, $token);
        abort_if($invoice === null, 404);

        try {
            $successUrl = url('/billing-invoice/'.$slug.'/'.$token.'?payment=success');
            $cancelUrl = url('/billing-invoice/'.$slug.'/'.$token.'?payment=cancel');
            $url = $stripePayments->createPublicCheckoutUrl($invoice, $successUrl, $cancelUrl);
        } catch (\Throwable $e) {
            return redirect('/billing-invoice/'.$slug.'/'.$token.'?payment=error');
        }

        return redirect()->away($url);
    }

    public function pdf(string $slug, string $token)
    {
        $invoice = $this->invoices->resolvePublicInvoice($slug, $token);
        abort_if($invoice === null, 404);

        $data = $this->invoices->pdfViewData($invoice);
        $filename = preg_replace(
            '/[^A-Za-z0-9._ -]+/',
            '_',
            'Fulfillment Summary - Invoice #'.$invoice->invoice_number
        ).'.pdf';

        return Pdf::loadView('billing.invoice-pdf', $data)->download($filename);
    }
}
