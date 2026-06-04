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
            'https://app.saverack.com/images/slack/shipping-status-paused.png',
            $payload['icon_url']
        );
        $this->assertSame(
            "Demo Company is set to Paused.\nUpdated by: Audi Kowalski\n<https://app.shiphero.com/3pl|Set Pause in Shiphero>",
            $payload['text']
        );
        $this->assertStringNotContainsString('View Account', $payload['text']);
        $this->assertStringNotContainsString('Shipping Status Update', $payload['text']);
        $this->assertArrayNotHasKey('blocks', $payload);
        $this->assertArrayNotHasKey('attachments', $payload);
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
            'https://app.saverack.com/images/slack/shipping-status-live.png',
            $payload['icon_url']
        );
        $this->assertSame(
            "Demo Account is set to Live.\nUpdated by: Audi Kowalski\n<https://app.shiphero.com/3pl|Set Live in Shiphero>",
            $payload['text']
        );
        $this->assertStringNotContainsString('Shipping Status Update', $payload['text']);
    }

    public function test_webhook_delivery_uses_username_and_icon_url_without_blocks(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => 'xoxb-test',
            'billing.slack.status_prefer_bot' => false,
        ]);

        $service = app(ClientAccountStatusSlackService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('deliveryOptions');
        $method->setAccessible(true);

        $text = "Demo is set to Live.\nUpdated by: Audi";
        $iconUrl = 'https://app.saverack.com/images/slack/shipping-status-live.png';
        $result = $method->invoke(
            $service,
            $text,
            'Shipping Status Update',
            $iconUrl,
            'Shipping live'
        );

        $this->assertSame('Shipping Status Update', $result['username']);
        $this->assertSame($text, $result['text']);
        $this->assertSame($iconUrl, $result['slack']['icon_url']);
        $this->assertArrayNotHasKey('blocks', $result['slack']);
        $this->assertArrayNotHasKey('prefer_bot', $result['slack']);
    }

    public function test_bot_only_delivery_uses_attachment_author_icon(): void
    {
        config([
            'billing.slack.webhook_url' => null,
            'billing.slack.bot_token' => 'xoxb-test',
        ]);

        $service = app(ClientAccountStatusSlackService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('deliveryOptions');
        $method->setAccessible(true);

        $text = "Demo is set to Paused.\nUpdated by: Audi";
        $iconUrl = 'https://app.saverack.com/images/slack/shipping-status-paused.png';
        $result = $method->invoke(
            $service,
            $text,
            'Shipping Status Update',
            $iconUrl,
            'Shipping paused'
        );

        $this->assertSame('Save Rack', $result['username']);
        $attachment = $result['slack']['attachments'][0];
        $this->assertSame('Shipping Status Update', $attachment['author_name']);
        $this->assertSame($iconUrl, $attachment['author_icon']);
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
        config(['billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x']);

        Http::fake([
            'https://app.saverack.com/images/slack/*' => Http::response('<html>', 404),
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
