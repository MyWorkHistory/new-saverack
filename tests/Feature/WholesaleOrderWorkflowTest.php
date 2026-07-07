<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\ShipHeroInventoryProductDetailCache;
use App\Models\User;
use App\Models\WholesaleOrder;
use App\Models\WholesaleOrderLine;
use App\Services\ShipHeroInventoryService;
use App\Services\ShipHeroOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class WholesaleOrderWorkflowTest extends TestCase
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
        $keys = array_merge(['orders.view', 'orders.update', 'clients.view'], $extraPermissionKeys);
        foreach ($keys as $key) {
            $perm = $this->permission($key, explode('.', $key)[0]);
            $user->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return $user;
    }

    private function account(string $suffix = '1'): ClientAccount
    {
        return ClientAccount::create([
            'company_name' => 'Wholesale Co '.$suffix,
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'sh-wholesale-'.$suffix,
        ]);
    }

  /**
   * @return array<string, mixed>
   */
    private function completeShippingAddress(): array
    {
        return [
            'first_name' => 'Jane',
            'last_name' => 'Buyer',
            'company' => 'Acme',
            'address1' => '123 Main St',
            'address2' => '',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'country' => 'US',
            'email' => 'jane@example.com',
            'phone' => '555-0100',
        ];
    }

  /**
   * @return array<string, mixed>
   */
    private function completeRequirementsPayload(): array
    {
        return [
            'sku_barcode_labels' => 'apply_new',
            'sku_barcode_labels_comment' => 'Use new labels',
            'cover_existing_barcodes' => 'yes',
            'cover_existing_barcodes_comment' => 'Cover all',
            'individual_sku_packaging' => 'poly_bag',
            'individual_sku_packaging_comment' => 'Seal bags',
            'bundle_configuration' => 'not_bundled',
            'bundle_configuration_comment' => '',
            'shipping_method_requirement' => 'boxes',
            'shipping_method_requirement_comment' => 'Standard boxes',
            'master_cartons' => 'no',
            'master_cartons_comment' => '',
        ];
    }

    private function seedReadyOrder(ClientAccount $account): WholesaleOrder
    {
        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'READY-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_PENDING,
            'items_count' => 2,
            'shipping_address' => $this->completeShippingAddress(),
            'shipping_carrier' => 'ups',
            'shipping_method' => 'Ground',
            'shipping_labels_provider' => WholesaleOrder::SHIPPING_LABELS_SAVE_RACK_PROVIDES,
            ...$this->completeRequirementsPayload(),
        ]);

        WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-READY',
            'name' => 'Ready Product',
            'quantity' => 2,
            'status' => WholesaleOrderLine::STATUS_SHIP_AS_IS,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 1,
        ]);

        return $order->fresh(['lines']);
    }

    public function test_create_wholesale_order_as_draft(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $this->postJson('/api/admin/wholesale-orders', [
            'client_account_id' => $account->id,
            'order_type' => 'invalid',
            'order_number' => 'WO-100',
        ])->assertUnprocessable();

        $response = $this->postJson('/api/admin/wholesale-orders', [
            'client_account_id' => $account->id,
            'order_type' => WholesaleOrder::TYPE_AMAZON,
            'order_number' => 'WO-100',
            'instructions' => 'Pack carefully.',
        ])
            ->assertCreated()
            ->assertJsonPath('status', WholesaleOrder::STATUS_DRAFT)
            ->assertJsonPath('order_number', 'WO-100')
            ->assertJsonPath('order_type', WholesaleOrder::TYPE_AMAZON)
            ->assertJsonCount(0, 'lines');

        $this->assertDatabaseHas('wholesale_orders', [
            'id' => $response->json('id'),
            'status' => WholesaleOrder::STATUS_DRAFT,
            'instructions' => 'Pack carefully.',
        ]);
    }

    public function test_list_filters_by_status_type_and_order_number(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'AMZ-001',
            'order_type' => WholesaleOrder::TYPE_AMAZON,
            'status' => WholesaleOrder::STATUS_PENDING,
            'items_count' => 0,
        ]);
        WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'TT-002',
            'order_type' => WholesaleOrder::TYPE_TIKTOK,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 0,
        ]);

        $this->getJson('/api/admin/wholesale-orders?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'AMZ-001');

        $this->getJson('/api/admin/wholesale-orders?order_type=tiktok')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'TT-002');

        $this->getJson('/api/admin/wholesale-orders?q=AMZ')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', 'AMZ-001');
    }

    public function test_line_crud_and_barcode_upload(): void
    {
        Storage::fake('local');
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $create = $this->postJson('/api/admin/wholesale-orders', [
            'client_account_id' => $account->id,
            'order_type' => WholesaleOrder::TYPE_B2B,
            'order_number' => 'B2B-55',
        ])->assertCreated();

        $orderId = (int) $create->json('id');

        $lineResponse = $this->postJson('/api/admin/wholesale-orders/'.$orderId.'/lines', [
            'sku' => 'SKU-A',
            'name' => 'Product A',
            'quantity' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('lines.0.sku', 'SKU-A')
            ->assertJsonPath('lines.0.status', WholesaleOrderLine::STATUS_PENDING)
            ->assertJsonPath('items_count', 3);

        $lineId = (int) $lineResponse->json('lines.0.id');

        $this->patchJson('/api/admin/wholesale-orders/'.$orderId.'/lines/'.$lineId, [
            'quantity' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('items_count', 5);

        $file = UploadedFile::fake()->create('barcode.pdf', 100, 'application/pdf');
        $this->post('/api/admin/wholesale-orders/'.$orderId.'/lines/'.$lineId.'/barcode', [
            'barcode' => $file,
        ])
            ->assertOk()
            ->assertJsonPath('lines.0.has_barcode', true)
            ->assertJsonPath('lines.0.status', WholesaleOrderLine::STATUS_BARCODE_READY);

        $line = WholesaleOrderLine::query()->findOrFail($lineId);
        $this->assertSame(WholesaleOrderLine::BARCODE_UPLOADED, $line->barcode_mode);
        $this->assertSame(WholesaleOrderLine::STATUS_BARCODE_READY, $line->status);
        Storage::disk('local')->assertExists($line->barcode_path);

        $this->get('/api/admin/wholesale-orders/'.$orderId.'/lines/'.$lineId.'/barcode.pdf')
            ->assertOk();

        $this->deleteJson('/api/admin/wholesale-orders/'.$orderId.'/lines/'.$lineId)
            ->assertOk()
            ->assertJsonCount(0, 'lines');
    }

    public function test_manual_status_update_pending_and_completed(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'STAT-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 0,
        ]);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, [
            'status' => WholesaleOrder::STATUS_PENDING,
        ])
            ->assertOk()
            ->assertJsonPath('status', WholesaleOrder::STATUS_PENDING);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, [
            'status' => WholesaleOrder::STATUS_COMPLETED,
        ])
            ->assertOk()
            ->assertJsonPath('status', WholesaleOrder::STATUS_COMPLETED);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, [
            'status' => WholesaleOrder::STATUS_SHIPPED,
        ])->assertUnprocessable();
    }

    public function test_show_enriches_missing_line_image_from_inventory_index(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'IMG-1',
            'order_type' => WholesaleOrder::TYPE_AMAZON,
            'status' => WholesaleOrder::STATUS_PENDING,
            'items_count' => 1,
        ]);

        WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'IMG-SKU',
            'name' => 'Indexed Product',
            'image_url' => null,
            'quantity' => 1,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 1,
        ]);

        \App\Models\ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $account->id,
            'sku' => 'IMG-SKU',
            'sku_search' => 'img-sku',
            'name' => 'Indexed Product',
            'image_url' => 'https://cdn.example.com/img-sku.jpg',
            'shiphero_product_id' => 'prod-img-1',
            'shiphero_customer_account_id' => $account->shiphero_customer_account_id,
            'synced_at' => now(),
        ]);

        $this->getJson('/api/admin/wholesale-orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('lines.0.image_url', 'https://cdn.example.com/img-sku.jpg');
    }

    public function test_post_comment_with_attachment(): void
    {
        Storage::fake('local');
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $create = $this->postJson('/api/admin/wholesale-orders', [
            'client_account_id' => $account->id,
            'order_type' => WholesaleOrder::TYPE_OTHER,
            'order_number' => 'OTH-1',
        ])->assertCreated();

        $orderId = (int) $create->json('id');
        $file = UploadedFile::fake()->create('note.pdf', 50, 'application/pdf');

        $this->post('/api/admin/wholesale-orders/'.$orderId.'/comments', [
            'body' => 'Needs labels.',
            'attachment' => $file,
        ])
            ->assertCreated()
            ->assertJsonPath('body', 'Needs labels.')
            ->assertJsonPath('attachment.original_name', 'note.pdf');
    }

    public function test_portal_user_cannot_access_wholesale_orders(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['client_account_id' => $account->id]);
        $user->permissions()->attach($this->permission('orders.view', 'orders')->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/wholesale-orders')->assertForbidden();
    }

    public function test_wholesale_product_catalog_uses_orders_view_not_inventory_view(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'WO-CAT-1',
            'order_type' => WholesaleOrder::TYPE_AMAZON,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 0,
        ]);

        $mock = Mockery::mock(ShipHeroInventoryService::class);
        $mock->shouldReceive('listAsnProductCatalogPage')
            ->once()
            ->with('sh-wholesale-1', Mockery::type('int'), null, null, 0, $account->id, true)
            ->andReturn([
                'products' => [
                    [
                        'id' => 'prod-wh-1',
                        'sku' => 'WH-SKU-1',
                        'name' => 'Wholesale Item',
                        'barcode' => '',
                        'image_url' => null,
                    ],
                ],
                'page_info' => ['has_next_page' => false, 'end_cursor' => null],
            ]);
        $this->app->instance(ShipHeroInventoryService::class, $mock);

        $this->getJson('/api/admin/wholesale-orders/'.$order->id.'/product-catalog?first=50&refresh=1')
            ->assertOk()
            ->assertJsonPath('client_account_id', $account->id)
            ->assertJsonPath('products.0.sku', 'WH-SKU-1');
    }

    public function test_requirements_patch_persists_all_fields(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'REQ-1',
            'order_type' => WholesaleOrder::TYPE_AMAZON,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 0,
        ]);

        $payload = $this->completeRequirementsPayload();

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, $payload)
            ->assertOk()
            ->assertJsonPath('sku_barcode_labels', 'apply_new')
            ->assertJsonPath('cover_existing_barcodes', 'yes')
            ->assertJsonPath('individual_sku_packaging', 'poly_bag')
            ->assertJsonPath('bundle_configuration', 'not_bundled')
            ->assertJsonPath('shipping_method_requirement', 'boxes')
            ->assertJsonPath('master_cartons', 'no')
            ->assertJsonPath('has_requirements_filled', true);

        $this->assertDatabaseHas('wholesale_orders', [
            'id' => $order->id,
            'sku_barcode_labels' => 'apply_new',
            'cover_existing_barcodes' => 'yes',
            'master_cartons' => 'no',
            'shipping_method_requirement' => 'boxes',
        ]);
    }

    public function test_requirements_not_filled_until_all_six_dropdowns_set(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'REQ-PARTIAL',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 0,
            'sku_barcode_labels' => 'apply_new',
            'cover_existing_barcodes' => 'yes',
            'individual_sku_packaging' => 'poly_bag',
            'bundle_configuration' => 'not_bundled',
            'shipping_method_requirement' => 'boxes',
        ]);

        $this->getJson('/api/admin/wholesale-orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('has_requirements_filled', false);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, [
            'master_cartons' => 'no',
        ])
            ->assertOk()
            ->assertJsonPath('has_requirements_filled', true);
    }

    public function test_shipping_address_patch_persists(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'SHIP-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 0,
        ]);

        $address = $this->completeShippingAddress();

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, [
            'shipping_address' => $address,
            'shipping_carrier' => 'ups',
            'shipping_method' => 'Ground',
        ])
            ->assertOk()
            ->assertJsonPath('shipping_carrier', 'ups')
            ->assertJsonPath('shipping_method', 'Ground')
            ->assertJsonPath('has_complete_shipping_address', true);

        $fresh = WholesaleOrder::query()->findOrFail($order->id);
        $this->assertTrue($fresh->hasCompleteShippingAddress());
        $this->assertTrue($fresh->hasShippingCarrierAndMethod());
    }

    public function test_shipping_labels_provider_patch_serializes(): void
    {
        Storage::fake('local');
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'SL-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 0,
        ]);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, [
            'shipping_labels_provider' => WholesaleOrder::SHIPPING_LABELS_CLIENT_PROVIDES,
            'shipping_labels_comment' => 'Use attached labels',
        ])
            ->assertOk()
            ->assertJsonPath('shipping_labels_provider', WholesaleOrder::SHIPPING_LABELS_CLIENT_PROVIDES)
            ->assertJsonPath('shipping_labels_provider_label', 'Client provides shipping labels')
            ->assertJsonPath('shipping_labels_comment', 'Use attached labels')
            ->assertJsonPath('has_shipping_labels_resolved', false)
            ->assertJsonPath('has_shipping_label_file', false);

        $this->assertDatabaseHas('wholesale_orders', [
            'id' => $order->id,
            'shipping_labels_provider' => WholesaleOrder::SHIPPING_LABELS_CLIENT_PROVIDES,
            'shipping_labels_comment' => 'Use attached labels',
        ]);
    }

    public function test_shipping_label_upload_resolves_client_provides_without_address(): void
    {
        Storage::fake('local');
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'SL-UP',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_PENDING,
            'items_count' => 1,
            'shipping_labels_provider' => WholesaleOrder::SHIPPING_LABELS_CLIENT_PROVIDES,
            ...$this->completeRequirementsPayload(),
        ]);

        WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-SL',
            'name' => 'Label Item',
            'quantity' => 1,
            'status' => WholesaleOrderLine::STATUS_SHIP_AS_IS,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 1,
        ]);

        $file = UploadedFile::fake()->create('shipping-label.pdf', 100, 'application/pdf');
        $this->post('/api/admin/wholesale-orders/'.$order->id.'/shipping-label', [
            'shipping_label' => $file,
        ])
            ->assertOk()
            ->assertJsonPath('has_shipping_label_file', true)
            ->assertJsonPath('has_shipping_labels_resolved', true)
            ->assertJsonPath('shipping_label_original_name', 'shipping-label.pdf');

        $fresh = WholesaleOrder::query()->findOrFail($order->id);
        $this->assertTrue($fresh->hasShippingLabelsResolved());
        Storage::disk('local')->assertExists($fresh->shipping_label_path);
    }

    public function test_save_rack_provides_requires_address_carrier_and_method_for_ready_to_ship(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'SL-SR',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_PENDING,
            'items_count' => 1,
            'shipping_labels_provider' => WholesaleOrder::SHIPPING_LABELS_SAVE_RACK_PROVIDES,
            ...$this->completeRequirementsPayload(),
        ]);

        WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-SR',
            'name' => 'Save Rack Item',
            'quantity' => 1,
            'status' => WholesaleOrderLine::STATUS_SHIP_AS_IS,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/admin/wholesale-orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('has_shipping_labels_resolved', false)
            ->assertJsonPath('can_ready_to_ship', false);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, [
            'shipping_address' => $this->completeShippingAddress(),
            'shipping_carrier' => 'ups',
            'shipping_method' => 'Ground',
        ])
            ->assertOk()
            ->assertJsonPath('has_shipping_labels_resolved', true)
            ->assertJsonPath('can_ready_to_ship', true);
    }

    public function test_shipping_label_download_returns_file(): void
    {
        Storage::fake('local');
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'SL-DL',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 0,
            'shipping_labels_provider' => WholesaleOrder::SHIPPING_LABELS_CLIENT_PROVIDES,
        ]);

        $path = 'wholesale-orders/'.$order->id.'/shipping-label.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $order->shipping_label_path = $path;
        $order->shipping_label_original_name = 'client-label.pdf';
        $order->shipping_label_mime = 'application/pdf';
        $order->save();

        $this->get('/api/admin/wholesale-orders/'.$order->id.'/shipping-label.pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_ready_to_ship_blocked_without_requirements(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'BLOCK-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_PENDING,
            'items_count' => 1,
            'shipping_address' => $this->completeShippingAddress(),
            'shipping_carrier' => 'ups',
            'shipping_method' => 'Ground',
        ]);

        WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-BLOCK',
            'name' => 'Blocked',
            'quantity' => 1,
            'status' => WholesaleOrderLine::STATUS_PENDING,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/ready-to-ship')
            ->assertUnprocessable();
    }

    public function test_ready_to_ship_blocked_when_uploaded_barcode_missing_file(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'BLOCK-BC',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_PENDING,
            'items_count' => 1,
            'shipping_address' => $this->completeShippingAddress(),
            'shipping_carrier' => 'ups',
            'shipping_method' => 'Ground',
            ...$this->completeRequirementsPayload(),
        ]);

        WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-NOFILE',
            'name' => 'Missing Upload',
            'quantity' => 1,
            'status' => WholesaleOrderLine::STATUS_BARCODE_READY,
            'barcode_mode' => WholesaleOrderLine::BARCODE_UPLOADED,
            'sort_order' => 1,
        ]);

        $this->getJson('/api/admin/wholesale-orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('can_ready_to_ship', false)
            ->assertJsonPath('has_all_lines_barcode_resolved', false);

        $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/ready-to-ship')
            ->assertUnprocessable();
    }

    public function test_can_ready_to_ship_when_requirements_and_ship_as_is_line(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());
        $order = $this->seedReadyOrder($account);

        $this->getJson('/api/admin/wholesale-orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('can_ready_to_ship', true)
            ->assertJsonPath('has_all_lines_barcode_resolved', true)
            ->assertJsonPath('has_requirements_filled', true);
    }

    public function test_ready_to_ship_success_mocks_shiphero(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());
        $order = $this->seedReadyOrder($account);

        $mock = Mockery::mock(ShipHeroOrderService::class);
        $mock->shouldReceive('createOrder')
            ->once()
            ->andReturn(['shiphero_order_id' => '99001', 'order_number' => 'READY-1']);
        $mock->shouldReceive('updateOrderShippingLines')->once();
        $mock->shouldReceive('updateOrderPackingNote')->once();
        $mock->shouldReceive('updateOrderFulfillmentStatus')->once();
        $mock->shouldReceive('addOrderHistoryEntry')->once();
        $this->app->instance(ShipHeroOrderService::class, $mock);

        $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/ready-to-ship')
            ->assertOk()
            ->assertJsonPath('status', WholesaleOrder::STATUS_IN_PROGRESS)
            ->assertJsonPath('status_label', 'Ready to Ship')
            ->assertJsonPath('shiphero_order_id', '99001')
            ->assertJsonPath('can_ready_to_ship', false)
            ->assertJsonPath('is_editable', false)
            ->assertJsonPath('is_lines_editable', true);

        $this->assertDatabaseHas('wholesale_orders', [
            'id' => $order->id,
            'status' => WholesaleOrder::STATUS_IN_PROGRESS,
            'shiphero_order_id' => '99001',
        ]);
    }

    public function test_line_ship_as_is_updates_status(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'SAI-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_DRAFT,
            'items_count' => 1,
        ]);

        $line = WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-SAI',
            'name' => 'Ship As Is Product',
            'quantity' => 1,
            'status' => WholesaleOrderLine::STATUS_PENDING,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 1,
        ]);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id.'/lines/'.$line->id, [
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
        ])
            ->assertOk()
            ->assertJsonPath('lines.0.status', WholesaleOrderLine::STATUS_SHIP_AS_IS);
    }

    public function test_lines_editable_after_in_progress_but_order_fields_locked(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->staffUser());

        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'LOCK-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_IN_PROGRESS,
            'items_count' => 1,
            'shiphero_order_id' => '88001',
        ]);

        $line = WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-LOCK',
            'name' => 'Locked',
            'quantity' => 1,
            'status' => WholesaleOrderLine::STATUS_PENDING,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 1,
        ]);

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id, [
            'instructions' => 'Should fail',
        ])->assertStatus(422);

        $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/lines', [
            'sku' => 'NEW-SKU',
            'name' => 'New',
            'quantity' => 1,
        ])->assertOk()
            ->assertJsonPath('lines.1.sku', 'NEW-SKU');

        $this->patchJson('/api/admin/wholesale-orders/'.$order->id.'/lines/'.$line->id, [
            'quantity' => 5,
        ])->assertOk()
            ->assertJsonPath('lines.0.quantity', 5);
    }

    private function seedInProgressOrder(ClientAccount $account): WholesaleOrder
    {
        $order = WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'PICK-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_IN_PROGRESS,
            'items_count' => 5,
            'shiphero_order_id' => 'sh-pick-1',
        ]);

        WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-PICK-A',
            'name' => 'Pick Product A',
            'quantity' => 3,
            'quantity_picked' => 0,
            'status' => WholesaleOrderLine::STATUS_PENDING,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 1,
        ]);
        WholesaleOrderLine::query()->create([
            'wholesale_order_id' => $order->id,
            'sku' => 'SKU-PICK-B',
            'name' => 'Pick Product B',
            'quantity' => 2,
            'quantity_picked' => 0,
            'status' => WholesaleOrderLine::STATUS_PENDING,
            'barcode_mode' => WholesaleOrderLine::BARCODE_SHIP_AS_IS,
            'sort_order' => 2,
        ]);

        return $order->fresh(['lines']);
    }

    public function test_pick_list_returns_in_progress_orders(): void
    {
        $account = $this->account('pick');
        $otherAccount = $this->account('other');
        Sanctum::actingAs($this->staffUser());

        $order = $this->seedInProgressOrder($account);

        WholesaleOrder::query()->create([
            'client_account_id' => $otherAccount->id,
            'order_number' => 'OTHER-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_IN_PROGRESS,
            'items_count' => 1,
        ]);

        WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'PENDING-1',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_PENDING,
            'items_count' => 1,
        ]);

        $this->getJson('/api/admin/wholesale-orders/pick-list')
            ->assertOk()
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.id', $order->id)
            ->assertJsonPath('orders.0.order_number', 'PICK-1')
            ->assertJsonPath('orders.0.is_fully_picked', false)
            ->assertJsonPath('orders.0.lines.0.sku', 'SKU-PICK-A');

        $this->getJson('/api/admin/wholesale-orders/pick-list?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $order->id);
    }

    public function test_pick_list_includes_locations_from_cached_product_detail(): void
    {
        $account = $this->account('pick-loc');
        Sanctum::actingAs($this->staffUser());
        $order = $this->seedInProgressOrder($account);

        ShipHeroInventoryProductDetailCache::query()->create([
            'client_account_id' => $account->id,
            'sku' => 'SKU-PICK-A',
            'sku_search' => 'sku-pick-a',
            'product_json' => [
                'sku' => 'SKU-PICK-A',
                'warehouses' => [
                    [
                        'warehouse_id' => 'wh-1',
                        'locations' => [
                            [
                                'location_id' => 'pick-1',
                                'location_name' => 'A-01',
                                'quantity' => 5,
                                'pickable' => true,
                            ],
                            [
                                'location_id' => 'back-1',
                                'location_name' => 'OS-1',
                                'quantity' => 25,
                                'pickable' => false,
                            ],
                        ],
                    ],
                ],
            ],
            'product_synced_at' => now(),
        ]);

        $this->getJson('/api/admin/wholesale-orders/pick-list?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('orders.0.lines.0.pick_location', 'A-01 (5)')
            ->assertJsonPath('orders.0.lines.0.backstock_location', 'OS-1 (25)')
            ->assertJsonPath('orders.0.lines.1.pick_location', null)
            ->assertJsonPath('orders.0.lines.1.backstock_location', null);
    }

    public function test_update_line_pick_rejects_over_quantity(): void
    {
        $account = $this->account('pick-reject');
        Sanctum::actingAs($this->staffUser());
        $order = $this->seedInProgressOrder($account);
        $line = $order->lines->first();

        $this->patchJson("/api/admin/wholesale-orders/{$order->id}/lines/{$line->id}/pick", [
            'quantity_picked' => 99,
        ])->assertStatus(422);
    }

    public function test_update_line_pick_updates_quantity(): void
    {
        $account = $this->account('pick-update');
        Sanctum::actingAs($this->staffUser());
        $order = $this->seedInProgressOrder($account);
        $line = $order->lines->first();

        $this->patchJson("/api/admin/wholesale-orders/{$order->id}/lines/{$line->id}/pick", [
            'quantity_picked' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('quantity_picked', 2)
            ->assertJsonPath('is_fully_picked', false);

        $this->assertDatabaseHas('wholesale_order_lines', [
            'id' => $line->id,
            'quantity_picked' => 2,
        ]);
    }

    public function test_mark_picked_requires_all_lines_and_completes_order(): void
    {
        $account = $this->account('mark-picked');
        Sanctum::actingAs($this->staffUser());
        $order = $this->seedInProgressOrder($account);
        $lines = $order->lines;

        $this->postJson("/api/admin/wholesale-orders/{$order->id}/mark-picked")
            ->assertStatus(422);

        foreach ($lines as $line) {
            $this->patchJson("/api/admin/wholesale-orders/{$order->id}/lines/{$line->id}/pick", [
                'quantity_picked' => $line->quantity,
            ])->assertOk();
        }

        $this->postJson("/api/admin/wholesale-orders/{$order->id}/mark-picked")
            ->assertOk()
            ->assertJsonPath('status', WholesaleOrder::STATUS_COMPLETED);

        $this->assertDatabaseHas('wholesale_orders', [
            'id' => $order->id,
            'status' => WholesaleOrder::STATUS_COMPLETED,
        ]);
    }
}
