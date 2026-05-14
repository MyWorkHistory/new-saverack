<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroOrderService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ShipHeroOrderServiceCountOrdersTest extends TestCase
{
    public function test_count_orders_sums_rows_across_pagination(): void
    {
        $client = $this->createMock(ShipHeroClient::class);
        $svc = new class($client) extends ShipHeroOrderService {
            private int $page = 0;

            public function listOrders(array $filters): array
            {
                $this->page++;
                if ($this->page === 1) {
                    return [
                        'rows' => [['id' => '1'], ['id' => '2']],
                        'pagination' => ['has_next_page' => true, 'end_cursor' => 'c1'],
                    ];
                }

                return [
                    'rows' => [['id' => '3']],
                    'pagination' => ['has_next_page' => false, 'end_cursor' => null],
                ];
            }
        };

        $out = $svc->countOrders([
            'customer_account_id' => 'acct-1',
            'tab' => 'awaiting',
        ]);

        $this->assertSame(3, $out['count']);
        $this->assertFalse($out['truncated']);
    }

    public function test_count_orders_rejects_manage_tab(): void
    {
        $client = $this->createMock(ShipHeroClient::class);
        $svc = new ShipHeroOrderService($client);

        $this->expectException(RuntimeException::class);
        $svc->countOrders([
            'customer_account_id' => 'acct-1',
            'tab' => 'manage',
        ]);
    }
}
