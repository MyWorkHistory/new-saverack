<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\CustomBill;
use App\Services\BillCreatedSlackService;
use App\Services\SlackDeliveryService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class BillCreatedSlackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.saverack.com',
            'crm.frontend_url' => 'https://app.saverack.com',
            'billing.slack.billing_channel' => '#billing',
        ]);
    }

    public function test_build_message_payload_matches_expected_copy(): void
    {
        $payload = app(BillCreatedSlackService::class)->buildMessagePayload(
            'Acme Corp',
            123456,
            '1001',
            'https://app.saverack.com/admin/billing/bills/42'
        );

        $this->assertSame('Bill Created', $payload['username']);
        $this->assertSame(
            "Account: Acme Corp - \$1,234.56\n<https://app.saverack.com/admin/billing/bills/42|Bill #1001 - View Bill>",
            $payload['text']
        );
    }

    public function test_notify_posts_to_billing_channel(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(true);
        $slack->expects($this->once())
            ->method('post')
            ->with(
                '#billing',
                $this->callback(function ($text) {
                    return str_contains((string) $text, 'Account: Acme Corp - $50.00')
                        && str_contains((string) $text, 'Bill #1001 - View Bill')
                        && str_contains((string) $text, '/admin/billing/bills/42');
                }),
                'Bill Created',
                $this->anything()
            )
            ->willReturn(['method' => 'bot', 'channel' => '#billing', 'ts' => '1.0']);

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->once()->andReturnNull();

        $bill = new CustomBill([
            'bill_number' => 1001,
            'total_cents' => 5000,
        ]);
        $bill->id = 42;
        $bill->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Acme Corp',
        ]));

        app(BillCreatedSlackService::class)->notifyCustomBill($bill);
    }

    public function test_notify_failure_does_not_throw(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(false);
        $slack->method('post')->willThrowException(new \RuntimeException('slack down'));

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('warning')->once()->andReturnNull();

        $bill = new CustomBill([
            'bill_number' => 1001,
            'total_cents' => 5000,
        ]);
        $bill->id = 42;
        $bill->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Acme Corp',
        ]));

        app(BillCreatedSlackService::class)->notifyCustomBill($bill);

        $this->assertTrue(true);
    }
}
