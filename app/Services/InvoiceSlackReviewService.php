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

        $webhookUrl = $this->normalizeWebhookUrl((string) config('billing.slack.webhook_url', ''));
        if ($webhookUrl !== '') {
            $this->postViaWebhook($webhookUrl, $text);

            return [
                'method' => 'webhook',
                'channel' => trim((string) (config('billing.slack.accounting_channel') ?: '#accounting-support')),
                'ts' => null,
            ];
        }

        $token = $this->normalizeBotToken((string) config('billing.slack.bot_token', ''));
        if ($token === '') {
            throw new \RuntimeException(
                'Slack is not configured for invoice review. Set SLACK_BOT_USER_OAUTH_TOKEN (xoxb-…) or BILLING_SLACK_INCOMING_WEBHOOK_URL in .env.'
            );
        }

        $this->assertBotTokenShape($token);

        $channel = $this->normalizeChannelName(
            trim((string) (config('billing.slack.accounting_channel') ?: '#accounting'))
        );
        if ($channel === '') {
            throw new \RuntimeException(
                'Slack accounting channel is not configured. Set BILLING_SLACK_ACCOUNTING_CHANNEL=#accounting in .env.'
            );
        }

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
        if ($body === 'ok') {
            return;
        }

        $json = $response->json();
        if (is_array($json) && ($json['ok'] ?? false) === true) {
            return;
        }

        throw new \RuntimeException(
            'Slack webhook rejected the invoice review message. Use a webhook created for #accounting-support (BILLING_SLACK_INCOMING_WEBHOOK_URL), not LOG_SLACK_WEBHOOK_URL.'
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
            return 'Slack rejected the bot token (invalid_auth). Regenerate the Bot User OAuth Token (xoxb-…) in Slack → your app → OAuth & Permissions, reinstall the app to the workspace, update .env, then run php artisan config:clear. Or use BILLING_SLACK_INCOMING_WEBHOOK_URL for #accounting-support instead.';
        }

        if ($error === 'missing_scope') {
            return 'Slack bot is missing required scopes. In api.slack.com → your app → OAuth & Permissions → Bot Token Scopes, add chat:write, channels:join (for private #accounting-support), then Reinstall to Workspace and update SLACK_BOT_USER_OAUTH_TOKEN in .env. Or use BILLING_SLACK_INCOMING_WEBHOOK_URL instead.';
        }

        if ($error === 'channel_not_found' || $error === 'not_in_channel') {
            return 'Slack bot is not in #accounting-support. Invite the bot to the channel (/invite @YourBot), add channels:join scope, or use BILLING_SLACK_INCOMING_WEBHOOK_URL for that channel.';
        }

        return 'Slack rejected the invoice review message ('.$error.').';
    }
}
