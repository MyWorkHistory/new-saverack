<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Support\CrmUrls;
use App\Support\InvoiceReviewReason;
use Illuminate\Support\Facades\Http;

class InvoiceSlackReviewService
{
    public function postReview(Invoice $invoice, string $reasonKey, ?string $note, ?User $actor = null): void
    {
        $token = trim((string) config('billing.slack.bot_token', ''));
        if ($token === '') {
            throw new \RuntimeException('Slack is not configured for invoice review notifications.');
        }

        $channel = trim((string) (config('billing.slack.accounting_channel') ?: '#accounting'));
        if ($channel === '') {
            throw new \RuntimeException(
                'Slack accounting channel is not configured. Set BILLING_SLACK_ACCOUNTING_CHANNEL=#accounting in .env.'
            );
        }

        $invoice->loadMissing('clientAccount');
        $text = $this->buildMessageText($invoice, $reasonKey, $note);

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $channel,
                'text' => $text,
                'mrkdwn' => true,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Could not send invoice review to Slack.');
        }

        $body = $response->json();
        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            $error = is_array($body) ? (string) ($body['error'] ?? 'unknown_error') : 'unknown_error';
            throw new \RuntimeException('Slack rejected the invoice review message ('.$error.').');
        }
    }

    public function buildMessageText(Invoice $invoice, string $reasonKey, ?string $note): string
    {
        $reasonLabel = InvoiceReviewReason::label($reasonKey);
        $invoiceNumber = trim((string) ($invoice->invoice_number ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = '#'.$invoice->id;
        } elseif (! str_starts_with($invoiceNumber, '#')) {
            $invoiceNumber = '#'.$invoiceNumber;
        }

        $company = trim((string) ($invoice->clientAccount->company_name ?? ''));
        if ($company === '') {
            $company = '—';
        }

        $summaryLine = sprintf('Invoice %s - %s - %s', $invoiceNumber, $company, $reasonLabel);
        $invoiceUrl = CrmUrls::invoiceStaffUrl((int) $invoice->id);

        $lines = [
            '*Invoice Review*',
            $summaryLine,
        ];

        $noteText = is_string($note) ? trim($note) : '';
        if ($noteText !== '') {
            $lines[] = 'Note: '.$noteText;
        }

        $lines[] = '<'.$invoiceUrl.'|View Invoice>';

        return implode("\n", $lines);
    }
}
