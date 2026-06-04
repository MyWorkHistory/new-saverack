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

        config(['crm.frontend_url' => 'https://app.saverack.com']);
    }

    public function test_build_paused_message_includes_shiphero_link_and_truck_icon(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Demo Company',
            'in_house_slack' => 'demo-company',
        ]);
        $account->id = 451;

        $actor = new User(['name' => 'Taylor May']);
        $payload = app(ClientAccountStatusSlackService::class)->buildMessagePayload(
            $account,
            ClientAccount::STATUS_ACTIVE,
            ClientAccount::STATUS_PAUSED,
            $actor
        );

        $this->assertSame('Account Paused', $payload['username']);
        $this->assertSame(':truck:', $payload['icon_emoji']);
        $this->assertSame('d32f2f', $payload['attachments'][0]['color']);
        $this->assertStringContainsString('*Account Paused*', $payload['text']);
        $this->assertStringContainsString('Demo Company', $payload['text']);
        $this->assertStringContainsString('Please pause this account for shipments.', $payload['text']);
        $this->assertStringContainsString(
            '<https://app.shiphero.com/3pl|Pause in ShipHero>',
            $payload['text']
        );
        $this->assertStringContainsString('Updated by: Taylor May', $payload['text']);
        $this->assertStringContainsString(
            '<https://app.saverack.com/admin/clients/accounts/451|View Account>',
            $payload['text']
        );
    }

    public function test_build_live_message_includes_shiphero_link_and_green_attachment(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Live Co',
            'in_house_slack' => 'live-co',
        ]);
        $account->id = 12;

        $payload = app(ClientAccountStatusSlackService::class)->buildMessagePayload(
            $account,
            ClientAccount::STATUS_PAUSED,
            ClientAccount::STATUS_ACTIVE
        );

        $this->assertSame('Account Live', $payload['username']);
        $this->assertSame(':truck:', $payload['icon_emoji']);
        $this->assertSame('2e7d32', $payload['attachments'][0]['color']);
        $this->assertStringContainsString('*Account Live*', $payload['text']);
        $this->assertStringContainsString('Please set this account live for shipments.', $payload['text']);
        $this->assertStringContainsString(
            '<https://app.shiphero.com/3pl|Pause in ShipHero>',
            $payload['text']
        );
    }

    public function test_build_generic_message_for_other_status_changes(): void
    {
        $account = new ClientAccount(['company_name' => 'Demo Company']);
        $account->id = 1;

        $text = app(ClientAccountStatusSlackService::class)->buildMessageText(
            $account,
            ClientAccount::STATUS_ACTIVE,
            ClientAccount::STATUS_INACTIVE
        );

        $this->assertStringContainsString('Account status changed: Demo Company — Active → Inactive', $text);
        $this->assertStringNotContainsString('*Account Paused*', $text);
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
        $this->assertSame(
            '#demo-company',
            $slack->channelFromInHouseSlack('https://saverack.slack.com/app_redirect?channel=demo-company')
        );
    }
}
