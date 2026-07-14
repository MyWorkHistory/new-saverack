<?php

namespace Tests\Unit;

use App\Models\OrderDashboardSection;
use App\Services\OrderDashboardSnapshotService;
use PHPUnit\Framework\TestCase;

class PausedOnHoldOrderCountTest extends TestCase
{
    public function test_sum_paused_on_hold_orders_from_sections(): void
    {
        $sections = [
            OrderDashboardSection::KEY_ON_HOLD => [
                'accounts' => [
                    [
                        'account_id' => 1,
                        'account_name' => 'Active Co',
                        'account_status' => 'active',
                        'orders_count' => 10,
                    ],
                    [
                        'account_id' => 2,
                        'account_name' => 'Paused Co',
                        'account_status' => 'paused',
                        'orders_count' => 4,
                    ],
                    [
                        'account_id' => 3,
                        'account_name' => 'Paused Two',
                        'account_status' => 'paused',
                        'orders_count' => 6,
                    ],
                ],
                'total_count' => 20,
            ],
        ];

        $this->assertSame(10, OrderDashboardSnapshotService::sumPausedOnHoldOrdersFromSections($sections));
    }

    public function test_sum_paused_returns_zero_without_accounts(): void
    {
        $this->assertSame(0, OrderDashboardSnapshotService::sumPausedOnHoldOrdersFromSections([]));
    }
}
