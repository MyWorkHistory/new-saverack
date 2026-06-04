<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientAccountStatusSlackService;
use App\Services\SlackDeliveryService;
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
            "Demo Account is set to Live.\nUpdated by: Audi Kowalski\n<https://app.shiphero.com/3pl|Set Live in Shiphero>",
            $payload['text']
        );
        $this->assertStringNotContainsString('Shipping Status Update', $payload['text']);
    }

    public function test_webhook_delivery_uses_username_and_icon_url_only(): void
    {
        config(['billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x']);

        $service = app(ClientAccountStatusSlackService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('deliveryOptions');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            "Demo is set to Live.\nUpdated by: Audi",
            'Shipping Status Update',
            'https://app.saverack.com/images/slack/shipping-status-live.png'
        );

        $this->assertSame('Shipping Status Update', $result['username']);
        $this->assertSame("Demo is set to Live.\nUpdated by: Audi", $result['text']);
        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-live.png',
            $result['slack']['icon_url']
        );
        $this->assertArrayNotHasKey('blocks', $result['slack']);
        $this->assertArrayNotHasKey('attachments', $result['slack']);
    }

    public function test_bot_delivery_uses_attachment_author_icon_not_body_image(): void
    {
        config(['billing.slack.webhook_url' => null, 'billing.slack.bot_token' => 'xoxb-test']);

        $service = app(ClientAccountStatusSlackService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('deliveryOptions');
        $method->setAccessible(true);

        $text = "Demo is set to Paused.\nUpdated by: Audi";
        $result = $method->invoke(
            $service,
            $text,
            'Shipping Status Update',
            'https://app.saverack.com/images/slack/shipping-status-paused.png'
        );

        $this->assertSame('Save Rack', $result['username']);
        $this->assertArrayHasKey('attachments', $result['slack']);
        $attachment = $result['slack']['attachments'][0];
        $this->assertSame('Shipping Status Update', $attachment['author_name']);
        $this->assertSame($text, $attachment['text']);
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
