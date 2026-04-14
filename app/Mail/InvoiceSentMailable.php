<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceSentMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var Invoice */
    public $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function build()
    {
        $subject = 'Invoice '.$this->invoice->invoice_number.' — '.config('app.name');

        return $this->subject($subject)
            ->view('emails.invoice-sent');
    }
}
