<?php

namespace Tests\Unit;

use App\Jobs\RefreshInventoryRestockReportJob;
use App\Models\InventoryRestockSnapshot;
use App\Services\InventoryRestockReportService;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InventoryRestockReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function serviceWithWarehouse(string $warehouseId = 'wh-1'): InventoryRestockReportService
    {
        $inventory = Mockery::mock(ShipHeroInventoryService::class);
        $inventory->shouldReceive('listWarehouses')->andReturn([['id' => $warehouseId]]);
        $this->app->instance(ShipHeroInventoryService::class, $inventory);

        return $this->app->make(InventoryRestockReportService::class);
    }

    public function test_stale_running_snapshot_marked_failed_on_latest_snapshot(): void
    {
        config(['services.shiphero.restock_stale_minutes' => 20]);
        config(['services.shiphero.restock_stall_minutes' => 20]);
        $service = $this->serviceWithWarehouse();

        InventoryRestockSnapshot::query()->create([
            'warehouse_id' => 'wh-1',
            'status' => InventoryRestockSnapshot::STATUS_RUNNING,
            'refresh_started_at' => now()->subMinutes(25),
            'rows' => [],
            'row_count' => 0,
        ]);

        $snapshot = $service->latestSnapshot('wh-1');

        $this->assertNotNull($snapshot);
        $this->assertSame(InventoryRestockSnapshot::STATUS_FAILED, $snapshot['status']);
        $this->assertStringContainsString('Refresh', (string) $snapshot['error_message']);
        $this->assertNull($snapshot['computed_at']);
        $this->assertNull($snapshot['duration_ms']);
    }

    public function test_is_refresh_in_progress_false_after_stale_resolution(): void
    {
        config(['services.shiphero.restock_stale_minutes' => 20]);
        config(['services.shiphero.restock_stall_minutes' => 20]);
        $service = $this->serviceWithWarehouse();

        InventoryRestockSnapshot::query()->create([
            'warehouse_id' => 'wh-1',
            'status' => InventoryRestockSnapshot::STATUS_RUNNING,
            'refresh_started_at' => now()->subMinutes(25),
            'rows' => [],
            'row_count' => 0,
        ]);

        $this->assertFalse($service->isRefreshInProgress('wh-1'));
    }

    public function test_meta_snapshot_does_not_load_rows_column(): void
    {
        $service = $this->serviceWithWarehouse();

        $allRows = [];
        for ($i = 0; $i < 75; $i++) {
            $allRows[] = ['sku' => 'SKU-'.$i, 'name' => 'Item '.$i, 'pick_qty' => 1];
        }

        InventoryRestockSnapshot::query()->create([
            'warehouse_id' => 'wh-1',
            'status' => InventoryRestockSnapshot::STATUS_OK,
            'computed_at' => now(),
            'refresh_started_at' => null,
            'rows' => $allRows,
            'row_count' => count($allRows),
        ]);

        $meta = $service->latestSnapshot('wh-1', false);
        $this->assertNotNull($meta);
        $this->assertSame([], $meta['rows']);
        $this->assertSame(75, $meta['row_count']);
        $this->assertArrayNotHasKey('has_more_rows', $meta);

        $page1 = $service->latestSnapshot('wh-1', true, 0, 50);
        $this->assertNotNull($page1);
        $this->assertCount(50, $page1['rows']);
        $this->assertTrue($page1['has_more_rows']);
        $this->assertSame(0, $page1['rows_offset']);
        $this->assertSame(50, $page1['rows_limit']);

        $page2 = $service->latestSnapshot('wh-1', true, 50, 50);
        $this->assertNotNull($page2);
        $this->assertCount(25, $page2['rows']);
        $this->assertFalse($page2['has_more_rows']);
    }

    public function test_mark_refresh_running_clears_stale_metadata_in_response(): void
    {
        $service = $this->serviceWithWarehouse();

        InventoryRestockSnapshot::query()->create([
            'warehouse_id' => 'wh-1',
            'computed_at' => now()->subDay(),
            'duration_ms' => 99999,
            'rows' => [['sku' => 'OLD']],
            'row_count' => 1,
            'status' => InventoryRestockSnapshot::STATUS_OK,
        ]);

        $payload = $service->markRefreshRunning('wh-1');

        $this->assertSame(InventoryRestockSnapshot::STATUS_RUNNING, $payload['status']);
        $this->assertNull($payload['computed_at']);
        $this->assertNull($payload['duration_ms']);
        $this->assertSame([], $payload['rows']);
        $this->assertNotNull($payload['refresh_started_at']);
    }

    public function test_mark_refresh_failed_only_updates_running_row(): void
    {
        $service = $this->serviceWithWarehouse();

        InventoryRestockSnapshot::query()->create([
            'warehouse_id' => 'wh-1',
            'status' => InventoryRestockSnapshot::STATUS_RUNNING,
            'refresh_started_at' => now(),
            'rows' => [],
            'row_count' => 0,
        ]);

        $service->markRefreshFailed('wh-1', 'Job exploded');

        $row = InventoryRestockSnapshot::query()->where('warehouse_id', 'wh-1')->first();
        $this->assertNotNull($row);
        $this->assertSame(InventoryRestockSnapshot::STATUS_FAILED, $row->status);
        $this->assertSame('Job exploded', $row->error_message);
    }

    public function test_job_failed_marks_snapshot_failed(): void
    {
        $service = $this->serviceWithWarehouse();

        InventoryRestockSnapshot::query()->create([
            'warehouse_id' => 'wh-1',
            'status' => InventoryRestockSnapshot::STATUS_RUNNING,
            'refresh_started_at' => now(),
            'rows' => [],
            'row_count' => 0,
        ]);

        $job = new RefreshInventoryRestockReportJob('wh-1');
        $job->failed(new RuntimeException('Worker timeout'));

        $row = InventoryRestockSnapshot::query()->where('warehouse_id', 'wh-1')->first();
        $this->assertNotNull($row);
        $this->assertSame(InventoryRestockSnapshot::STATUS_FAILED, $row->status);
        $this->assertSame('Worker timeout', $row->error_message);
    }
}
