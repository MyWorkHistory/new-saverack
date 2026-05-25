<?php

namespace Tests\Unit;

use App\Support\OrderShipmentTracking;
use PHPUnit\Framework\TestCase;

class OrderShipmentTrackingTest extends TestCase
{
    public function test_skips_void_labels_and_builds_usps_tracking_url_for_endicia(): void
    {
        $result = OrderShipmentTracking::fromShipHeroShipments([
            [
                'shipping_labels' => [
                    [
                        'id' => 'void-1',
                        'status' => 'void',
                        'tracking_number' => '111',
                        'carrier' => 'endicia',
                        'shipping_name' => 'USPS Priority Mail',
                        'cost' => '5.00',
                    ],
                    [
                        'id' => 'live-1',
                        'status' => 'printed',
                        'tracking_number' => '9405550105800027557451',
                        'carrier' => 'endicia',
                        'shipping_name' => 'USPS Priority Mail',
                        'shipping_method' => 'Priority',
                        'cost' => '7.25',
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $result['labels']);
        $this->assertSame('USPS Priority Mail', $result['labels'][0]['service_label']);
        $this->assertSame(
            'https://tools.usps.com/tracking/?strOrigTrackNum=9405550105800027557451',
            $result['labels'][0]['tracking_url']
        );
        $this->assertSame(7.25, $result['total_label_cost']);
    }

    public function test_includes_multiple_tracking_numbers(): void
    {
        $result = OrderShipmentTracking::fromShipHeroShipments([
            [
                'shipping_labels' => [
                    [
                        'id' => 'a',
                        'tracking_number' => 'TRACKONE',
                        'carrier' => 'ups',
                        'shipping_name' => 'UPS Ground',
                        'cost' => '3.00',
                    ],
                    [
                        'id' => 'b',
                        'tracking_number' => 'TRACKTWO',
                        'carrier' => 'ups',
                        'shipping_name' => 'UPS Ground',
                        'cost' => '2.50',
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $result['labels']);
        $this->assertSame(5.5, $result['total_label_cost']);
    }
}
