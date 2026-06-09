<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountAsnLine;
use App\Models\ClientAccountAsnTracking;
use App\Models\CustomBill;
use App\Models\Permission;
use App\Models\User;
use App\Services\AsnReceivingService;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AdminAsnReceivingTest extends TestCase
{
    use RefreshDatabase;

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

    private function billingCreatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.create'],
            ['label' => 'Create billing', 'module' => 'billing']
        );
    }

    private function staffUser(array $extraPermissionKeys = []): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $keys = array_merge(['inventory.view'], $extraPermissionKeys);
        foreach ($keys as $key) {
            $perm = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'module' => explode('.', $key)[0]]
            );
            $user->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $user;
    }

    private function account(string $suffix = '1'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'ASN Admin Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-asn-admin-'.$suffix,
        ]);
    }

    public function test_global_asn_numbers_increment_across_accounts(): void
    {
        $accountA = $this->account('a');
        $accountB = $this->account('b');
        $user = User::factory()->create(['client_account_id' => $accountA->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/asns', ['client_account_id' => $accountA->id])
            ->assertCreated()
            ->json();
        $this->assertSame('0001', $first['asn_number']);

        Sanctum::actingAs(User::factory()->create([
            'client_account_id' => $accountB->id,
        ])->tap(function (User $u) {
            $u->permissions()->attach($this->inventoryViewPermission()->id);
        }));

        $second = $this->postJson('/api/asns', ['client_account_id' => $accountB->id])
            ->assertCreated()
            ->json();
        $this->assertSame('0002', $second['asn_number']);
    }

    public function test_admin_summary_and_list_search_by_tracking(): void
    {
        $account = $this->account();
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0010',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        ClientAccountAsnTracking::create([
            'client_account_asn_id' => $asn->id,
            'carrier' => 'UPS',
            'tracking_number' => '9852952592285992',
            'sort_order' => 0,
        ]);

        $this->getJson('/api/admin/asns/summary')
            ->assertOk()
            ->assertJsonPath('pending', 1);

        $this->getJson('/api/admin/asns?q=985')
            ->assertOk()
            ->assertJsonPath('data.0.id', $asn->id);

        $this->getJson('/api/admin/asns?q=999999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_compliant_asn_without_fee_skips_custom_bill(): void
    {
        $account = $this->account();
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/admin/asns/non-compliant', [
            'client_account_id' => $account->id,
            'total_boxes' => 2,
            'total_pallets' => 0,
            'trackings' => [
                ['carrier' => 'UPS', 'tracking_number' => '1Z999'],
            ],
            'fee' => 0,
        ])->assertCreated();

        $id = (int) $response->json('id');
        $this->assertDatabaseHas('client_account_asns', [
            'id' => $id,
            'status' => ClientAccountAsn::STATUS_NON_COMPLIANT,
            'custom_bill_id' => null,
        ]);
        $this->assertSame(0, CustomBill::query()->count());
    }

    public function test_non_compliant_asn_with_fee_creates_custom_bill(): void
    {
        $account = $this->account();
        $staff = $this->staffUser(['billing.create']);
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/admin/asns/non-compliant', [
            'client_account_id' => $account->id,
            'total_boxes' => 1,
            'trackings' => [
                ['carrier' => 'UPS', 'tracking_number' => '1Z111'],
            ],
            'fee' => 25.5,
        ])->assertCreated();

        $asnId = (int) $response->json('id');
        $billId = (int) $response->json('custom_bill_id');
        $this->assertGreaterThan(0, $billId);
        $this->assertDatabaseHas('client_account_asns', [
            'id' => $asnId,
            'custom_bill_id' => $billId,
        ]);
        $this->assertDatabaseHas('custom_bills', ['id' => $billId]);
    }

    public function test_staff_can_add_line_to_non_compliant_asn(): void
    {
        $account = $this->account();
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0025',
            'status' => ClientAccountAsn::STATUS_NON_COMPLIANT,
            'total_boxes' => 2,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);

        $this->postJson("/api/asns/{$asn->id}/lines", [
            'sku' => 'NC-SKU-1',
            'name' => 'Non-Compliant Item',
            'expected_qty' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('sku', 'NC-SKU-1')
            ->assertJsonPath('expected_qty', 3);

        $this->assertDatabaseHas('client_account_asn_lines', [
            'client_account_asn_id' => $asn->id,
            'sku' => 'NC-SKU-1',
            'expected_qty' => 3,
        ]);
    }

    public function test_receive_increment_updates_line_and_status(): void
    {
        $account = $this->account();
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0020',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 10,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'DEMO-SKU',
            'name' => 'Demo',
            'expected_qty' => 10,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'line_status' => ClientAccountAsnLine::LINE_STATUS_PENDING,
            'sort_order' => 0,
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')->andReturn([
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [
                        ['location_name' => 'Receiving', 'quantity' => 5],
                    ],
                ],
            ],
        ]);
        $mock->shouldReceive('ensureWarehouseLocation')->andReturn(['id' => 'loc-recv', 'name' => 'Receiving']);
        $mock->shouldReceive('replaceLocationQuantity')->once();
        $mock->shouldNotReceive('addLocationQuantity');
        $this->app->instance(ShipHeroInventoryService::class, $mock);
        $this->app->forgetInstance(AsnReceivingService::class);

        $this->postJson("/api/admin/asns/{$asn->id}/lines/{$line->id}/receive", ['delta' => 10])
            ->assertOk()
            ->assertJsonPath('line.accepted_qty', 10)
            ->assertJsonPath('asn.status', ClientAccountAsn::STATUS_IN_PROGRESS)
            ->assertJsonPath('asn.processed_by_name', $staff->name);

        $line->refresh();
        $this->assertSame(10, $line->accepted_qty);
        $this->assertSame(ClientAccountAsnLine::LINE_STATUS_COMPLETED, $line->line_status);
    }

    public function test_scan_barcodes_increments_accepted_qty_by_barcode(): void
    {
        $account = $this->account('scan');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0030',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 5,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'SCAN-SKU',
            'name' => 'Scannable',
            'barcode' => '9945422442',
            'expected_qty' => 5,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'line_status' => ClientAccountAsnLine::LINE_STATUS_PENDING,
            'sort_order' => 0,
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')->andReturn([
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [
                        ['location_name' => 'Receiving', 'quantity' => 0],
                    ],
                ],
            ],
        ]);
        $mock->shouldReceive('ensureWarehouseLocation')->andReturn(['id' => 'loc-recv', 'name' => 'Receiving']);
        $mock->shouldReceive('replaceLocationQuantity')->times(5);
        $mock->shouldNotReceive('addLocationQuantity');
        $this->app->instance(ShipHeroInventoryService::class, $mock);
        $this->app->forgetInstance(AsnReceivingService::class);

        $barcodes = implode("\n", array_fill(0, 5, '9945422442'));
        $this->postJson("/api/admin/asns/{$asn->id}/scan-barcodes", ['barcodes' => $barcodes])
            ->assertOk()
            ->assertJsonPath('matched', 5)
            ->assertJsonPath('unmatched', []);

        $line->refresh();
        $this->assertSame(5, $line->accepted_qty);
    }

    public function test_receive_increment_uses_inventory_add_when_sku_has_no_receiving_row(): void
    {
        $account = $this->account('new-loc');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0021',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 5,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'NEW-SKU',
            'name' => 'New SKU',
            'expected_qty' => 5,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'line_status' => ClientAccountAsnLine::LINE_STATUS_PENDING,
            'sort_order' => 0,
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('getProductDetailBySku')->andReturn([
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [],
                ],
            ],
        ]);
        $mock->shouldReceive('ensureWarehouseLocation')->once()->andReturn(['id' => 'loc-recv', 'name' => 'Receiving']);
        $mock->shouldReceive('addLocationQuantity')->once();
        $mock->shouldNotReceive('replaceLocationQuantity');
        $this->app->instance(ShipHeroInventoryService::class, $mock);
        $this->app->forgetInstance(AsnReceivingService::class);

        $this->postJson("/api/admin/asns/{$asn->id}/lines/{$line->id}/receive", ['delta' => 3])
            ->assertOk()
            ->assertJsonPath('line.accepted_qty', 3);

        $line->refresh();
        $this->assertSame(3, $line->accepted_qty);
    }

    public function test_portal_user_forbidden_on_admin_asn_routes(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/asns/summary')->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
