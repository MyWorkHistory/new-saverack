<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientAccountStatusSlackService;
use App\Services\SlackDeliveryService;
use App\Services\SlackStatusIconUrlService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

        Storage::fake('public');
    }

    private function iconBase(): string
    {
        return 'https://app.saverack.com/storage/slack-status-icons';
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
            $this->iconBase().'/shipping-status-paused.png',
            $payload['icon_url']
        );
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
            $this->iconBase().'/shipping-status-live.png',
            $payload['icon_url']
        );
        $this->assertSame(
            "Demo Account is set to Live.\nUpdated by: Audi Kowalski\n<https://app.shiphero.com/3pl|Set Live in Shiphero>",
            $payload['text']
        );
        $this->assertStringNotContainsString('Shipping Status Update', $payload['text']);
    }

    public function test_delivery_uses_bot_customize_identity_for_truck_icon(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => 'xoxb-test',
        ]);

        $service = app(ClientAccountStatusSlackService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('deliveryOptions');
        $method->setAccessible(true);

        $iconUrl = $this->iconBase().'/shipping-status-live.png';
        $result = $method->invoke(
            $service,
            "Demo is set to Live.\nUpdated by: Audi",
            'Shipping Status Update',
            $iconUrl
        );

        $this->assertSame('Shipping Status Update', $result['username']);
        $this->assertTrue($result['slack']['customize_identity']);
        $this->assertTrue($result['slack']['prefer_bot']);
        $this->assertSame($iconUrl, $result['slack']['icon_url']);
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

    public function test_explicit_cdn_icon_url_override(): void
    {
        config([
            'billing.slack.status_icon_live_url' => 'https://cdn.example.com/live.png',
        ]);

        $account = new ClientAccount(['company_name' => 'Demo']);
        $payload = app(ClientAccountStatusSlackService::class)->buildMessagePayload(
            $account,
            ClientAccount::STATUS_PAUSED,
            ClientAccount::STATUS_ACTIVE
        );

        $this->assertSame('https://cdn.example.com/live.png', $payload['icon_url']);
    }

    public function test_icon_unreachable_logs_warning_but_does_not_throw(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => 'xoxb-test-token',
        ]);

        $iconUrl = $this->iconBase().'/shipping-status-paused.png';

        Http::fake([
            $iconUrl => Http::response('<html>', 404),
            'https://slack.com/api/*' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '1.0'], 200),
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'client_account.status_slack_icon_unreachable'
                    && ($context['http_status'] ?? null) === 404;
            });

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
    }

    public function test_notify_without_bot_logs_failure_and_does_not_use_webhook(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => null,
        ]);

        Http::fake([
            'app.saverack.com/storage/slack-status-icons/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
        ]);

        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

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

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'hooks.slack.com');
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
