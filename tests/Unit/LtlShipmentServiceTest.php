<?php

namespace Tests\Unit;

use App\Models\LtlShipment;
use App\Models\LtlShipmentPallet;
use App\Services\LtlShipmentService;
use App\Services\LtlShipmentSlackService;
use App\Services\SlackDeliveryService;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class LtlShipmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://app.saverack.com',
            'crm.frontend_url' => 'https://app.saverack.com',
            'ltl.slack_channel' => '#ltl-freight',
        ]);
    }

    public function test_quote_validation_requires_contact_email_and_pallet(): void
    {
        $shipment = new LtlShipment([
            'direction' => LtlShipment::DIRECTION_TO_SAVE_RACK,
            'company_name' => 'Acme',
            'address_line1' => '1 Main',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
            'contact_name' => 'Jane',
            'contact_phone' => '555',
            'time_mode' => LtlShipment::TIME_ASAP,
            'load_requirement' => 'dock',
            'pickup_type' => 'business',
        ]);
        $shipment->setRelation('pallets', new Collection);

        $errors = app(LtlShipmentService::class)->quoteValidationErrors($shipment);

        $this->assertContains('Contact email is required.', $errors);
        $this->assertContains('Add at least one pallet.', $errors);
    }

    public function test_quote_validation_passes_when_complete(): void
    {
        $shipment = new LtlShipment([
            'direction' => LtlShipment::DIRECTION_TO_SAVE_RACK,
            'company_name' => 'Acme',
            'address_line1' => '1 Main',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
            'contact_name' => 'Jane',
            'contact_email' => 'jane@example.com',
            'contact_phone' => '555',
            'time_mode' => LtlShipment::TIME_ASAP,
            'load_requirement' => 'dock',
            'pickup_type' => 'business',
        ]);
        $pallet = new LtlShipmentPallet([
            'commodity' => 'Boxes',
            'length_in' => 48,
            'width_in' => 40,
            'height_in' => 60,
            'weight_lbs' => 100,
        ]);
        $shipment->setRelation('pallets', new Collection([$pallet]));

        $this->assertSame([], app(LtlShipmentService::class)->quoteValidationErrors($shipment));
    }

    public function test_slack_quote_request_posts_to_ltl_freight_channel(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(true);
        $slack->expects($this->once())
            ->method('post')
            ->with(
                '#ltl-freight',
                $this->callback(function ($text) {
                    return str_contains((string) $text, 'Ship To Save Rack')
                        && str_contains((string) $text, 'Pallets: 2')
                        && str_contains((string) $text, 'View LTL')
                        && str_contains((string) $text, '/admin/receiving/ltl/9');
                }),
                'LTL Quote Request',
                $this->anything()
            )
            ->willReturn(['method' => 'bot', 'channel' => '#ltl-freight', 'ts' => '1.0']);

        $this->app->instance(SlackDeliveryService::class, $slack);

        $shipment = new LtlShipment([
            'direction' => LtlShipment::DIRECTION_TO_SAVE_RACK,
            'company_name' => 'Acme',
        ]);
        $shipment->id = 9;
        $shipment->setRelation('pallets', new Collection([
            new LtlShipmentPallet(['commodity' => 'A']),
            new LtlShipmentPallet(['commodity' => 'B']),
        ]));

        app(LtlShipmentSlackService::class)->notifyQuoteRequest($shipment);
    }
}
