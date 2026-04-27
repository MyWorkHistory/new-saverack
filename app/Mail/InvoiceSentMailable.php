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

    /** @var string|null */
    public $customerViewUrl;

    /** @var string|null */
    public $customMessage;

    /** @var list<string> */
    public array $primaryRecipientEmails;

    /** @var string */
    public string $greetingName;

    /** @var string e.g. "$1,234.56" */
    public string $invoiceAmountFormatted;

    /** @var string */
    public string $dueDateFormatted;

    /** @var string */
    public string $accountName;

    /** @var string Absolute URL for logo in email clients */
    public string $logoUrl;

    /**
     * @param  list<string>  $primaryRecipientEmails  First To address(es) for greeting derivation
     */
    public function __construct(
        Invoice $invoice,
        ?string $customerViewUrl = null,
        ?string $customMessage = null,
        array $primaryRecipientEmails = []
    ) {
        $this->invoice = $invoice;
        $this->customerViewUrl = $customerViewUrl;
        $this->customMessage = $customMessage;
        $this->primaryRecipientEmails = array_values($primaryRecipientEmails);
        $this->greetingName = $this->resolveGreetingName($invoice, $this->primaryRecipientEmails);
        $this->invoiceAmountFormatted = $this->formatMoney($invoice);
        $this->dueDateFormatted = $invoice->due_at !== null
            ? $invoice->due_at->format('n/j/Y')
            : '—';
        $this->accountName = $invoice->clientAccount !== null
            ? (string) $invoice->clientAccount->company_name
            : 'Your account';
        $base = rtrim((string) config('app.url'), '/');
        $this->logoUrl = $base.'/images/logo/logo.svg';
    }

    public function build()
    {
        $subject = 'You have a new invoice for '.$this->invoiceAmountFormatted;

        return $this->from('billing@saverack.com', 'Save Rack Billing')
            ->subject($subject)
            ->view('emails.invoice-sent');
    }

    private function resolveGreetingName(Invoice $invoice, array $emails): string
    {
        $account = $invoice->clientAccount;
        if ($account !== null) {
            $first = trim((string) $account->contact_first_name);
            if ($first !== '') {
                return $first;
            }
        }
        if ($emails !== []) {
            $local = strtolower(trim((string) explode('@', (string) $emails[0], 2)[0]));
            if ($local !== '') {
                return ucfirst(str_replace(['.', '_'], ' ', $local));
            }
        }

        return 'there';
    }

    private function formatMoney(Invoice $invoice): string
    {
        $cents = (int) $invoice->total_cents;
        $amount = $cents / 100;
        $sym = $invoice->currency === 'USD' ? '$' : ((string) $invoice->currency).' ';

        return $sym.number_format($amount, 2);
    }
}
