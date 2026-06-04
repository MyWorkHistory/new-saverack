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

    public function test_build_message_includes_status_change_and_view_link(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Demo Company',
            'in_house_slack' => 'demo-company',
        ]);
        $account->id = 451;

        $actor = new User(['name' => 'Taylor May']);
        $text = app(ClientAccountStatusSlackService::class)->buildMessageText(
            $account,
            ClientAccount::STATUS_ACTIVE,
            ClientAccount::STATUS_PAUSED,
            $actor
        );

        $this->assertStringContainsString('Account status changed: Demo Company — Active → Paused', $text);
        $this->assertStringContainsString('Updated by: Taylor May', $text);
        $this->assertStringContainsString(
            '<https://app.saverack.com/admin/clients/accounts/451|View Account>',
            $text
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
        $this->assertSame(
            '#demo-company',
            $slack->channelFromInHouseSlack('https://saverack.slack.com/app_redirect?channel=demo-company')
        );
    }
}
