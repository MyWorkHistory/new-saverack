<?php

namespace Tests\Unit;

use App\Services\SlackDeliveryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SlackDeliveryServiceCustomizeTest extends TestCase
{
    public function test_bot_post_includes_username_and_icon_url_when_customizing(): void
    {
        config(['billing.slack.bot_token' => 'xoxb-test-token']);

        Http::fake([
            'https://slack.com/api/conversations.join' => Http::response(['ok' => true], 200),
            'https://slack.com/api/chat.postMessage' => Http::response([
                'ok' => true,
                'channel' => 'C123',
                'ts' => '1.0',
            ], 200),
        ]);

        app(SlackDeliveryService::class)->post(
            '#demo-co',
            'Hello',
            'Shipping Status Update',
            [
                'icon_url' => 'https://app.saverack.com/images/slack/shipping-status-live.png',
                'customize_identity' => true,
                'prefer_bot' => true,
            ]
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat.postMessage')) {
                return false;
            }

            $body = $request->data();

            return ($body['username'] ?? '') === 'Shipping Status Update'
                && str_contains((string) ($body['icon_url'] ?? ''), 'shipping-status-live.png')
                && ($body['text'] ?? '') === 'Hello';
        });
    }

    public function test_customize_identity_without_bot_uses_webhook(): void
    {
        config([
            'billing.slack.bot_token' => null,
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
        ]);

        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

        $result = app(SlackDeliveryService::class)->post(
            '#demo-co',
            'Hello',
            'Shipping Status Update',
            ['customize_identity' => true, 'icon_url' => 'https://example.com/icon.png']
        );

        $this->assertSame('webhook', $result['method']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'hooks.slack.com')
                && ($request->data()['text'] ?? '') === 'Hello';
        });
    }

    public function test_customize_identity_falls_back_to_webhook_when_bot_fails(): void
    {
        config([
            'billing.slack.bot_token' => 'xoxb-test-token',
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
        ]);

        Http::fake([
            'https://slack.com/api/conversations.join' => Http::response(['ok' => true], 200),
            'https://slack.com/api/chat.postMessage' => Http::response([
                'ok' => false,
                'error' => 'not_in_channel',
            ], 200),
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $result = app(SlackDeliveryService::class)->post(
            '#demo-co',
            'Hello',
            'Shipping Status Update',
            [
                'icon_url' => 'https://app.saverack.com/images/slack/shipping-status-live-thumb.png',
                'customize_identity' => true,
                'prefer_bot' => true,
            ]
        );

        $this->assertSame('webhook', $result['method']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'hooks.slack.com')) {
                return false;
            }

            $payload = $request->data();

            return ($payload['text'] ?? '') === 'Hello'
                && str_contains((string) ($payload['icon_url'] ?? ''), 'shipping-status-live-thumb.png')
                && ! array_key_exists('attachments', $payload);
        });
    }
}
