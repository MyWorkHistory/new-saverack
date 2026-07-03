<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use App\Models\WholesaleOrder;
use App\Models\WholesaleOrderLine;
use App\Services\ShipHeroInventoryService;
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
            ->assertJsonPath('lines.0.has_barcode', true);

        $line = WholesaleOrderLine::query()->findOrFail($lineId);
        $this->assertSame(WholesaleOrderLine::BARCODE_UPLOADED, $line->barcode_mode);
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
}
