<?php

namespace Tests\Unit;

use App\Jobs\TransferInventoryLocationJob;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class TransferInventoryLocationJobTest extends TestCase
{
    public function test_job_carries_restock_next_status_for_post_transfer_apply(): void
    {
        $job = new TransferInventoryLocationJob([
            'sku' => 'SKU-1',
            'warehouse_id' => 'WH1',
            'from_location_id' => 'LOC-A',
            'to_location_id' => 'LOC-B',
            'quantity' => 3,
            'reason' => 'Restock',
            'restock_next_status' => 'complete',
        ]);

        $this->assertSame('complete', $job->restockNextStatus);
        $this->assertNull($job->restockPreviousStatus);
    }

    public function test_failed_with_next_status_only_caches_error_without_requiring_previous(): void
    {
        $job = new TransferInventoryLocationJob([
            'sku' => 'SKU-1',
            'warehouse_id' => 'WH1',
            'from_location_id' => 'LOC-A',
            'to_location_id' => 'LOC-B',
            'quantity' => 3,
            'reason' => 'Restock',
            'restock_next_status' => 'complete',
        ]);

        $job->failed(new RuntimeException('ShipHero down'));

        $this->assertSame(
            'ShipHero down',
            Cache::get(TransferInventoryLocationJob::RESTOCK_ERROR_CACHE_PREFIX.'sku-1')
        );
    }
}
