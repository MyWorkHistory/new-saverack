<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\Permission;
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
        $keys = array_merge(['inventory.view', 'inventory.update', 'clients.view'], $extraPermissionKeys);
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
            'order_qty' => $qty,
            'return_qty' => $qty,
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_assign_return_bin_on_received_return(): void
    {
        $account = $this->account();
        $return = $this->receivedReturn($account);
        $lineA = $this->line($return, 'SKU-A', 2);
        $this->line($return, 'SKU-B', 1, ['sort_order' => 1]);
        Sanctum::actingAs($this->staffUser());

        $this->patchJson('/api/admin/returns/'.$return->id.'/return-bin', [
            'return_bin_number' => 7,
        ])
            ->assertOk()
            ->assertJsonPath('return_bin_number', 7);

        $return->refresh();
        $this->assertSame(7, $return->return_bin_number);

        $lineA->refresh();
        $this->assertSame(7, $lineA->return_bin_number);
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
        Sanctum::actingAs($this->staffUser(['inventory.view']));

        $this->postJson('/api/admin/returns/'.$return->id.'/process', [
            'line_ids' => [$line->id],
            'return_bin_number' => 6,
        ])
            ->assertOk()
            ->assertJsonPath('return_bin_number', 6);

        $return->refresh();
        $this->assertSame(6, $return->return_bin_number);
        $this->assertSame(6, $line->fresh()->return_bin_number);
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
        Sanctum::actingAs($this->staffUser());

        $this->patchJson('/api/admin/returns/'.$return->id.'/return-bin', [
            'return_bin_number' => 3,
        ])->assertUnprocessable();
    }

    public function test_bins_list_returns_twenty_rows_with_counts(): void
    {
        $account = $this->account('list');
        $return = $this->receivedReturn($account, ['rma_number' => 'LS0001']);
        $this->line($return, 'SKU-1', 2);
        $this->line($return, 'SKU-2', 3, ['sort_order' => 1]);

        app(ReturnBinService::class)->assignReturnToBin($return, 4);

        Sanctum::actingAs($this->staffUser());

        $response = $this->getJson('/api/admin/returns/bins')
            ->assertOk()
            ->assertJsonCount(20, 'data');

        $binFour = collect($response->json('data'))->firstWhere('bin_number', 4);
        $this->assertSame(5, $binFour['items_count']);
        $binOne = collect($response->json('data'))->firstWhere('bin_number', 1);
        $this->assertSame(0, $binOne['items_count']);
    }

    public function test_bin_detail_aggregates_duplicate_skus(): void
    {
        $account = $this->account('agg');
        $returnA = $this->receivedReturn($account, ['rma_number' => 'AG0001']);
        $this->line($returnA, 'SKU-SAME', 2);
        $returnB = $this->receivedReturn($account, ['rma_number' => 'AG0002']);
        $this->line($returnB, 'SKU-SAME', 3);

        $service = app(ReturnBinService::class);
        $service->assignReturnToBin($returnA, 2);
        $service->assignReturnToBin($returnB, 2);

        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/bins/2/items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'SKU-SAME')
            ->assertJsonPath('data.0.qty', 5);
    }

    public function test_transfer_decrements_remaining_and_rejects_over_transfer(): void
    {
        $account = $this->account('xfer');
        $return = $this->receivedReturn($account, ['rma_number' => 'XF0001']);
        $this->line($return, 'SKU-X', 4);
        app(ReturnBinService::class)->assignReturnToBin($return, 5);

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

        $this->postJson('/api/admin/returns/bins/5/transfer', [
            'sku' => 'SKU-X',
            'client_account_id' => $account->id,
            'quantity' => 2,
            'warehouse_id' => 'wh-1',
            'to_location_id' => 'loc-pick-1',
        ])
            ->assertOk()
            ->assertJsonPath('transferred_qty', 2)
            ->assertJsonPath('remaining_qty', 2);

        $this->postJson('/api/admin/returns/bins/5/transfer', [
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
        app(ReturnBinService::class)->assignReturnToBin($return, 1);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
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

        $this->postJson('/api/admin/returns/bins/1/transfer', [
            'sku' => 'SKU-NC',
            'client_account_id' => $account->id,
            'quantity' => 1,
            'warehouse_id' => 'wh-1',
            'to_location_id' => 'loc-1',
        ])->assertOk();
    }
}
