<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientAccountStatusSlackService;
use App\Services\SlackDeliveryService;
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

    /**
     * @return array<string, mixed>
     */
    private function sampleAccount(): ClientAccount
    {
        $account = new ClientAccount([
            'company_name' => 'Demo Company',
            'in_house_slack' => 'demo-company',
        ]);
        $account->id = 451;

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleActor(): User
    {
        return new User(['name' => 'Audi Kowalski']);
    }

    public function test_build_paused_message_body_only_once(): void
    {
        $payload = app(ClientAccountStatusSlackService::class)->buildMessagePayload(
            $this->sampleAccount(),
            ClientAccount::STATUS_ACTIVE,
            ClientAccount::STATUS_PAUSED,
            $this->sampleActor()
        );

        $this->assertNotNull($payload);
        $this->assertSame('Shipping Status Update', $payload['username']);
        $this->assertSame(
            "Demo Company is set to Paused.\nUpdated by: Audi Kowalski\n<https://app.shiphero.com/3pl|Set Pause in Shiphero>",
            $payload['text']
        );
        $this->assertStringContainsString('/shipping-status-paused-thumb.png', $payload['icon_url']);
        $this->assertStringContainsString('/shipping-status-paused.png', $payload['icon_url_fallbacks'][0]);
        $this->assertStringNotContainsString('View Account', $payload['text']);
        $this->assertStringNotContainsString('Shipping Status Update', $payload['text']);
    }

    public function test_build_live_message_body_only_once(): void
    {
        $payload = app(ClientAccountStatusSlackService::class)->buildMessagePayload(
            $this->sampleAccount(),
            ClientAccount::STATUS_PAUSED,
            ClientAccount::STATUS_ACTIVE,
            $this->sampleActor()
        );

        $this->assertNotNull($payload);
        $this->assertSame(
            "Demo Company is set to Live.\nUpdated by: Audi Kowalski\n<https://app.shiphero.com/3pl|Set Live in Shiphero>",
            $payload['text']
        );
        $this->assertStringContainsString('/shipping-status-live-thumb.png', $payload['icon_url']);
        $this->assertStringContainsString('/shipping-status-live.png', $payload['icon_url_fallbacks'][0]);
        $this->assertStringNotContainsString('Shipping Status Update', $payload['text']);
    }

    public function test_live_and_paused_payloads_share_same_shape(): void
    {
        $service = app(ClientAccountStatusSlackService::class);
        $account = $this->sampleAccount();
        $actor = $this->sampleActor();

        $live = $service->buildMessagePayload($account, ClientAccount::STATUS_PAUSED, ClientAccount::STATUS_ACTIVE, $actor);
        $paused = $service->buildMessagePayload($account, ClientAccount::STATUS_ACTIVE, ClientAccount::STATUS_PAUSED, $actor);

        $this->assertNotNull($live);
        $this->assertNotNull($paused);
        $this->assertSame(array_keys($live), array_keys($paused));
        $this->assertSame('Shipping Status Update', $live['username']);
        $this->assertSame('Shipping Status Update', $paused['username']);
        $this->assertArrayNotHasKey('blocks', $live);
        $this->assertArrayNotHasKey('blocks', $paused);
        $this->assertStringContainsString('is set to Live.', $live['text']);
        $this->assertStringContainsString('is set to Paused.', $paused['text']);
        $this->assertStringContainsString('live', $live['icon_url']);
        $this->assertStringContainsString('paused', $paused['icon_url']);
    }

    public function test_delivery_without_bot_sends_icon_url_and_body_text(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => null,
        ]);

        $service = app(ClientAccountStatusSlackService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('deliveryOptions');
        $method->setAccessible(true);

        $iconUrl = $this->iconBase().'/shipping-status-live-thumb.png';
        $result = $method->invoke(
            $service,
            'Shipping Status Update',
            $iconUrl,
            [$this->iconBase().'/shipping-status-live.png']
        );

        $this->assertSame('Shipping Status Update', $result['username']);
        $this->assertSame($iconUrl, $result['slack']['icon_url']);
        $this->assertArrayNotHasKey('blocks', $result['slack']);
        $this->assertArrayNotHasKey('attachments', $result['slack']);
        $this->assertArrayNotHasKey('customize_identity', $result['slack']);
        $this->assertArrayNotHasKey('bot_only', $result['slack']);
    }

    public function test_delivery_uses_bot_customize_identity_without_blocks(): void
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
        $result = $method->invoke(
            $service,
            'Shipping Status Update',
            $iconUrl,
            [$this->iconBase().'/shipping-status-live.png']
        );

        $this->assertSame('Shipping Status Update', $result['username']);
        $this->assertTrue($result['slack']['customize_identity']);
        $this->assertTrue($result['slack']['prefer_bot']);
        $this->assertTrue($result['slack']['bot_only']);
        $this->assertSame($iconUrl, $result['slack']['icon_url']);
        $this->assertSame([$this->iconBase().'/shipping-status-live.png'], $result['slack']['icon_url_fallbacks']);
        $this->assertArrayNotHasKey('blocks', $result['slack']);
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

    public function test_notify_live_posts_via_bot_with_native_header_fields(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => 'xoxb-test-token',
        ]);

        Http::fake([
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

            return ($payload['username'] ?? '') === 'Shipping Status Update'
                && str_contains((string) ($payload['icon_url'] ?? ''), 'shipping-status-live-thumb.png')
                && str_contains((string) ($payload['text'] ?? ''), 'Demo is set to Live.')
                && ! array_key_exists('blocks', $payload);
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'hooks.slack.com');
        });
    }

    public function test_notify_paused_posts_via_bot_with_native_header_fields(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => 'xoxb-test-token',
        ]);

        Http::fake([
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
            ClientAccount::STATUS_ACTIVE,
            ClientAccount::STATUS_PAUSED
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat.postMessage')) {
                return false;
            }

            $payload = $request->data();

            return ($payload['username'] ?? '') === 'Shipping Status Update'
                && str_contains((string) ($payload['icon_url'] ?? ''), 'shipping-status-paused-thumb.png')
                && str_contains((string) ($payload['text'] ?? ''), 'Demo is set to Paused.')
                && ! array_key_exists('blocks', $payload);
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'hooks.slack.com');
        });
    }

    public function test_notify_without_bot_still_posts_via_webhook_with_body_text(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => null,
        ]);

        Http::fake([
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

            return str_contains((string) ($payload['icon_url'] ?? ''), '/images/slack/shipping-status-paused-thumb.png')
                && str_contains((string) ($payload['text'] ?? ''), 'Demo is set to Paused.')
                && ($payload['username'] ?? '') === 'Shipping Status Update'
                && ! array_key_exists('blocks', $payload);
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
