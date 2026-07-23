<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\ClientAccountReturn;
use App\Models\ClientAccountReturnLine;
use App\Models\Permission;
use App\Models\ReturnBill;
use App\Models\ReturnBin;
use App\Models\User;
use App\Services\ShipHeroOrderService;
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
        Sanctum::actingAs($this->staffUser());

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('listOrders')
            ->once()
            ->andReturn([
                'rows' => [
                    [
                        'id' => 'order-sh-999',
                        'order_number' => '84842',
                        'recipient_name' => 'Emily Stewart',
                    ],
                ],
            ]);
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->getJson('/api/admin/returns/order-lookup?client_account_id='.$account->id.'&order_number=84842')
            ->assertOk()
            ->assertJsonPath('display_status', 'not_returned')
            ->assertJsonPath('order.order_number', '84842')
            ->assertJsonPath('return', null);
    }

    public function test_order_lookup_pending_when_return_exists(): void
    {
        $account = $this->account();
        $return = $this->returnForAccount($account);
        Sanctum::actingAs($this->staffUser());

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('listOrders')
            ->once()
            ->andReturn([
                'rows' => [
                    [
                        'id' => 'order-sh-100',
                        'order_number' => '84842',
                        'recipient_name' => 'Emily Stewart',
                    ],
                ],
            ]);
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->getJson('/api/admin/returns/order-lookup?client_account_id='.$account->id.'&order_number=84842')
            ->assertOk()
            ->assertJsonPath('display_status', 'pending')
            ->assertJsonPath('return.id', $return->id);
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

    public function test_process_return_requires_return_bin_id(): void
    {
        $account = $this->account();
        $this->seedReturnFees($account);
        $return = $this->returnForAccount($account);
        $lineA = $this->lineForReturn($return, ['sku' => 'A', 'return_qty' => 2]);
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/'.$return->id.'/process', [
            'line_ids' => [$lineA->id],
            'restock_by_line_id' => [$lineA->id => true],
        ])->assertUnprocessable();
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
        $bin = ReturnBin::query()->create(['name' => 'Draft Bin 3']);
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/returns/'.$return->id.'/process-from-draft', [
            'return_type' => ClientAccountReturn::TYPE_DIRECT,
            'return_bin_id' => $bin->id,
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
            ->assertJsonPath('return_bin_id', $bin->id)
            ->assertJsonPath('return_bin_name', 'Draft Bin 3');

        $return->refresh();
        $this->assertSame(ClientAccountReturn::STATUS_RECEIVED, $return->status);
        $this->assertSame($bin->id, $return->return_bin_id);
        $this->assertNotNull($return->return_bill_id);
        $this->assertSame('unknown', $return->lines()->first()->return_reason);
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
