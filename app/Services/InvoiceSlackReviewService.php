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
        $invoice->loadMissing('clientAccount');
        $text = $this->buildMessageText($invoice, $reasonKey, $note);

        $webhookUrl = $this->normalizeWebhookUrl((string) config('billing.slack.webhook_url', ''));
        if ($webhookUrl !== '') {
            $this->postViaWebhook($webhookUrl, $text);

            return;
        }

        $token = $this->normalizeBotToken((string) config('billing.slack.bot_token', ''));
        if ($token === '') {
            throw new \RuntimeException(
                'Slack is not configured for invoice review. Set SLACK_BOT_USER_OAUTH_TOKEN (xoxb-…) or BILLING_SLACK_INCOMING_WEBHOOK_URL in .env.'
            );
        }

        $this->assertBotTokenShape($token);

        $channel = trim((string) (config('billing.slack.accounting_channel') ?: '#accounting'));
        if ($channel === '') {
            throw new \RuntimeException(
                'Slack accounting channel is not configured. Set BILLING_SLACK_ACCOUNTING_CHANNEL=#accounting in .env.'
            );
        }

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
            throw new \RuntimeException($this->formatSlackError($error));
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

    private function postViaWebhook(string $webhookUrl, string $text): void
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->post($webhookUrl, ['text' => $text]);

        if (! $response->successful()) {
            throw new \RuntimeException('Could not send invoice review to Slack webhook.');
        }

        $body = trim($response->body());
        if ($body !== '' && $body !== 'ok') {
            throw new \RuntimeException('Slack webhook rejected the invoice review message.');
        }
    }

    private function normalizeBotToken(string $raw): string
    {
        $token = trim($raw);
        if ($token === '') {
            return '';
        }

        if (
            (str_starts_with($token, '"') && str_ends_with($token, '"'))
            || (str_starts_with($token, "'") && str_ends_with($token, "'"))
        ) {
            $token = substr($token, 1, -1);
        }

        return trim($token);
    }

    private function normalizeWebhookUrl(string $raw): string
    {
        $url = trim($raw);
        if (
            (str_starts_with($url, '"') && str_ends_with($url, '"'))
            || (str_starts_with($url, "'") && str_ends_with($url, "'"))
        ) {
            $url = substr($url, 1, -1);
        }

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! str_starts_with($url, 'https://hooks.slack.com/')) {
            return '';
        }

        return $url;
    }

    private function assertBotTokenShape(string $token): void
    {
        if (str_starts_with($token, 'https://hooks.slack.com/')) {
            throw new \RuntimeException(
                'SLACK_BOT_USER_OAUTH_TOKEN looks like a webhook URL. Put that URL in BILLING_SLACK_INCOMING_WEBHOOK_URL instead, or use a Bot User OAuth Token (xoxb-…).'
            );
        }

        if (str_starts_with($token, 'xoxp-')) {
            throw new \RuntimeException(
                'SLACK_BOT_USER_OAUTH_TOKEN is a user token (xoxp-). Invoice review needs a Bot User OAuth Token (xoxb-…) from Slack app OAuth & Permissions.'
            );
        }

        if (! str_starts_with($token, 'xoxb-')) {
            throw new \RuntimeException(
                'SLACK_BOT_USER_OAUTH_TOKEN must be a Bot User OAuth Token starting with xoxb-. Copy it from api.slack.com → your app → OAuth & Permissions → Bot User OAuth Token.'
            );
        }
    }

    private function formatSlackError(string $error): string
    {
        if ($error === 'invalid_auth') {
            return 'Slack rejected the bot token (invalid_auth). Regenerate the Bot User OAuth Token (xoxb-…) in Slack → your app → OAuth & Permissions, reinstall the app to the workspace, update .env, then run php artisan config:clear. Or use BILLING_SLACK_INCOMING_WEBHOOK_URL for #accounting instead.';
        }

        if ($error === 'missing_scope') {
            return 'Slack bot is missing required scopes. In api.slack.com → your app → OAuth & Permissions → Bot Token Scopes, add chat:write (and chat:write.public if the bot is not in #accounting), then Reinstall to Workspace and update SLACK_BOT_USER_OAUTH_TOKEN in .env. Or use BILLING_SLACK_INCOMING_WEBHOOK_URL instead.';
        }

        if ($error === 'channel_not_found' || $error === 'not_in_channel') {
            return 'Slack bot is not in #accounting. Invite the bot to the channel or use BILLING_SLACK_INCOMING_WEBHOOK_URL for that channel.';
        }

        return 'Slack rejected the invoice review message ('.$error.').';
    }
}
