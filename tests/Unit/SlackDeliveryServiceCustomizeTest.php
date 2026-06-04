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
                'icon_url' => 'https://app.saverack.com/storage/slack-status-icons/shipping-status-live.png',
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
}
