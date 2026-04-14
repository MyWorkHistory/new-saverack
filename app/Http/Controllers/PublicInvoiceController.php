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

        $data = $this->invoices->pdfViewData($invoice);
        $data['public_pdf_path'] = url('/billing-invoice/'.$slug.'/'.$token.'/pdf');

        return response()->view('public.invoice', $data);
    }

    public function pdf(string $slug, string $token)
    {
        $invoice = $this->invoices->resolvePublicInvoice($slug, $token);
        abort_if($invoice === null, 404);

        $data = $this->invoices->pdfViewData($invoice);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $invoice->invoice_number).'.pdf';

        return Pdf::loadView('billing.invoice-pdf', $data)->download($filename);
    }
}
