<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroOrderService;
use PHPUnit\Framework\TestCase;

final class ShipHeroOrderServiceCountShipmentsTest extends TestCase
{
    public function test_count_shipments_sums_pagination(): void
    {
        $client = $this->createMock(ShipHeroClient::class);
        $client->method('query')->willReturnOnConsecutiveCalls(
            [
                'data' => [
                    'shipments' => [
                        'data' => [
                            'edges' => [
                                ['node' => ['id' => 's1']],
                                ['node' => ['id' => 's2']],
                            ],
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1'],
                        ],
                    ],
                ],
            ],
            [
                'data' => [
                    'shipments' => [
                        'data' => [
                            'edges' => [
                                ['node' => ['id' => 's3']],
                            ],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ],
            ],
        );

        $svc = new ShipHeroOrderService($client);
        $out = $svc->countShipments([
            'customer_account_id' => 'acct-esas',
            'date_from' => '2026-05-27T04:00:00-04:00',
            'date_to' => '2026-05-28T03:59:59-04:00',
        ]);

        $this->assertSame(3, $out['count']);
        $this->assertFalse($out['truncated']);
    }
}
