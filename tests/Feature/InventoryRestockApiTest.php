<?php

namespace Tests\Feature;

use App\Models\InventoryRestockSnapshot;
use App\Models\Permission;
use App\Models\User;
use App\Services\InventoryRestockReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class InventoryRestockApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function staffWithInventoryView(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($permission->id);

        return $user;
    }

    public function test_get_restock_returns_latest_snapshot(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        InventoryRestockSnapshot::query()->create([
            'warehouse_id' => 'wh-1',
            'computed_at' => now(),
            'rows' => [
                ['sku' => 'A', 'name' => 'Alpha', 'pick_qty' => 1, 'backstock_qty' => 5],
            ],
            'row_count' => 1,
            'status' => InventoryRestockSnapshot::STATUS_OK,
            'duration_ms' => 100,
        ]);

        $mock = Mockery::mock(InventoryRestockReportService::class);
        $mock->shouldReceive('resolveWarehouseId')->andReturn('wh-1');
        $mock->shouldReceive('latestSnapshot')->once()->with(null, false)->andReturn([
            'warehouse_id' => 'wh-1',
            'computed_at' => now()->toIso8601String(),
            'rows' => [['sku' => 'A']],
            'row_count' => 1,
            'status' => 'ok',
            'error_message' => null,
            'duration_ms' => 100,
        ]);
        $this->app->instance(InventoryRestockReportService::class, $mock);

        $response = $this->getJson('/api/inventory/restock');

        $response->assertOk();
        $response->assertJsonPath('warehouse_id', 'wh-1');
        $response->assertJsonPath('row_count', 1);
    }

    public function test_post_refresh_dispatches_background_job(): void
    {
        Sanctum::actingAs($this->staffWithInventoryView());

        $mock = Mockery::mock(InventoryRestockReportService::class);
        $mock->shouldReceive('latestSnapshot')->once()->with(null, false)->andReturn(null);
        $mock->shouldReceive('isRefreshInProgress')->once()->with(null)->andReturn(false);
        $mock->shouldReceive('markRefreshRunning')->once()->with(null)->andReturn([
            'warehouse_id' => 'wh-1',
            'computed_at' => null,
            'rows' => [],
            'row_count' => 0,
            'status' => InventoryRestockSnapshot::STATUS_RUNNING,
            'error_message' => null,
            'duration_ms' => null,
            'refresh_started_at' => now()->toIso8601String(),
            'progress_page' => 0,
        ]);
        $mock->shouldReceive('dispatchRefreshJob')->once()->with(null);
        $this->app->instance(InventoryRestockReportService::class, $mock);

        $response = $this->postJson('/api/inventory/restock/refresh');

        $response->assertStatus(202);
        $response->assertJsonPath('status', InventoryRestockSnapshot::STATUS_RUNNING);
    }

    public function test_get_restock_resolves_stale_running_snapshot(): void
    {
        config(['services.shiphero.restock_warehouse_id' => 'wh-1']);
        config(['services.shiphero.restock_stale_minutes' => 20]);
        Sanctum::actingAs($this->staffWithInventoryView());

        InventoryRestockSnapshot::query()->create([
            'warehouse_id' => 'wh-1',
            'status' => InventoryRestockSnapshot::STATUS_RUNNING,
            'refresh_started_at' => now()->subMinutes(25),
            'computed_at' => now()->subHours(2),
            'duration_ms' => 418498,
            'rows' => [],
            'row_count' => 0,
        ]);

        $inventory = Mockery::mock(\App\Services\ShipHeroInventoryService::class);
        $this->app->instance(\App\Services\ShipHeroInventoryService::class, $inventory);

        $response = $this->getJson('/api/inventory/restock');

        $response->assertOk();
        $response->assertJsonPath('status', InventoryRestockSnapshot::STATUS_FAILED);
        $response->assertJsonPath('duration_ms', null);
        $response->assertJsonPath('computed_at', null);
        $this->assertStringContainsString('queue worker', (string) $response->json('error_message'));
    }
}
