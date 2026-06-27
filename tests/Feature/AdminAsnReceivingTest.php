<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\ClientAccountAsnLine;
use App\Models\ClientAccountAsnTracking;
use App\Models\AsnBill;
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

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shiphero.put_away_warehouse_id' => 'wh-1']);
    }

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

    /**
     * @param  array<string, mixed>  $productDetail
     */
    private function mockInventoryForReceiving(string $customerId, array $productDetail): ShipHeroInventoryService
    {
        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('resolveCustomerAccountIdForSkuMutation')
            ->andReturn($customerId);
        $mock->shouldReceive('getProductDetailBySku')->andReturn($productDetail);

        return $mock;
    }

    /**
     * @return array<string, mixed>
     */
    private function receivingWarehouseSlice(int $receivingQty, string $locationId = 'whloc-recv-99'): array
    {
        return [
            'warehouse_id' => 'wh-1',
            'warehouse_name' => 'Warehouse',
            'locations' => [
                [
                    'location_name' => 'Receiving',
                    'location_id' => $locationId,
                    'quantity' => $receivingQty,
                ],
            ],
        ];
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
            'asn_bill_id' => null,
        ]);
        $this->assertSame(0, CustomBill::query()->count());
        $this->assertSame(0, AsnBill::query()->count());
    }

    public function test_non_compliant_asn_with_fee_creates_asn_bill(): void
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
        $billId = (int) $response->json('asn_bill_id');
        $this->assertGreaterThan(0, $billId);
        $this->assertDatabaseHas('client_account_asns', [
            'id' => $asnId,
            'asn_bill_id' => $billId,
        ]);
        $this->assertDatabaseHas('asn_bills', ['id' => $billId]);
        $this->assertDatabaseHas('asn_bill_items', [
            'asn_bill_id' => $billId,
            'line_type' => AsnBill::LINE_NON_COMPLIANT,
        ]);
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

        $mock = $this->mockInventoryForReceiving('sh-asn-admin-1', [
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [
                        [
                            'location_name' => 'Receiving',
                            'location_id' => 'whloc-recv-99',
                            'quantity' => 5,
                        ],
                    ],
                ],
            ],
        ]);
        $mock->shouldReceive('replaceLocationQuantity')->once()->with(
            'DEMO-SKU',
            'wh-1',
            'whloc-recv-99',
            15,
            Mockery::type('string'),
            'sh-asn-admin-1'
        )->andReturn($this->receivingWarehouseSlice(15));
        $mock->shouldNotReceive('addLocationQuantity');
        $mock->shouldNotReceive('ensureWarehouseLocation');
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

        $mock = $this->mockInventoryForReceiving('sh-asn-admin-scan', [
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [
                        [
                            'location_name' => 'Receiving',
                            'location_id' => 'whloc-recv-scan',
                            'quantity' => 0,
                        ],
                    ],
                ],
            ],
        ]);
        $mock->shouldReceive('replaceLocationQuantity')->times(5)->with(
            'SCAN-SKU',
            'wh-1',
            'whloc-recv-scan',
            Mockery::on(static function ($qty) {
                return is_int($qty) && $qty >= 1 && $qty <= 5;
            }),
            Mockery::type('string'),
            'sh-asn-admin-scan'
        )->andReturnUsing(function ($sku, $wh, $loc, $qty) {
            return $this->receivingWarehouseSlice((int) $qty, 'whloc-recv-scan');
        });
        $mock->shouldNotReceive('addLocationQuantity');
        $mock->shouldNotReceive('ensureWarehouseLocation');
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

        $mock = $this->mockInventoryForReceiving('sh-asn-admin-new-loc', [
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [],
                ],
            ],
        ]);
        $mock->shouldReceive('ensureWarehouseLocation')->once()->andReturn(['id' => 'loc-catalog-recv', 'name' => 'Receiving']);
        $mock->shouldReceive('addLocationQuantity')->once()->with(
            'NEW-SKU',
            'wh-1',
            'loc-catalog-recv',
            3,
            Mockery::type('string'),
            'sh-asn-admin-new-loc'
        )->andReturn($this->receivingWarehouseSlice(3, 'loc-catalog-recv'));
        $mock->shouldNotReceive('replaceLocationQuantity');
        $this->app->instance(ShipHeroInventoryService::class, $mock);
        $this->app->forgetInstance(AsnReceivingService::class);

        $this->postJson("/api/admin/asns/{$asn->id}/lines/{$line->id}/receive", ['delta' => 3])
            ->assertOk()
            ->assertJsonPath('line.accepted_qty', 3);

        $line->refresh();
        $this->assertSame(3, $line->accepted_qty);
    }

    public function test_receive_override_uses_product_slice_location_id(): void
    {
        $account = $this->account('override');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0040',
            'status' => ClientAccountAsn::STATUS_IN_PROGRESS,
            'total_boxes' => 1,
            'expected_qty' => 8,
            'accepted_qty' => 2,
            'rejected_qty' => 0,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'OVR-SKU',
            'name' => 'Override SKU',
            'expected_qty' => 8,
            'accepted_qty' => 2,
            'rejected_qty' => 0,
            'line_status' => ClientAccountAsnLine::LINE_STATUS_PARTIAL,
            'sort_order' => 0,
        ]);

        $mock = $this->mockInventoryForReceiving('sh-asn-admin-override', [
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [
                        [
                            'location_name' => 'Receiving',
                            'location_id' => 'whloc-recv-override',
                            'quantity' => 2,
                        ],
                    ],
                ],
            ],
        ]);
        $mock->shouldReceive('replaceLocationQuantity')->once()->with(
            'OVR-SKU',
            'wh-1',
            'whloc-recv-override',
            6,
            Mockery::type('string'),
            'sh-asn-admin-override'
        )->andReturn($this->receivingWarehouseSlice(6, 'whloc-recv-override'));
        $mock->shouldNotReceive('addLocationQuantity');
        $mock->shouldNotReceive('ensureWarehouseLocation');
        $this->app->instance(ShipHeroInventoryService::class, $mock);
        $this->app->forgetInstance(AsnReceivingService::class);

        $this->postJson("/api/admin/asns/{$asn->id}/lines/{$line->id}/receive-override", [
            'accepted_qty' => 6,
        ])
            ->assertOk()
            ->assertJsonPath('line.accepted_qty', 6);

        $line->refresh();
        $this->assertSame(6, $line->accepted_qty);
    }

    public function test_staff_cannot_patch_accepted_qty_on_line(): void
    {
        $account = $this->account('patch');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0050',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 4,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'PATCH-SKU',
            'name' => 'Patch SKU',
            'expected_qty' => 4,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'sort_order' => 0,
        ]);

        $this->patchJson("/api/asns/{$asn->id}/lines/{$line->id}", [
            'accepted_qty' => 3,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accepted_qty']);

        $line->refresh();
        $this->assertSame(0, $line->accepted_qty);
    }

    public function test_staff_cannot_create_line_with_accepted_qty(): void
    {
        $account = $this->account('create-line');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0051',
            'status' => ClientAccountAsn::STATUS_NON_COMPLIANT,
            'total_boxes' => 1,
            'expected_qty' => 0,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);

        $this->postJson("/api/asns/{$asn->id}/lines", [
            'sku' => 'CREATE-ACCEPTED',
            'name' => 'Create Accepted',
            'expected_qty' => 2,
            'accepted_qty' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accepted_qty']);
    }

    public function test_receive_increment_upserts_put_away_receiving_row(): void
    {
        $account = $this->account('put-away');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0060',
            'status' => ClientAccountAsn::STATUS_PENDING,
            'total_boxes' => 1,
            'expected_qty' => 4,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'PUT-SKU',
            'name' => 'Put Away SKU',
            'barcode' => '12345',
            'expected_qty' => 4,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'line_status' => ClientAccountAsnLine::LINE_STATUS_PENDING,
            'sort_order' => 0,
        ]);

        $mock = $this->mockInventoryForReceiving('sh-asn-admin-put-away', [
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [
                        [
                            'location_name' => 'Receiving',
                            'location_id' => 'whloc-recv-put',
                            'quantity' => 0,
                        ],
                    ],
                ],
            ],
        ]);
        $mock->shouldReceive('replaceLocationQuantity')->once()->andReturn(
            $this->receivingWarehouseSlice(4, 'whloc-recv-put')
        );
        $this->app->instance(ShipHeroInventoryService::class, $mock);
        $this->app->forgetInstance(AsnReceivingService::class);

        $this->postJson("/api/admin/asns/{$asn->id}/lines/{$line->id}/receive", ['delta' => 4])
            ->assertOk();

        $this->getJson('/api/admin/put-away?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.sku', 'PUT-SKU')
            ->assertJsonPath('rows.0.receiving_qty', 4)
            ->assertJsonPath('meta.source', 'local');
    }

    public function test_update_line_specs_syncs_to_shiphero(): void
    {
        $account = $this->account('specs');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0070',
            'status' => ClientAccountAsn::STATUS_IN_PROGRESS,
            'total_boxes' => 1,
            'expected_qty' => 1,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'SPEC-SKU',
            'name' => 'Specs SKU',
            'expected_qty' => 1,
            'accepted_qty' => 0,
            'rejected_qty' => 0,
            'sort_order' => 0,
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('resolveCustomerAccountIdForSkuMutation')
            ->andReturn('sh-asn-admin-specs');
        $mock->shouldReceive('updateProductSpecs')->once()->with(
            'sh-asn-admin-specs',
            'SPEC-SKU',
            Mockery::on(static function (array $specs) {
                return ($specs['barcode'] ?? null) === '998877'
                    && (float) ($specs['weight'] ?? 0) === 0.3
                    && (float) ($specs['length'] ?? 0) === 5.0
                    && (float) ($specs['width'] ?? 0) === 3.0
                    && (float) ($specs['height'] ?? 0) === 5.0;
            })
        );
        $this->app->instance(ShipHeroInventoryService::class, $mock);
        $this->app->forgetInstance(AsnReceivingService::class);

        $this->patchJson("/api/admin/asns/{$asn->id}/lines/{$line->id}/specs", [
            'barcode' => '998877',
            'weight' => 0.3,
            'length' => 5,
            'width' => 3,
            'height' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('barcode', '998877')
            ->assertJsonPath('weight', 0.3);

        $line->refresh();
        $this->assertSame('998877', $line->barcode);
        $this->assertSame(0.3, (float) $line->weight);
    }

    public function test_reject_override_updates_line_and_asn_total(): void
    {
        $account = $this->account('reject');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0055',
            'status' => ClientAccountAsn::STATUS_IN_PROGRESS,
            'total_boxes' => 1,
            'expected_qty' => 10,
            'accepted_qty' => 4,
            'rejected_qty' => 1,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'REJ-SKU',
            'name' => 'Reject SKU',
            'expected_qty' => 10,
            'accepted_qty' => 4,
            'rejected_qty' => 1,
            'line_status' => ClientAccountAsnLine::LINE_STATUS_PARTIAL,
            'sort_order' => 0,
        ]);

        $this->postJson("/api/admin/asns/{$asn->id}/lines/{$line->id}/reject-override", [
            'rejected_qty' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('line.rejected_qty', 3)
            ->assertJsonPath('asn.rejected_qty', 3);

        $line->refresh();
        $asn->refresh();
        $this->assertSame(3, $line->rejected_qty);
        $this->assertSame(3, $asn->rejected_qty);
    }

    public function test_receive_override_lowering_qty_updates_shiphero_receiving(): void
    {
        $account = $this->account('lower-recv');
        $staff = $this->staffUser();
        Sanctum::actingAs($staff);

        $asn = ClientAccountAsn::create([
            'client_account_id' => $account->id,
            'asn_number' => '0056',
            'status' => ClientAccountAsn::STATUS_IN_PROGRESS,
            'total_boxes' => 1,
            'expected_qty' => 10,
            'accepted_qty' => 10,
            'rejected_qty' => 0,
        ]);
        $line = ClientAccountAsnLine::create([
            'client_account_asn_id' => $asn->id,
            'sku' => 'LOWER-SKU',
            'name' => 'Lower SKU',
            'expected_qty' => 10,
            'accepted_qty' => 10,
            'rejected_qty' => 0,
            'line_status' => ClientAccountAsnLine::LINE_STATUS_COMPLETED,
            'sort_order' => 0,
        ]);

        $mock = $this->mockInventoryForReceiving('sh-asn-admin-lower-recv', [
            'warehouses' => [
                [
                    'warehouse_id' => 'wh-1',
                    'locations' => [
                        [
                            'location_name' => 'Receiving',
                            'location_id' => 'whloc-recv-lower',
                            'quantity' => 10,
                        ],
                    ],
                ],
            ],
        ]);
        $mock->shouldReceive('replaceLocationQuantity')->once()->with(
            'LOWER-SKU',
            'wh-1',
            'whloc-recv-lower',
            6,
            Mockery::type('string'),
            'sh-asn-admin-lower-recv'
        )->andReturn($this->receivingWarehouseSlice(6, 'whloc-recv-lower'));
        $mock->shouldNotReceive('addLocationQuantity');
        $this->app->instance(ShipHeroInventoryService::class, $mock);
        $this->app->forgetInstance(\App\Services\AsnReceivingService::class);

        $this->postJson("/api/admin/asns/{$asn->id}/lines/{$line->id}/receive-override", [
            'accepted_qty' => 6,
        ])
            ->assertOk()
            ->assertJsonPath('line.accepted_qty', 6)
            ->assertJsonPath('asn.accepted_qty', 6);

        $line->refresh();
        $this->assertSame(6, $line->accepted_qty);
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
