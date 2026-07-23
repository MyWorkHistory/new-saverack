<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\Permission;
use App\Models\ReturnBin;
use App\Models\User;
use App\Services\ReturnBinService;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AdminReturnBinWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function permission(string $key, string $module): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            ['label' => $key, 'module' => $module]
        );
    }

    private function staffUser(array $extraPermissionKeys = []): User
    {
        $user = User::factory()->create(['client_account_id' => null, 'name' => 'Bin Staff']);
        $keys = array_merge(['returns.view', 'returns.update', 'clients.view'], $extraPermissionKeys);
        foreach ($keys as $key) {
            $perm = $this->permission($key, explode('.', $key)[0]);
            $user->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $user;
    }

    private function account(string $suffix = 'bin'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Return Bin Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-bin-'.$suffix,
        ]);
    }

    private function makeBin(string $name = 'Bin A'): ReturnBin
    {
        return ReturnBin::query()->create(['name' => $name]);
    }

    private function receivedReturn(ClientAccount $account, array $overrides = []): ClientAccountReturn
    {
        return ClientAccountReturn::query()->create(array_merge([
            'client_account_id' => $account->id,
            'rma_number' => 'BN1234',
            'status' => ClientAccountReturn::STATUS_RECEIVED,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-bin-1',
            'order_number' => '500',
            'customer_name' => 'Customer',
            'items_count' => 2,
            'processed_at' => now(),
        ], $overrides));
    }

    private function line(
        ClientAccountReturn $return,
        string $sku,
        int $qty,
        array $overrides = []
    ): ClientAccountReturnLine {
        return ClientAccountReturnLine::query()->create(array_merge([
            'client_account_return_id' => $return->id,
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'image_url' => 'https://example.com/'.$sku.'.jpg',
            'order_qty' => $qty,
            'return_qty' => $qty,
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_create_rename_clear_and_delete_bin(): void
    {
        Sanctum::actingAs($this->staffUser());

        $create = $this->postJson('/api/admin/returns/bins', ['name' => 'Returns Front'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Returns Front')
            ->assertJsonPath('data.items_count', 0);

        $binId = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $binId);

        $this->patchJson('/api/admin/returns/bins/'.$binId, ['name' => 'Returns Back'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Returns Back');

        $account = $this->account('crud');
        $return = $this->receivedReturn($account, ['rma_number' => 'CR0001']);
        $this->line($return, 'SKU-CR', 2);
        app(ReturnBinService::class)->assignReturnToBin($return, $binId);

        $this->getJson('/api/admin/returns/bins')
            ->assertOk()
            ->assertJsonFragment(['id' => $binId, 'name' => 'Returns Back', 'items_count' => 2]);

        $this->deleteJson('/api/admin/returns/bins/'.$binId)
            ->assertUnprocessable();

        $this->postJson('/api/admin/returns/bins/'.$binId.'/clear')
            ->assertOk()
            ->assertJsonPath('data.items_count', 0);

        $this->assertSame(0, (int) ClientAccountReturnLine::query()->where('sku', 'SKU-CR')->value('return_bin_remaining_qty'));
        $this->assertNull(ClientAccountReturnLine::query()->where('sku', 'SKU-CR')->value('return_bin_id'));

        $this->deleteJson('/api/admin/returns/bins/'.$binId)
            ->assertOk();

        $this->assertNull(ReturnBin::query()->find($binId));
    }

    public function test_assign_return_bin_on_received_return(): void
    {
        $account = $this->account();
        $return = $this->receivedReturn($account);
        $lineA = $this->line($return, 'SKU-A', 2);
        $this->line($return, 'SKU-B', 1, ['sort_order' => 1]);
        $bin = $this->makeBin('Staging 7');
        Sanctum::actingAs($this->staffUser());

        $this->patchJson('/api/admin/returns/'.$return->id.'/return-bin', [
            'return_bin_id' => $bin->id,
        ])
            ->assertOk()
            ->assertJsonPath('return_bin_id', $bin->id)
            ->assertJsonPath('return_bin_name', 'Staging 7');

        $return->refresh();
        $this->assertSame($bin->id, $return->return_bin_id);
        $this->assertNull($return->return_bin_number);

        $lineA->refresh();
        $this->assertSame($bin->id, $lineA->return_bin_id);
        $this->assertSame(2, $lineA->return_bin_remaining_qty);
    }

    public function test_process_pending_return_assigns_bin(): void
    {
        $account = $this->account('proc');
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'PR1111',
            'status' => ClientAccountReturn::STATUS_PENDING,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-pr',
            'order_number' => '2',
            'items_count' => 1,
        ]);
        $line = $this->line($return, 'SKU-PR', 1);
        $bin = $this->makeBin('Process Bin');
        Sanctum::actingAs($this->staffUser(['returns.view']));

        $this->postJson('/api/admin/returns/'.$return->id.'/process', [
            'line_ids' => [$line->id],
            'return_bin_id' => $bin->id,
        ])
            ->assertOk()
            ->assertJsonPath('return_bin_id', $bin->id)
            ->assertJsonPath('return_bin_name', 'Process Bin');

        $return->refresh();
        $this->assertSame($bin->id, $return->return_bin_id);
        $this->assertSame($bin->id, $line->fresh()->return_bin_id);
    }

    public function test_pending_return_cannot_assign_bin(): void
    {
        $account = $this->account();
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'PD1111',
            'status' => ClientAccountReturn::STATUS_PENDING,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-pd',
            'order_number' => '1',
            'items_count' => 1,
        ]);
        $this->line($return, 'SKU-P', 1);
        $bin = $this->makeBin('Pending Block');
        Sanctum::actingAs($this->staffUser());

        $this->patchJson('/api/admin/returns/'.$return->id.'/return-bin', [
            'return_bin_id' => $bin->id,
        ])->assertUnprocessable();
    }

    public function test_bins_list_returns_created_bins_with_counts(): void
    {
        $account = $this->account('list');
        $return = $this->receivedReturn($account, ['rma_number' => 'LS0001']);
        $this->line($return, 'SKU-1', 2);
        $this->line($return, 'SKU-2', 3, ['sort_order' => 1]);
        $bin = $this->makeBin('List Bin');
        $empty = $this->makeBin('Empty Bin');

        app(ReturnBinService::class)->assignReturnToBin($return, (int) $bin->id);

        Sanctum::actingAs($this->staffUser());

        $response = $this->getJson('/api/admin/returns/bins')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $listed = collect($response->json('data'));
        $this->assertSame(5, $listed->firstWhere('id', $bin->id)['items_count']);
        $this->assertSame(0, $listed->firstWhere('id', $empty->id)['items_count']);
    }

    public function test_bin_detail_aggregates_from_db_without_shiphero(): void
    {
        $account = $this->account('agg');
        $returnA = $this->receivedReturn($account, ['rma_number' => 'AG0001']);
        $this->line($returnA, 'SKU-SAME', 2, ['pick_location' => 'A-01']);
        $returnB = $this->receivedReturn($account, ['rma_number' => 'AG0002']);
        $this->line($returnB, 'SKU-SAME', 3, ['pick_location' => 'A-01']);
        $bin = $this->makeBin('Agg Bin');

        $service = app(ReturnBinService::class);
        $service->assignReturnToBin($returnA, (int) $bin->id);
        $service->assignReturnToBin($returnB, (int) $bin->id);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldNotReceive('getProductDetailBySku');
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/bins/'.$bin->id.'/items')
            ->assertOk()
            ->assertJsonPath('bin.id', $bin->id)
            ->assertJsonPath('bin.name', 'Agg Bin')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'SKU-SAME')
            ->assertJsonPath('data.0.qty', 5)
            ->assertJsonPath('data.0.image_url', 'https://example.com/SKU-SAME.jpg')
            ->assertJsonPath('data.0.pick_location', 'A-01');
    }

    public function test_transfer_decrements_remaining_and_rejects_over_transfer(): void
    {
        $account = $this->account('xfer');
        $return = $this->receivedReturn($account, ['rma_number' => 'XF0001']);
        $this->line($return, 'SKU-X', 4);
        $bin = $this->makeBin('Transfer Bin');
        app(ReturnBinService::class)->assignReturnToBin($return, (int) $bin->id);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')->andReturn([
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [
                        [
                            'location_id' => 'loc-pick-1',
                            'location_name' => 'A-01',
                            'quantity' => 1,
                            'pickable' => true,
                        ],
                    ],
                ],
            ],
        ]);
        $mock->shouldReceive('addLocationQuantity')
            ->once()
            ->with(
                'SKU-X',
                'wh-1',
                'loc-pick-1',
                2,
                Mockery::on(fn (string $reason) => str_contains($reason, 'Return Restock RMA# XF0001')),
                'sh-bin-xfer'
            )
            ->andReturn([]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/bins/'.$bin->id.'/transfer', [
            'sku' => 'SKU-X',
            'client_account_id' => $account->id,
            'quantity' => 2,
            'warehouse_id' => 'wh-1',
            'to_location_id' => 'loc-pick-1',
        ])
            ->assertOk()
            ->assertJsonPath('transferred_qty', 2)
            ->assertJsonPath('remaining_qty', 2);

        $this->postJson('/api/admin/returns/bins/'.$bin->id.'/transfer', [
            'sku' => 'SKU-X',
            'client_account_id' => $account->id,
            'quantity' => 99,
            'warehouse_id' => 'wh-1',
            'to_location_id' => 'loc-pick-1',
        ])->assertUnprocessable();
    }

    public function test_non_compliant_transfer_uses_non_compliant_reason(): void
    {
        $account = $this->account('nc');
        $return = $this->receivedReturn($account, [
            'rma_number' => 'NC9999',
            'is_non_compliant' => true,
            'non_compliant_reason' => 'unable_to_identify_customer',
        ]);
        $this->line($return, 'SKU-NC', 1);
        $bin = $this->makeBin('NC Bin');
        app(ReturnBinService::class)->assignReturnToBin($return, (int) $bin->id);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')->andReturn(null);
        $mock->shouldReceive('addLocationQuantity')
            ->once()
            ->with(
                'SKU-NC',
                'wh-1',
                'loc-1',
                1,
                Mockery::on(fn (string $reason) => str_contains($reason, 'Return Restock (Non-Compliant)')),
                'sh-bin-nc'
            )
            ->andReturn([]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/bins/'.$bin->id.'/transfer', [
            'sku' => 'SKU-NC',
            'client_account_id' => $account->id,
            'quantity' => 1,
            'warehouse_id' => 'wh-1',
            'to_location_id' => 'loc-1',
        ])->assertOk();
    }
}
