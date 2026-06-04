<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientAccountStatusSlackService;
use App\Services\SlackDeliveryService;
use App\Services\SlackStatusIconUrlService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class ClientAccountStatusSlackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.saverack.com',
            'crm.frontend_url' => 'https://app.saverack.com',
            'billing.slack.public_asset_base_url' => 'https://app.saverack.com',
        ]);
    }

    private function iconBase(): string
    {
        return 'https://app.saverack.com/images/slack';
    }

    public function test_build_paused_message_body_only_once(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Demo Company',
            'in_house_slack' => 'demo-company',
        ]);
        $account->id = 451;

        $actor = new User(['name' => 'Audi Kowalski']);
        $payload = app(ClientAccountStatusSlackService::class)->buildMessagePayload(
            $account,
            ClientAccount::STATUS_ACTIVE,
            ClientAccount::STATUS_PAUSED,
            $actor
        );

        $this->assertNotNull($payload);
        $this->assertSame('Shipping Status Update', $payload['username']);
        $this->assertSame(
            "Demo Company is set to Paused.\nUpdated by: Audi Kowalski\n<https://app.shiphero.com/3pl|Set Pause in Shiphero>",
            $payload['text']
        );
        $this->assertStringNotContainsString('View Account', $payload['text']);
        $this->assertStringNotContainsString('Shipping Status Update', $payload['text']);
    }

    public function test_build_live_message_body_only_once(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Demo Account',
            'in_house_slack' => 'live-co',
        ]);

        $actor = new User(['name' => 'Audi Kowalski']);
        $payload = app(ClientAccountStatusSlackService::class)->buildMessagePayload(
            $account,
            ClientAccount::STATUS_PAUSED,
            ClientAccount::STATUS_ACTIVE,
            $actor
        );

        $this->assertNotNull($payload);
        $this->assertSame(
            "Demo Account is set to Live.\nUpdated by: Audi Kowalski\n<https://app.shiphero.com/3pl|Set Live in Shiphero>",
            $payload['text']
        );
        $this->assertStringNotContainsString('Shipping Status Update', $payload['text']);
    }

    public function test_delivery_without_bot_includes_blocks_header_and_body(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => null,
        ]);

        $service = app(ClientAccountStatusSlackService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('deliveryOptions');
        $method->setAccessible(true);

        $text = "Demo is set to Live.\nUpdated by: Audi";
        $iconUrl = $this->iconBase().'/shipping-status-live-thumb.png';
        $result = $method->invoke(
            $service,
            $text,
            'Shipping Status Update',
            $iconUrl,
            'Live'
        );

        $this->assertSame('Shipping Status Update', $result['username']);
        $this->assertSame($iconUrl, $result['slack']['icon_url']);
        $this->assertArrayHasKey('blocks', $result['slack']);
        $blocks = $result['slack']['blocks'];
        $this->assertSame('context', $blocks[0]['type']);
        $this->assertSame('image', $blocks[0]['elements'][0]['type']);
        $this->assertSame($iconUrl, $blocks[0]['elements'][0]['image_url']);
        $this->assertStringContainsString('Shipping Status Update', $blocks[0]['elements'][1]['text']);
        $this->assertSame('section', $blocks[1]['type']);
        $this->assertSame($text, $blocks[1]['text']['text']);
    }

    public function test_delivery_uses_bot_customize_identity_and_blocks(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => 'xoxb-test',
        ]);

        $service = app(ClientAccountStatusSlackService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('deliveryOptions');
        $method->setAccessible(true);

        $iconUrl = $this->iconBase().'/shipping-status-live-thumb.png';
        $body = "Demo is set to Live.\nUpdated by: Audi";
        $result = $method->invoke(
            $service,
            $body,
            'Shipping Status Update',
            $iconUrl,
            'Live'
        );

        $this->assertSame('Shipping Status Update', $result['username']);
        $this->assertTrue($result['slack']['customize_identity']);
        $this->assertTrue($result['slack']['prefer_bot']);
        $this->assertSame($iconUrl, $result['slack']['icon_url']);
        $this->assertArrayHasKey('blocks_for_webhook_fallback', $result['slack']);
        $this->assertSame($body, $result['slack']['blocks_for_webhook_fallback'][1]['text']['text']);
    }

    public function test_other_status_changes_do_not_build_slack_payload(): void
    {
        $account = new ClientAccount(['company_name' => 'Demo Company']);
        $payload = app(ClientAccountStatusSlackService::class)->buildMessagePayload(
            $account,
            ClientAccount::STATUS_ACTIVE,
            ClientAccount::STATUS_INACTIVE
        );

        $this->assertNull($payload);
    }

    public function test_notify_live_still_posts_when_icon_fetch_fails(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => 'xoxb-test-token',
        ]);

        Http::fake([
            'app.saverack.com/*' => Http::response('<html>', 404),
            'https://slack.com/api/*' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '1.0'], 200),
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        Log::shouldReceive('info')->andReturnNull();

        $account = new ClientAccount([
            'company_name' => 'Demo',
            'in_house_slack' => 'demo-co',
        ]);
        $account->id = 1;

        app(ClientAccountStatusSlackService::class)->notifyStatusChange(
            $account,
            ClientAccount::STATUS_PAUSED,
            ClientAccount::STATUS_ACTIVE
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat.postMessage')) {
                return false;
            }

            $payload = $request->data();
            $blocks = $payload['blocks'] ?? [];

            return ($payload['username'] ?? '') === 'Shipping Status Update'
                && str_contains((string) ($payload['text'] ?? ''), 'Demo is set to Live.');
        });
    }

    public function test_notify_without_bot_still_posts_via_webhook_with_blocks(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => null,
        ]);

        Http::fake([
            'app.saverack.com/images/slack/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        Log::shouldReceive('info')->andReturnNull();

        $account = new ClientAccount([
            'company_name' => 'Demo',
            'in_house_slack' => 'demo-co',
        ]);
        $account->id = 1;

        app(ClientAccountStatusSlackService::class)->notifyStatusChange(
            $account,
            ClientAccount::STATUS_ACTIVE,
            ClientAccount::STATUS_PAUSED
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'hooks.slack.com')) {
                return false;
            }

            $payload = $request->data();
            $blocks = $payload['blocks'] ?? [];

            return str_contains((string) ($payload['icon_url'] ?? ''), '/images/slack/shipping-status-paused-thumb.png')
                && ($payload['text'] ?? '') === 'Shipping Status Update'
                && str_contains((string) ($blocks[1]['text']['text'] ?? ''), 'Demo is set to Paused.')
                && ! array_key_exists('attachments', $payload);
        });
    }

    public function test_channel_from_in_house_slack_supports_archive_url_and_slug(): void
    {
        $slack = app(SlackDeliveryService::class);

        $this->assertSame(
            'C02CKSH0HDE',
            $slack->channelFromInHouseSlack('https://saverack.slack.com/archives/C02CKSH0HDE')
        );
        $this->assertSame(
            '#demo-company',
            $slack->channelFromInHouseSlack('demo-company')
        );
    }
}
