<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\Permission;
use App\Models\ReturnBill;
use App\Models\ReturnBin;
use App\Models\ShipHeroOrderQueueIndex;
use App\Models\User;
use App\Services\ReturnBinService;
use App\Services\ShipHeroInventoryService;
use App\Support\Billing\ReturnBillChargeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AdminReturnProcessWorkflowTest extends TestCase
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
        $user = User::factory()->create(['client_account_id' => null]);
        $keys = array_merge(['returns.view', 'clients.view'], $extraPermissionKeys);
        foreach ($keys as $key) {
            $perm = $this->permission($key, explode('.', $key)[0]);
            $user->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $user;
    }

    private function account(string $suffix = '1'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Return Admin Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-ret-admin-'.$suffix,
        ]);
    }

    private function seedReturnFees(ClientAccount $account): void
    {
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => ClientAccountFee::LINE_RETURNS_PROCESSING,
            'label' => 'Return Fee (1st Item)',
            'amount' => '3.0000',
            'currency' => 'USD',
            'sort_order' => 0,
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_RETURNS,
            'line_code' => ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS,
            'label' => 'Return Fee (Additional Items)',
            'amount' => '1.0000',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);
    }

    private function returnForAccount(ClientAccount $account, array $overrides = []): ClientAccountReturn
    {
        return ClientAccountReturn::query()->create(array_merge([
            'client_account_id' => $account->id,
            'rma_number' => 'AB1234',
            'status' => ClientAccountReturn::STATUS_PENDING,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-sh-100',
            'order_number' => '84842',
            'customer_name' => 'Jane Customer',
            'items_count' => 2,
        ], $overrides));
    }

    private function lineForReturn(ClientAccountReturn $return, array $overrides = []): ClientAccountReturnLine
    {
        return ClientAccountReturnLine::query()->create(array_merge([
            'client_account_return_id' => $return->id,
            'sku' => 'SKU-1',
            'name' => 'Product One',
            'order_qty' => 2,
            'return_qty' => 1,
            'return_reason' => 'damaged',
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @return \Mockery\MockInterface&ShipHeroInventoryService
     */
    private function mockInventoryStaging()
    {
        config(['services.shiphero.returns_warehouse_id' => 'wh-returns-test']);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')->andReturn(null)->byDefault();
        $mock->shouldReceive('resolveWarehouseLocation')
            ->andReturnUsing(function ($warehouseId, $locationInput) {
                $name = trim((string) $locationInput);

                return [
                    'id' => 'loc-'.strtolower(str_replace(' ', '-', $name)),
                    'name' => $name,
                ];
            })
            ->byDefault();
        $mock->shouldReceive('resolveProductWarehouseLocation')->andReturn(null)->byDefault();
        $mock->shouldReceive('addLocationQuantity')
            ->andReturn([
                'warehouse_id' => 'wh-returns-test',
                'warehouse_name' => 'Main',
                'locations' => [],
            ])
            ->byDefault();
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        return $mock;
    }

    public function test_pending_list_returns_only_pending_returns(): void
    {
        $account = $this->account();
        $pending = $this->returnForAccount($account, ['rma_number' => 'PD0001']);
        $this->returnForAccount($account, [
            'rma_number' => 'RC0001',
            'status' => ClientAccountReturn::STATUS_RECEIVED,
            'processed_at' => now(),
        ]);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.display_status', 'pending');
    }

    public function test_order_lookup_not_returned_when_order_exists_without_return(): void
    {
        $account = $this->account();
        ShipHeroOrderQueueIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'order-sh-999',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED,
            'order_number' => '84842',
            'order_number_search' => '84842',
            'recipient_name' => 'Emily Stewart',
            'last_seen_at' => now(),
            'indexed_at' => now(),
        ]);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/order-lookup?order_number=84842')
            ->assertOk()
            ->assertJsonPath('display_status', 'not_returned')
            ->assertJsonPath('client_account_id', $account->id)
            ->assertJsonPath('order.order_number', '84842')
            ->assertJsonPath('order.id', 'order-sh-999')
            ->assertJsonPath('return', null);
    }

    public function test_order_lookup_pending_when_return_exists(): void
    {
        $account = $this->account();
        $return = $this->returnForAccount($account);
        ShipHeroOrderQueueIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'order-sh-100',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED,
            'order_number' => '84842',
            'order_number_search' => '84842',
            'recipient_name' => 'Emily Stewart',
            'last_seen_at' => now(),
            'indexed_at' => now(),
        ]);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/order-lookup?order_number=84842')
            ->assertOk()
            ->assertJsonPath('display_status', 'pending')
            ->assertJsonPath('return.id', $return->id);
    }

    public function test_order_lookup_works_without_client_account_id(): void
    {
        $account = $this->account('x');
        ShipHeroOrderQueueIndex::query()->create([
            'client_account_id' => $account->id,
            'shiphero_order_id' => 'order-sh-db-1',
            'queue_kind' => ShipHeroOrderQueueIndex::KIND_SHIPPED,
            'order_number' => '#DB-77',
            'order_number_search' => 'db-77',
            'recipient_name' => 'Pat Lee',
            'last_seen_at' => now(),
            'indexed_at' => now(),
        ]);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/order-lookup?order_number=DB-77')
            ->assertOk()
            ->assertJsonPath('client_account_id', $account->id)
            ->assertJsonPath('client_account_company_name', 'Return Admin Co x')
            ->assertJsonPath('order.id', 'order-sh-db-1')
            ->assertJsonPath('display_status', 'not_returned');
    }

    public function test_rma_lookup_finds_returned_status(): void
    {
        $account = $this->account();
        $return = $this->returnForAccount($account, [
            'rma_number' => 'XY9999',
            'status' => ClientAccountReturn::STATUS_RECEIVED,
            'processed_at' => now(),
        ]);
        Sanctum::actingAs($this->staffUser());

        $this->getJson('/api/admin/returns/rma-lookup?rma_number=RMA%20XY9999')
            ->assertOk()
            ->assertJsonPath('data.id', $return->id)
            ->assertJsonPath('data.display_status', 'returned');
    }

    public function test_process_return_auto_assigns_return_cart_when_restocking(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        $return = $this->returnForAccount($account);
        $lineA = $this->lineForReturn($return, ['sku' => 'A', 'return_qty' => 2, 'restock' => true]);
        Sanctum::actingAs($this->staffUser());
        $mock = $this->mockInventoryStaging();
        $mock->shouldReceive('addLocationQuantity')
            ->once()
            ->withArgs(function ($sku, $warehouseId, $locationId, $qty) {
                return $sku === 'A'
                    && $locationId === 'loc-return-cart'
                    && (int) $qty === 2;
            })
            ->andReturn(['warehouse_id' => 'wh-returns-test', 'warehouse_name' => 'Main', 'locations' => []]);

        $this->postJson('/api/admin/returns/'.$return->id.'/process', [
            'line_ids' => [$lineA->id],
            'restock_by_line_id' => [$lineA->id => true],
        ])
            ->assertOk()
            ->assertJsonPath('status', ClientAccountReturn::STATUS_RECEIVED)
            ->assertJsonPath('return_bin_name', ReturnBinService::BIN_RETURN_CART);

        $lineA->refresh();
        $bin = ReturnBin::query()->find($lineA->return_bin_id);
        $this->assertNotNull($bin);
        $this->assertSame(ReturnBinService::BIN_RETURN_CART, $bin->name);
        $this->assertSame(2, (int) $lineA->return_bin_remaining_qty);
    }

    public function test_process_return_auto_assigns_dispose_bin_when_not_restocking(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        $return = $this->returnForAccount($account);
        $lineA = $this->lineForReturn($return, ['sku' => 'A', 'return_qty' => 1, 'restock' => false]);
        Sanctum::actingAs($this->staffUser());
        $mock = $this->mockInventoryStaging();
        $mock->shouldReceive('addLocationQuantity')
            ->once()
            ->withArgs(function ($sku, $warehouseId, $locationId, $qty) {
                return $sku === 'A'
                    && $locationId === 'loc-dispose-bin'
                    && (int) $qty === 1;
            })
            ->andReturn(['warehouse_id' => 'wh-returns-test', 'warehouse_name' => 'Main', 'locations' => []]);

        $this->postJson('/api/admin/returns/'.$return->id.'/process', [
            'line_ids' => [$lineA->id],
            'restock_by_line_id' => [$lineA->id => false],
        ])
            ->assertOk()
            ->assertJsonPath('return_bin_name', ReturnBinService::BIN_DISPOSE);
    }

    public function test_process_return_sets_received_and_zeros_unselected_lines(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        $return = $this->returnForAccount($account);
        $lineA = $this->lineForReturn($return, ['sku' => 'A', 'return_qty' => 2]);
        $lineB = $this->lineForReturn($return, ['sku' => 'B', 'return_qty' => 1, 'sort_order' => 1]);
        $bin = ReturnBin::query()->create(['name' => 'Process Bin 5']);
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);
        $this->mockInventoryStaging();

        $this->postJson('/api/admin/returns/'.$return->id.'/process', [
            'line_ids' => [$lineA->id],
            'restock_by_line_id' => [$lineA->id => true],
            'return_bin_id' => $bin->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', ClientAccountReturn::STATUS_RECEIVED)
            ->assertJsonPath('return_fees.locked', true)
            ->assertJsonPath('return_bin_id', $bin->id)
            ->assertJsonPath('return_bin_name', 'Process Bin 5')
            ->assertJsonPath('processed_by_name', $staff->name);

        $return->refresh();
        $this->assertSame(ClientAccountReturn::STATUS_RECEIVED, $return->status);
        $this->assertNotNull($return->processed_at);
        $this->assertSame($staff->id, $return->processed_by_user_id);
        $this->assertSame($bin->id, $return->return_bin_id);
        $this->assertNotNull($return->fees_locked_at);
        $this->assertNotNull($return->return_bill_id);
        $this->assertSame(2, (int) $return->items_count);
        $this->assertSame(0, (int) $lineB->fresh()->return_qty);
        $this->assertTrue((bool) $lineA->fresh()->restock);
        $this->assertSame($bin->id, $lineA->fresh()->return_bin_id);
        $this->assertSame(2, $lineA->fresh()->return_bin_remaining_qty);
        $this->assertSame(ReturnBill::STATUS_OPEN, ReturnBill::query()->find($return->return_bill_id)->status);

        $bill = ReturnBill::query()->with('items')->find($return->return_bill_id);
        $first = $bill->items->firstWhere('line_type', ReturnBill::LINE_FIRST_ITEM);
        $this->assertNotNull($first);
        $this->assertSame(ReturnBillChargeCatalog::FIRST_ITEM_NAME, $first->name);
    }

    public function test_admin_process_from_draft_skips_pending_and_creates_bill(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'AD0001',
            'status' => ClientAccountReturn::STATUS_DRAFT,
            'created_source' => ClientAccountReturn::SOURCE_ADMIN,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-admin-1',
            'order_number' => '90001',
            'customer_name' => 'Admin Customer',
            'items_count' => 0,
            'return_fee_first_item' => 3.0,
            'return_fee_additional_item' => 1.0,
        ]);
        Sanctum::actingAs($this->staffUser());
        $this->mockInventoryStaging();

        $this->postJson('/api/admin/returns/'.$return->id.'/process-from-draft', [
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'lines' => [
                [
                    'sku' => 'SKU-X',
                    'name' => 'Product X',
                    'order_qty' => 2,
                    'return_qty' => 2,
                    'return_reason' => 'unknown',
                    'restock' => true,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', ClientAccountReturn::STATUS_RECEIVED)
            ->assertJsonPath('return_fees.locked', true)
            ->assertJsonPath('return_bin_name', ReturnBinService::BIN_RETURN_CART);

        $return->refresh();
        $this->assertSame(ClientAccountReturn::STATUS_RECEIVED, $return->status);
        $this->assertNotNull($return->return_bill_id);
        $this->assertSame('unknown', $return->lines()->first()->return_reason);
    }

    public function test_process_from_draft_routes_restock_false_to_dispose_bin(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'AD0099',
            'status' => ClientAccountReturn::STATUS_DRAFT,
            'created_source' => ClientAccountReturn::SOURCE_ADMIN,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-admin-dispose',
            'order_number' => '90099',
            'customer_name' => 'Admin Customer',
            'items_count' => 0,
            'return_fee_first_item' => 3.0,
            'return_fee_additional_item' => 1.0,
        ]);
        Sanctum::actingAs($this->staffUser());
        $mock = $this->mockInventoryStaging();
        $mock->shouldReceive('addLocationQuantity')
            ->once()
            ->withArgs(function ($sku, $warehouseId, $locationId) {
                return $sku === 'SKU-D' && $locationId === 'loc-dispose-bin';
            })
            ->andReturn(['warehouse_id' => 'wh-returns-test', 'warehouse_name' => 'Main', 'locations' => []]);

        $this->postJson('/api/admin/returns/'.$return->id.'/process-from-draft', [
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'lines' => [
                [
                    'sku' => 'SKU-D',
                    'name' => 'Product D',
                    'order_qty' => 1,
                    'return_qty' => 1,
                    'return_reason' => 'unknown',
                    'restock' => false,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('return_bin_name', ReturnBinService::BIN_DISPOSE);
    }

    public function test_process_from_draft_allows_different_bins_per_line(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Multi Bin Co',
            'email' => 'multibin@example.test',
            'shiphero_customer_account_id' => 'sh-multi-bin',
        ]);
        $return = ClientAccountReturn::query()->create([
            'client_account_id' => $account->id,
            'rma_number' => 'AD0002',
            'status' => ClientAccountReturn::STATUS_DRAFT,
            'created_source' => ClientAccountReturn::SOURCE_ADMIN,
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'shiphero_order_id' => 'order-admin-2',
            'order_number' => '90002',
            'customer_name' => 'Admin Customer',
            'items_count' => 0,
            'return_fee_first_item' => 3.0,
            'return_fee_additional_item' => 1.0,
        ]);
        $binA = ReturnBin::query()->create(['name' => 'Bin A']);
        $binB = ReturnBin::query()->create(['name' => 'Bin B']);
        Sanctum::actingAs($this->staffUser());
        $this->mockInventoryStaging();

        $this->postJson('/api/admin/returns/'.$return->id.'/process-from-draft', [
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'lines' => [
                [
                    'sku' => 'SKU-A',
                    'name' => 'Product A',
                    'order_qty' => 1,
                    'return_qty' => 1,
                    'return_reason' => 'unknown',
                    'restock' => true,
                    'return_bin_id' => $binA->id,
                ],
                [
                    'sku' => 'SKU-B',
                    'name' => 'Product B',
                    'order_qty' => 1,
                    'return_qty' => 1,
                    'return_reason' => 'unknown',
                    'restock' => true,
                    'return_bin_id' => $binB->id,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', ClientAccountReturn::STATUS_RECEIVED);

        $lines = $return->fresh()->lines()->orderBy('sort_order')->get();
        $this->assertCount(2, $lines);
        $this->assertSame($binA->id, (int) $lines[0]->return_bin_id);
        $this->assertSame($binB->id, (int) $lines[1]->return_bin_id);
    }

    public function test_processed_return_appears_in_returned_orders_and_items(): void
    {
        $account = $this->account();
        $staff = $this->staffUser();
        $staff->update(['name' => 'Orders Staff']);
        $return = $this->returnForAccount($account, [
            'status' => ClientAccountReturn::STATUS_RECEIVED,
            'processed_at' => now(),
            'processed_by_user_id' => $staff->id,
            'rma_number' => 'RC5555',
        ]);
        $this->lineForReturn($return);
        Sanctum::actingAs($staff);

        $this->getJson('/api/admin/returns/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $return->id)
            ->assertJsonPath('data.0.display_status', 'returned')
            ->assertJsonPath('data.0.client_account_company_name', $account->company_name)
            ->assertJsonPath('data.0.processed_by_name', $staff->name);

        $this->getJson('/api/admin/returns/items')
            ->assertOk()
            ->assertJsonPath('data.0.return_id', $return->id)
            ->assertJsonPath('data.0.display_status', 'returned')
            ->assertJsonPath('data.0.processed_by_name', $staff->name);
    }

    public function test_portal_user_cannot_access_admin_pending(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->permission('returns.view', 'returns')->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/returns/pending')->assertForbidden();
    }
}
