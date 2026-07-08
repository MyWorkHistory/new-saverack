<?php

namespace Tests\Unit;

use App\Support\Billing\LegacyCustomerFeeImportMapper;
use Tests\TestCase;

final class LegacyCustomerFeeImportMapperTest extends TestCase
{
    public function test_dedupes_store_rows_to_one_per_customer_and_service(): void
    {
        $rows = [
            (object) [
                'customer' => 37,
                'store' => 52,
                'category' => 'Fulfillment Service',
                'service' => 'Fulfillment',
                'status' => 1,
                'is_deleted' => 1,
                'fee' => 1.25,
                'type' => 'Fulfillment',
                'fee_order' => 1,
                'updated_at' => '2022-10-31 13:59:11',
            ],
            (object) [
                'customer' => 37,
                'store' => 152,
                'category' => 'Fulfillment Service',
                'service' => 'Fulfillment',
                'status' => 1,
                'is_deleted' => 2,
                'fee' => 1.25,
                'type' => 'Fulfillment',
                'fee_order' => 1,
                'updated_at' => '2022-10-31 14:17:30',
            ],
            (object) [
                'customer' => 37,
                'store' => 187,
                'category' => 'Fulfillment Service',
                'service' => 'Additional Picks',
                'status' => 1,
                'is_deleted' => 1,
                'fee' => 0.25,
                'type' => 'Fulfillment',
                'fee_order' => 2,
                'updated_at' => '2022-10-31 14:17:11',
            ],
        ];

        $deduped = LegacyCustomerFeeImportMapper::dedupeByCustomerAndService($rows);

        $this->assertArrayHasKey(37, $deduped);
        $this->assertCount(2, $deduped[37]);
        $this->assertSame(1.25, $deduped[37]['template:Fulfillment']['fee']);
        $this->assertSame(0.25, $deduped[37]['template:Additional Picks']['fee']);
    }

    public function test_skips_postage_rows(): void
    {
        $rows = [
            (object) [
                'customer' => 28,
                'store' => null,
                'category' => null,
                'service' => null,
                'status' => 1,
                'is_deleted' => 1,
                'fee' => 0.20,
                'type' => 'Postage',
                'fee_order' => 20,
                'updated_at' => '2022-10-31 09:27:19',
            ],
        ];

        $deduped = LegacyCustomerFeeImportMapper::dedupeByCustomerAndService($rows);

        $this->assertSame([], $deduped);
    }
}
