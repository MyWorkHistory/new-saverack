<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Post Slack messages via legacy webhook (channel in payload) or bot token.
 */
class SlackDeliveryService
{
    /**
     * @param  array{
     *     icon_emoji?: string|null,
     *     icon_url?: string|null,
     *     attachments?: array<int, array<string, mixed>>|null,
     *     blocks?: array<int, array<string, mixed>>|null
     * }  $options
     * @return array{method: string, channel: string, ts: string|null}
     */
    public function post(string $channel, string $text, string $username = 'Save Rack', array $options = []): array
    {
        $channel = $this->normalizeChannelName($channel);
        if ($channel === '') {
            throw new \RuntimeException('Slack channel is required.');
        }

        $iconUrl = isset($options['icon_url']) ? trim((string) $options['icon_url']) : '';
        $iconEmoji = $iconUrl === '' && isset($options['icon_emoji'])
            ? trim((string) $options['icon_emoji'])
            : '';
        $attachments = $options['attachments'] ?? null;
        if (! is_array($attachments)) {
            $attachments = null;
        }
        $blocks = $options['blocks'] ?? null;
        if (! is_array($blocks)) {
            $blocks = null;
        }

        $webhookUrl = $this->normalizeWebhookUrl((string) config('billing.slack.webhook_url', ''));
        if ($webhookUrl !== '') {
            $this->postViaWebhook($webhookUrl, $channel, $text, $username, $iconEmoji, $attachments, $iconUrl, $blocks);

            return ['method' => 'webhook', 'channel' => $channel, 'ts' => null];
        }

        $token = $this->normalizeBotToken((string) config('billing.slack.bot_token', ''));
        if ($token === '') {
            throw new \RuntimeException(
                'Slack is not configured. Set SLACK_WEBHOOK_URL (legacy) or BILLING_SLACK_INCOMING_WEBHOOK_URL in .env, or SLACK_BOT_USER_OAUTH_TOKEN (xoxb-…).'
            );
        }

        $this->assertBotTokenShape($token);
        $this->joinChannelIfPossible($token, $channel);

        $payload = [
            'channel' => $channel,
            'text' => $text,
            'mrkdwn' => true,
        ];
        if ($iconUrl !== '') {
            $payload['icon_url'] = $iconUrl;
        } elseif ($iconEmoji !== '') {
            $payload['icon_emoji'] = $iconEmoji;
        }
        if ($attachments !== null && $attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->post('https://slack.com/api/chat.postMessage', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Could not send message to Slack.');
        }

        $body = $response->json();
        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            $error = is_array($body) ? (string) ($body['error'] ?? 'unknown_error') : 'unknown_error';
            throw new \RuntimeException($this->formatSlackError($error));
        }

        $ts = isset($body['ts']) ? trim((string) $body['ts']) : '';
        $postedChannel = isset($body['channel']) ? trim((string) $body['channel']) : $channel;

        return ['method' => 'bot', 'channel' => $postedChannel, 'ts' => $ts !== '' ? $ts : null];
    }

    public function normalizeChannelName(string $channel): string
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

    /**
     * Resolve a Slack channel id or #name from stored in-house Slack values.
     */
    public function channelFromInHouseSlack(?string $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        if (preg_match('#/archives/(C[A-Z0-9]+)#', $s, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/[?&]channel=([^&]+)/', $s, $matches) === 1) {
            $slug = trim(urldecode($matches[1]));
            $slug = ltrim($slug, '#');

            return $slug !== '' ? '#'.$slug : null;
        }

        if (preg_match('/^C[A-Z0-9]+$/', $s) === 1) {
            return $s;
        }

        if (preg_match('#^https?://#i', $s) === 1) {
            return null;
        }

        return $this->normalizeChannelName(ltrim($s, '#'));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $attachments
     */
    /**
     * @param  array<int, array<string, mixed>>|null  $blocks
     */
    private function postViaWebhook(
        string $webhookUrl,
        string $channel,
        string $text,
        string $username,
        string $iconEmoji = '',
        ?array $attachments = null,
        string $iconUrl = '',
        ?array $blocks = null
    ): void {
        $payload = [
            'channel' => $channel,
            'username' => $username,
            'text' => $text,
            'mrkdwn' => true,
        ];
        if ($iconUrl !== '') {
            $payload['icon_url'] = $iconUrl;
        } elseif ($iconEmoji !== '') {
            $payload['icon_emoji'] = $iconEmoji;
        }
        if ($attachments !== null && $attachments !== []) {
            $payload['attachments'] = $attachments;
        }
        if ($blocks !== null && $blocks !== []) {
            $payload['blocks'] = $blocks;
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->post($webhookUrl, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Could not send message to Slack webhook.');
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
            throw new \RuntimeException('Slack webhook could not post to '.$channel.'.');
        }

        throw new \RuntimeException('Slack webhook rejected the message.');
    }

    private function joinChannelIfPossible(string $token, string $channel): void
    {
        if (preg_match('/^C[A-Z0-9]+$/', $channel) === 1) {
            return;
        }

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
                Log::warning('slack_delivery_join_failed', [
                    'channel' => $channel,
                    'error' => $error,
                ]);
            }
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
        if ($url === '' || ! str_starts_with($url, 'https://hooks.slack.com/')) {
            return '';
        }

        return $url;
    }

    private function assertBotTokenShape(string $token): void
    {
        if (str_starts_with($token, 'https://hooks.slack.com/')) {
            throw new \RuntimeException(
                'SLACK_BOT_USER_OAUTH_TOKEN looks like a webhook URL. Put that URL in SLACK_WEBHOOK_URL instead.'
            );
        }

        if (str_starts_with($token, 'xoxp-')) {
            throw new \RuntimeException(
                'SLACK_BOT_USER_OAUTH_TOKEN is a user token (xoxp-). Use a Bot User OAuth Token (xoxb-…).'
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
            return 'Slack rejected the bot token (invalid_auth). Use SLACK_WEBHOOK_URL like legacy CRM, or regenerate xoxb- token.';
        }

        if ($error === 'missing_scope') {
            return 'Slack bot is missing required scopes (chat:write). Prefer SLACK_WEBHOOK_URL like legacy Save Net.';
        }

        if ($error === 'channel_not_found' || $error === 'not_in_channel') {
            return 'Slack bot is not in the target channel. Invite the bot or use SLACK_WEBHOOK_URL.';
        }

        return 'Slack rejected the message ('.$error.').';
    }
}
