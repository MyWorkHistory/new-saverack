<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Support\CrmUrls;
use App\Support\InvoiceReviewReason;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InvoiceSlackReviewService
{
    /**
     * @return array{method: string, channel: string|null, ts: string|null}
     */
    public function postReview(Invoice $invoice, string $reasonKey, ?string $note, ?User $actor = null): array
    {
        $invoice->loadMissing('clientAccount');
        $text = $this->buildMessageText($invoice, $reasonKey, $note);
        $channel = $this->resolveAccountingChannel();

        $webhookUrl = $this->normalizeWebhookUrl((string) config('billing.slack.webhook_url', ''));
        if ($webhookUrl !== '') {
            $this->postViaWebhook($webhookUrl, $channel, $text);

            Log::info('invoice_review_slack_sent', [
                'invoice_id' => $invoice->id,
                'slack_channel' => $channel,
                'delivery' => 'webhook',
                'actor_id' => $actor !== null ? $actor->id : null,
            ]);

            return [
                'method' => 'webhook',
                'channel' => $channel,
                'ts' => null,
            ];
        }

        $token = $this->normalizeBotToken((string) config('billing.slack.bot_token', ''));
        if ($token === '') {
            throw new \RuntimeException(
                'Slack is not configured for invoice review. Set SLACK_WEBHOOK_URL (legacy) or BILLING_SLACK_INCOMING_WEBHOOK_URL in .env, or SLACK_BOT_USER_OAUTH_TOKEN (xoxb-…).'
            );
        }

        $this->assertBotTokenShape($token);
        $this->joinChannelIfPossible($token, $channel);

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

        $ts = isset($body['ts']) ? trim((string) $body['ts']) : '';
        if ($ts === '') {
            throw new \RuntimeException('Slack accepted the request but did not return a message timestamp. Check bot scopes and channel configuration.');
        }

        $postedChannel = isset($body['channel']) ? trim((string) $body['channel']) : $channel;

        Log::info('invoice_review_slack_sent', [
            'invoice_id' => $invoice->id,
            'slack_channel' => $postedChannel,
            'slack_ts' => $ts,
            'delivery' => 'bot',
            'actor_id' => $actor !== null ? $actor->id : null,
        ]);

        return [
            'method' => 'bot',
            'channel' => $postedChannel,
            'ts' => $ts,
        ];
    }

    public function buildMessageText(Invoice $invoice, string $reasonKey, ?string $note): string
    {
        $reasonLabel = InvoiceReviewReason::label($reasonKey);
        $invoiceNumber = trim((string) ($invoice->invoice_number ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = (string) $invoice->id;
        }

        $company = trim((string) ($invoice->clientAccount->company_name ?? ''));
        if ($company === '') {
            $company = '—';
        }

        $summaryLine = sprintf('Invoice `#%s` - %s - %s', $invoiceNumber, $company, $reasonLabel);
        $invoiceUrl = CrmUrls::invoiceStaffUrl((int) $invoice->id);

        $lines = [
            $summaryLine,
        ];

        $noteText = is_string($note) ? trim($note) : '';
        if ($noteText !== '') {
            $lines[] = 'Note: '.$noteText;
        }

        $lines[] = '<'.$invoiceUrl.'|View Invoice>';

        return implode("\n", $lines);
    }

    private function resolveAccountingChannel(): string
    {
        $channel = $this->normalizeChannelName(
            trim((string) (config('billing.slack.accounting_channel') ?: '#accounting'))
        );
        if ($channel === '') {
            throw new \RuntimeException(
                'Slack accounting channel is not configured. Set BILLING_SLACK_ACCOUNTING_CHANNEL=#accounting in .env.'
            );
        }

        return $channel;
    }

    /**
     * Legacy Save Net pattern: one workspace webhook (SLACK_WEBHOOK_URL) + channel in payload.
     */
    private function postViaWebhook(string $webhookUrl, string $channel, string $text): void
    {
        $payload = [
            'channel' => $channel,
            'username' => 'Invoice Review',
            'text' => $text,
            'mrkdwn' => true,
        ];

        $response = Http::acceptJson()
            ->timeout(15)
            ->post($webhookUrl, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Could not send invoice review to Slack webhook.');
        }

        $body = trim($response->body());
        if ($body === 'ok') {
            return;
        }

        $json = $response->json();
        if (is_array($json) && ($json['ok'] ?? false) === true) {
            return;
        }

        $error = is_array($json) ? (string) ($json['error'] ?? '') : '';
        if ($error === 'channel_not_found' || $error === 'invalid_channel') {
            throw new \RuntimeException(
                'Slack webhook could not post to '.$channel.'. Check the channel name or use a webhook tied to #accounting.'
            );
        }

        throw new \RuntimeException(
            'Slack webhook rejected the invoice review message. Set SLACK_WEBHOOK_URL (same as legacy CRM) or BILLING_SLACK_INCOMING_WEBHOOK_URL.'
        );
    }

    private function joinChannelIfPossible(string $token, string $channel): void
    {
        if (preg_match('/^C[A-Z0-9]+$/', $channel) !== 1) {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(10)
                ->post('https://slack.com/api/conversations.join', [
                    'channel' => $channel,
                ]);

            $body = $response->json();
            if (is_array($body) && ($body['ok'] ?? false) !== true) {
                $error = (string) ($body['error'] ?? '');
                if (! in_array($error, ['already_in_channel', 'method_not_supported_for_channel_type'], true)) {
                    Log::warning('invoice_review_slack_join_failed', [
                        'channel' => $channel,
                        'error' => $error,
                    ]);
                }
            }
        }
    }

    private function normalizeChannelName(string $channel): string
    {
        $channel = trim($channel);
        if ($channel === '') {
            return '';
        }

        if (preg_match('/^C[A-Z0-9]+$/', $channel) === 1) {
            return $channel;
        }

        if (! str_starts_with($channel, '#')) {
            $channel = '#'.$channel;
        }

        return $channel;
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
                'SLACK_BOT_USER_OAUTH_TOKEN looks like a webhook URL. Put that URL in SLACK_WEBHOOK_URL or BILLING_SLACK_INCOMING_WEBHOOK_URL instead.'
            );
        }

        if (str_starts_with($token, 'xoxp-')) {
            throw new \RuntimeException(
                'SLACK_BOT_USER_OAUTH_TOKEN is a user token (xoxp-). Invoice review needs a Bot User OAuth Token (xoxb-…).'
            );
        }

        if (! str_starts_with($token, 'xoxb-')) {
            throw new \RuntimeException(
                'SLACK_BOT_USER_OAUTH_TOKEN must be a Bot User OAuth Token starting with xoxb-.'
            );
        }
    }

    private function formatSlackError(string $error): string
    {
        if ($error === 'invalid_auth') {
            return 'Slack rejected the bot token (invalid_auth). Use SLACK_WEBHOOK_URL like legacy CRM, or regenerate xoxb- token and run php artisan config:clear.';
        }

        if ($error === 'missing_scope') {
            return 'Slack bot is missing required scopes (chat:write). Prefer SLACK_WEBHOOK_URL like legacy Save Net instead of a bot token.';
        }

        if ($error === 'channel_not_found' || $error === 'not_in_channel') {
            return 'Slack bot is not in #accounting. Invite the bot or use SLACK_WEBHOOK_URL with BILLING_SLACK_ACCOUNTING_CHANNEL=#accounting.';
        }

        return 'Slack rejected the invoice review message ('.$error.').';
    }
}


