<?php

namespace Tests\Unit;

use App\Models\Supply;
use App\Models\SupplyOrder;
use App\Models\SupplyOrderLine;
use App\Services\SlackDeliveryService;
use App\Services\SuppliesOrderedSlackService;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class SuppliesOrderedSlackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supplies.slack_channel' => '#supplies',
        ]);
    }

    public function test_build_message_payload_matches_expected_copy(): void
    {
        $order = new SupplyOrder();
        $order->setRelation('lines', new Collection([
            new SupplyOrderLine([
                'name' => '9x9x4',
                'type' => Supply::TYPE_BOX,
                'quantity' => 100,
            ]),
            new SupplyOrderLine([
                'name' => '6x9',
                'type' => Supply::TYPE_POLY_MAILER,
                'quantity' => 2000,
            ]),
        ]));

        $slack = $this->createMock(SlackDeliveryService::class);
        $service = new SuppliesOrderedSlackService($slack);

        $this->assertSame([
            'text' => "Box 9x9x4 - QTY: 100\nPoly Mailer 6x9 - QTY: 2000",
            'username' => 'Supplies Ordered',
        ], $service->buildMessagePayload($order));
    }

    public function test_send_posts_to_supplies_with_bot_identity(): void
    {
        $order = new SupplyOrder();
        $order->setRelation('lines', new Collection([
            new SupplyOrderLine([
                'name' => '9x9x4',
                'type' => Supply::TYPE_BOX,
                'quantity' => 100,
            ]),
        ]));

        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(true);
        $slack->expects($this->once())
            ->method('post')
            ->with(
                '#supplies',
                "Box 9x9x4 - QTY: 100",
                'Supplies Ordered',
                [
                    'customize_identity' => true,
                    'prefer_bot' => true,
                ]
            )
            ->willReturn(['method' => 'bot', 'channel' => '#supplies', 'ts' => '1.0']);

        $service = new SuppliesOrderedSlackService($slack);
        $result = $service->send($order);

        $this->assertSame('#supplies', $result['channel']);
    }
}
