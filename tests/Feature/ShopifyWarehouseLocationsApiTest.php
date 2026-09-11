<?php

namespace Tests\Feature;

use App\Jobs\PushShopifyVariantInventoryJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyInventoryLevel;
use App\Models\ShopifyLocation;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyWarehouseLocation;
use App\Models\ShopifyWarehouseLocationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopifyWarehouseLocationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_locations_require_admin(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/shopify/locations')->assertForbidden();
    }

    public function test_meta_returns_types_and_add_item_reasons(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/shopify/locations/meta')->assertOk();

        $types = $response->json('types');
        $this->assertIsArray($types);
        $this->assertContains('Large Shelf', $types);
        $this->assertContains('Medium Shelf', $types);
        $this->assertContains('Small Shelf', $types);

        $reasons = $response->json('add_item_reasons');
        $this->assertIsArray($reasons);
        $this->assertSame(config('inventory.adjustment_reasons'), $reasons);
        $this->assertContains('Amazon Return', $reasons);
        $this->assertContains('Order Fulfilment', $reasons);
        $this->assertSame(
            config('inventory.default_add_location_reason'),
            $response->json('default_add_item_reason')
        );
    }

    public function test_store_item_rejects_unknown_reason(): void
    {
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-100',
            'type' => 'Large Shelf',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant();

        $this->postJson("/api/shopify/locations/{$location->id}/items", [
            'client_account_id' => $variant->connection->client_account_id,
            'shopify_variant_id' => $variant->id,
            'available' => 5,
            'reason' => 'Not A Real Reason',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_store_item_rolls_up_to_sync_inventory_level_and_pushes_inline(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-100',
            'type' => 'Large Shelf',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('ROLL-1');
        ShopifyLocation::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_location_id' => '9001',
            'name' => 'Main Warehouse',
            'active' => true,
            'sync_inventory' => true,
        ]);
        ShopifyLocation::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_location_id' => '9002',
            'name' => 'Inactive Sync Off',
            'active' => true,
            'sync_inventory' => false,
        ]);

        $sync = \Mockery::mock(\App\Services\ShopifyProductSyncService::class);
        $sync->shouldReceive('pushInventoryToShopify')->twice()->andReturn(1);
        $this->app->instance(\App\Services\ShopifyProductSyncService::class, $sync);
        $this->app->forgetInstance(\App\Services\ShopifyWarehouseInventorySyncService::class);

        $this->postJson("/api/shopify/locations/{$location->id}/items", [
            'client_account_id' => $variant->connection->client_account_id,
            'shopify_variant_id' => $variant->id,
            'available' => 5,
            'reason' => 'Restock',
        ])->assertCreated()
            ->assertJsonPath('item.available', 5)
            ->assertJsonPath('shopify_sync.status', 'pushed')
            ->assertJsonPath('shopify_sync.pushed', 1);

        $this->assertDatabaseHas('shopify_inventory_levels', [
            'connection_id' => $variant->connection_id,
            'shopify_inventory_item_id' => (string) $variant->shopify_inventory_item_id,
            'shopify_location_id' => '9001',
            'available' => 5,
        ]);

        // Only sync_inventory=true location should get a level row.
        $this->assertSame(
            1,
            ShopifyInventoryLevel::query()->where('connection_id', $variant->connection_id)->count()
        );

        // Second add increments both warehouse item and Shopify level.
        $this->postJson("/api/shopify/locations/{$location->id}/items", [
            'client_account_id' => $variant->connection->client_account_id,
            'shopify_variant_id' => $variant->id,
            'available' => 3,
            'reason' => 'Restock',
        ])->assertCreated()
            ->assertJsonPath('item.available', 8)
            ->assertJsonPath('shopify_sync.status', 'pushed');

        $this->assertSame(
            8,
            (int) ShopifyInventoryLevel::query()
                ->where('connection_id', $variant->connection_id)
                ->where('shopify_location_id', '9001')
                ->value('available')
        );

        Bus::assertNotDispatched(PushShopifyVariantInventoryJob::class);
    }

    public function test_apply_available_delta_pushes_inline_on_success(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $variant = $this->makeVariant('PUSH-1');
        ShopifyLocation::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_location_id' => '6100',
            'name' => 'Main',
            'active' => true,
            'sync_inventory' => true,
        ]);

        $sync = \Mockery::mock(\App\Services\ShopifyProductSyncService::class);
        $sync->shouldReceive('pushInventoryToShopify')->once()->andReturn(1);
        $this->app->instance(\App\Services\ShopifyProductSyncService::class, $sync);
        $this->app->forgetInstance(\App\Services\ShopifyWarehouseInventorySyncService::class);

        $result = app(\App\Services\ShopifyWarehouseInventorySyncService::class)
            ->applyAvailableDelta($variant, 2);

        $this->assertSame('pushed', $result['status']);
        $this->assertSame(1, $result['pushed'] ?? null);
        Bus::assertNotDispatched(PushShopifyVariantInventoryJob::class);
    }

    public function test_apply_available_delta_queues_retry_when_inline_push_fails(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $variant = $this->makeVariant('PUSH-FAIL');
        ShopifyLocation::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_location_id' => '6101',
            'name' => 'Main',
            'active' => true,
            'sync_inventory' => true,
        ]);

        $sync = \Mockery::mock(\App\Services\ShopifyProductSyncService::class);
        $sync->shouldReceive('pushInventoryToShopify')
            ->once()
            ->andThrow(new \RuntimeException('Shopify GraphQL error'));
        $this->app->instance(\App\Services\ShopifyProductSyncService::class, $sync);
        $this->app->forgetInstance(\App\Services\ShopifyWarehouseInventorySyncService::class);

        $result = app(\App\Services\ShopifyWarehouseInventorySyncService::class)
            ->applyAvailableDelta($variant, 2);

        $this->assertSame('queued', $result['status']);
        Bus::assertDispatched(PushShopifyVariantInventoryJob::class, function ($job) use ($variant) {
            return (int) $job->variantId === (int) $variant->id;
        });
    }

    public function test_store_item_pushes_when_sync_inventory_flag_off_but_location_exists(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-FALLBACK',
            'type' => 'Large Shelf',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('FALLBACK-1');
        ShopifyLocation::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_location_id' => '9100',
            'name' => 'Shop location',
            'active' => true,
            'sync_inventory' => false,
        ]);

        $sync = \Mockery::mock(\App\Services\ShopifyProductSyncService::class);
        $sync->shouldReceive('pushInventoryToShopify')->once()->andReturn(1);
        $this->app->instance(\App\Services\ShopifyProductSyncService::class, $sync);
        $this->app->forgetInstance(\App\Services\ShopifyWarehouseInventorySyncService::class);

        $this->postJson("/api/shopify/locations/{$location->id}/items", [
            'client_account_id' => $variant->connection->client_account_id,
            'shopify_variant_id' => $variant->id,
            'available' => 2,
            'reason' => 'Restock',
        ])->assertCreated()
            ->assertJsonPath('item.available', 2)
            ->assertJsonPath('shopify_sync.status', 'pushed');

        $this->assertDatabaseHas('shopify_inventory_levels', [
            'connection_id' => $variant->connection_id,
            'shopify_inventory_item_id' => (string) $variant->shopify_inventory_item_id,
            'shopify_location_id' => '9100',
            'available' => 2,
        ]);
    }

    public function test_store_item_skips_shopify_sync_without_any_shopify_location(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-SKIP',
            'type' => 'Large Shelf',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('SKIP-1');

        $this->postJson("/api/shopify/locations/{$location->id}/items", [
            'client_account_id' => $variant->connection->client_account_id,
            'shopify_variant_id' => $variant->id,
            'available' => 2,
            'reason' => 'Restock',
        ])->assertCreated()
            ->assertJsonPath('item.available', 2)
            ->assertJsonPath('shopify_sync.status', 'skipped')
            ->assertJsonPath('shopify_sync.reason', 'no_sync_inventory_locations');

        Bus::assertNotDispatched(PushShopifyVariantInventoryJob::class);
    }

    public function test_push_inventory_matches_gid_and_numeric_location_ids(): void
    {
        $variant = $this->makeVariant('GID-1');
        $connection = $variant->connection;

        ShopifyLocation::query()->create([
            'connection_id' => $connection->id,
            'shopify_location_id' => 'gid://shopify/Location/7777',
            'name' => 'GID Loc',
            'active' => true,
            'sync_inventory' => true,
        ]);
        ShopifyInventoryLevel::query()->create([
            'connection_id' => $connection->id,
            'shopify_inventory_item_id' => (string) $variant->shopify_inventory_item_id,
            'shopify_location_id' => '7777',
            'available' => 9,
            'crm_set_at' => now(),
        ]);

        $api = \Mockery::mock(\App\Services\ShopifyClient::class);
        $api->shouldReceive('graphql')
            ->once()
            ->withArgs(function (string $query) {
                return str_contains($query, 'inventoryActivate');
            })
            ->andReturn([
                'inventoryActivate' => ['userErrors' => []],
            ]);
        $api->shouldReceive('graphql')
            ->once()
            ->withArgs(function (string $query, array $vars) use ($variant) {
                $qty = $vars['input']['quantities'][0] ?? null;
                $input = $vars['input'] ?? [];

                return str_contains($query, 'inventorySetQuantities')
                    && str_contains($query, '@idempotent')
                    && is_string($vars['idempotencyKey'] ?? null)
                    && ($vars['idempotencyKey'] ?? '') !== ''
                    && ($input['ignoreCompareQuantity'] ?? null) === true
                    && is_array($qty)
                    && ($qty['locationId'] ?? null) === 'gid://shopify/Location/7777'
                    && ($qty['inventoryItemId'] ?? null) === 'gid://shopify/InventoryItem/'.$variant->shopify_inventory_item_id
                    && (int) ($qty['quantity'] ?? 0) === 9
                    && array_key_exists('changeFromQuantity', $qty)
                    && ($qty['changeFromQuantity'] ?? 'missing') === null;
            })
            ->andReturn([
                'inventorySetQuantities' => ['userErrors' => []],
            ]);

        $client = \Mockery::mock(\App\Services\ShopifyClient::class);
        $client->shouldReceive('forConnection')->andReturn($api);
        $this->app->instance(\App\Services\ShopifyClient::class, $client);
        $this->app->forgetInstance(\App\Services\ShopifyProductSyncService::class);

        $pushed = app(\App\Services\ShopifyProductSyncService::class)
            ->pushInventoryToShopify($variant->fresh('connection'));

        $this->assertSame(1, $pushed);
    }

    public function test_push_variant_inventory_endpoint_pushes_only_this_variant(): void
    {
        $this->actingAsAdmin();
        $variant = $this->makeVariant('PUSH-VAR');
        $other = $this->makeVariant('PUSH-OTHER');

        // Same connection for both so store-wide push would hit both.
        $other->connection_id = $variant->connection_id;
        $other->shopify_product_id = $variant->shopify_product_id;
        $other->save();

        ShopifyLocation::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_location_id' => '8888',
            'name' => 'Main',
            'active' => true,
            'sync_inventory' => true,
        ]);
        ShopifyInventoryLevel::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_inventory_item_id' => (string) $variant->shopify_inventory_item_id,
            'shopify_location_id' => '8888',
            'available' => 4,
            'crm_set_at' => now(),
        ]);
        ShopifyInventoryLevel::query()->create([
            'connection_id' => $other->connection_id,
            'shopify_inventory_item_id' => (string) $other->shopify_inventory_item_id,
            'shopify_location_id' => '8888',
            'available' => 99,
            'crm_set_at' => now(),
        ]);

        $api = \Mockery::mock(\App\Services\ShopifyClient::class);
        $api->shouldReceive('graphql')
            ->once()
            ->withArgs(function (string $query, array $vars) use ($variant) {
                $qty = $vars['input']['quantities'][0] ?? null;

                return str_contains($query, '@idempotent')
                    && (int) ($qty['quantity'] ?? 0) === 4
                    && ($qty['inventoryItemId'] ?? null) === 'gid://shopify/InventoryItem/'.$variant->shopify_inventory_item_id;
            })
            ->andReturn([
                'inventorySetQuantities' => ['userErrors' => []],
            ]);

        $client = \Mockery::mock(\App\Services\ShopifyClient::class);
        $client->shouldReceive('forConnection')->andReturn($api);
        $this->app->instance(\App\Services\ShopifyClient::class, $client);
        $this->app->forgetInstance(\App\Services\ShopifyProductSyncService::class);

        $this->postJson("/api/shopify/inventory/{$variant->id}/push-inventory")
            ->assertOk()
            ->assertJsonPath('pushed', 1);
    }

    public function test_update_item_qty_applies_delta_to_sync_level(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-200',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('ROLL-2');
        ShopifyLocation::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_location_id' => '8001',
            'name' => 'Main',
            'active' => true,
            'sync_inventory' => true,
        ]);
        ShopifyInventoryLevel::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_inventory_item_id' => (string) $variant->shopify_inventory_item_id,
            'shopify_location_id' => '8001',
            'available' => 10,
            'crm_set_at' => now(),
        ]);
        $item = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $location->id,
            'shopify_variant_id' => $variant->id,
            'available' => 10,
        ]);

        $sync = \Mockery::mock(\App\Services\ShopifyProductSyncService::class);
        $sync->shouldReceive('pushInventoryToShopify')->once()->andReturn(1);
        $this->app->instance(\App\Services\ShopifyProductSyncService::class, $sync);
        $this->app->forgetInstance(\App\Services\ShopifyWarehouseInventorySyncService::class);

        $this->patchJson("/api/shopify/locations/{$location->id}/items/{$item->id}", [
            'available' => 4,
            'reason' => 'Cycle Counts / Physical Counts',
        ])->assertOk()
            ->assertJsonPath('item.available', 4)
            ->assertJsonPath('shopify_sync.status', 'pushed');

        $this->assertSame(
            4,
            (int) ShopifyInventoryLevel::query()
                ->where('connection_id', $variant->connection_id)
                ->where('shopify_location_id', '8001')
                ->value('available')
        );
    }

    public function test_transfer_does_not_change_inventory_levels_or_dispatch_push(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $this->actingAsAdmin();

        $from = ShopifyWarehouseLocation::query()->create([
            'name' => 'FROM-1',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $to = ShopifyWarehouseLocation::query()->create([
            'name' => 'TO-1',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('XFER-1');
        ShopifyLocation::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_location_id' => '7001',
            'name' => 'Main',
            'active' => true,
            'sync_inventory' => true,
        ]);
        ShopifyInventoryLevel::query()->create([
            'connection_id' => $variant->connection_id,
            'shopify_inventory_item_id' => (string) $variant->shopify_inventory_item_id,
            'shopify_location_id' => '7001',
            'available' => 50,
            'crm_set_at' => now(),
        ]);
        $item = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variant->id,
            'available' => 50,
        ]);

        $this->postJson("/api/shopify/locations/{$from->id}/transfer", [
            'item_id' => $item->id,
            'to_location_id' => $to->id,
            'quantity' => 20,
            'reason' => 'Restock',
        ])->assertOk();

        $this->assertSame(
            50,
            (int) ShopifyInventoryLevel::query()
                ->where('connection_id', $variant->connection_id)
                ->where('shopify_location_id', '7001')
                ->value('available')
        );
        Bus::assertNotDispatched(PushShopifyVariantInventoryJob::class);
    }

    public function test_can_add_item_to_location_with_reason(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-100',
            'type' => 'Large Shelf',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant();

        $this->postJson("/api/shopify/locations/{$location->id}/items", [
            'client_account_id' => $variant->connection->client_account_id,
            'shopify_variant_id' => $variant->id,
            'available' => 5,
            'reason' => 'Restock',
        ])->assertCreated()
            ->assertJsonPath('item.available', 5);

        $this->postJson("/api/shopify/locations/{$location->id}/items", [
            'client_account_id' => $variant->connection->client_account_id,
            'shopify_variant_id' => $variant->id,
            'available' => 3,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_can_create_search_filter_and_bulk_edit_locations(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/shopify/locations', [
            'name' => 'A-01-042',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ])->assertCreated();

        $this->postJson('/api/shopify/locations', [
            'name' => 'B-02-117',
            'type' => 'Medium Bin',
            'pickable' => false,
            'sellable' => true,
        ])->assertCreated();

        $this->getJson('/api/shopify/locations?q=A-01')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'A-01-042');

        $this->getJson('/api/shopify/locations?pickable=0')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'B-02-117');

        $b = ShopifyWarehouseLocation::query()->where('name', 'B-02-117')->first();
        $this->postJson('/api/shopify/locations/bulk', [
            'ids' => [$b->id],
            'type' => 'Large Pallet',
            'pickable' => true,
            'sellable' => false,
        ])->assertOk()->assertJsonPath('updated', 1);

        $b->refresh();
        $this->assertSame('Large Pallet', $b->type);
        $this->assertTrue($b->pickable);
        $this->assertFalse($b->sellable);
    }

    public function test_can_import_csv_and_transfer_qty(): void
    {
        $this->actingAsAdmin();
        $csv = "Location Name,Type,Pickable,Sellable\nA-04-044,Large Pallet,Yes,Yes\nC-03-256,Small Pallet,No,Yes\n";
        $file = UploadedFile::fake()->createWithContent('locations.csv', $csv);

        $this->post('/api/shopify/locations/import', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('created', 2);

        $from = ShopifyWarehouseLocation::query()->where('name', 'A-04-044')->first();
        $to = ShopifyWarehouseLocation::query()->where('name', 'C-03-256')->first();
        $variant = $this->makeVariant();
        $item = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variant->id,
            'available' => 120,
        ]);

        $this->postJson("/api/shopify/locations/{$from->id}/transfer", [
            'item_id' => $item->id,
            'to_location_id' => $to->id,
            'quantity' => 40,
            'reason' => 'Restock',
        ])->assertOk();

        $this->assertSame(80, (int) ShopifyWarehouseLocationItem::query()->where('id', $item->id)->value('available'));
        $this->assertSame(40, (int) ShopifyWarehouseLocationItem::query()
            ->where('location_id', $to->id)
            ->where('shopify_variant_id', $variant->id)
            ->value('available'));
    }

    public function test_cannot_delete_location_with_inventory(): void
    {
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-042',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant();
        ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $location->id,
            'shopify_variant_id' => $variant->id,
            'available' => 10,
        ]);

        $this->deleteJson("/api/shopify/locations/{$location->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location']);

        $this->assertDatabaseHas('shopify_warehouse_locations', ['id' => $location->id]);
    }

    public function test_can_delete_empty_location(): void
    {
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-043',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);

        $this->deleteJson("/api/shopify/locations/{$location->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Location deleted.');

        $this->assertDatabaseMissing('shopify_warehouse_locations', ['id' => $location->id]);
    }

    public function test_bulk_transfer_moves_full_qty_for_multiple_items(): void
    {
        $this->actingAsAdmin();

        $from = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-04-044',
            'type' => 'Large Pallet',
            'pickable' => true,
            'sellable' => true,
        ]);
        $to = ShopifyWarehouseLocation::query()->create([
            'name' => 'C-03-256',
            'type' => 'Small Pallet',
            'pickable' => false,
            'sellable' => true,
        ]);
        $variantA = $this->makeVariant('SKU-A');
        $variantB = $this->makeVariant('SKU-B');
        $itemA = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variantA->id,
            'available' => 50,
        ]);
        $itemB = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variantB->id,
            'available' => 30,
        ]);

        $this->postJson("/api/shopify/locations/{$from->id}/bulk-transfer", [
            'item_ids' => [$itemA->id, $itemB->id],
            'to_location_id' => $to->id,
            'reason' => 'Restock',
        ])->assertOk()
            ->assertJsonPath('transferred', 2)
            ->assertJsonPath('skipped', 0);

        $this->assertDatabaseMissing('shopify_warehouse_location_items', ['id' => $itemA->id]);
        $this->assertDatabaseMissing('shopify_warehouse_location_items', ['id' => $itemB->id]);
        $this->assertSame(50, (int) ShopifyWarehouseLocationItem::query()
            ->where('location_id', $to->id)
            ->where('shopify_variant_id', $variantA->id)
            ->value('available'));
        $this->assertSame(30, (int) ShopifyWarehouseLocationItem::query()
            ->where('location_id', $to->id)
            ->where('shopify_variant_id', $variantB->id)
            ->value('available'));
    }

    public function test_bulk_transfer_rejects_same_source_and_destination(): void
    {
        $this->actingAsAdmin();

        $from = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-04-045',
            'type' => 'Large Pallet',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant();
        $item = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variant->id,
            'available' => 20,
        ]);

        $this->postJson("/api/shopify/locations/{$from->id}/bulk-transfer", [
            'item_ids' => [$item->id],
            'to_location_id' => $from->id,
            'reason' => 'Restock',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['to_location_id']);
    }

    public function test_location_print_pdf_returns_pdf(): void
    {
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-02',
            'type' => 'Medium Pallet',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('aaaa');
        ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $location->id,
            'shopify_variant_id' => $variant->id,
            'available' => 160,
        ]);

        $response = $this->get("/api/shopify/locations/{$location->id}/print.pdf");
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_bulk_barcode_labels_pdf_returns_pdf(): void
    {
        $this->actingAsAdmin();

        $variantA = $this->makeVariant('sku-a');
        $variantA->barcode = 'bc-a';
        $variantA->save();
        $variantB = $this->makeVariant('sku-b');
        $variantB->barcode = 'bc-b';
        $variantB->save();

        $response = $this->postJson('/api/shopify/inventory/barcode-labels.pdf', [
            'ids' => [$variantA->id, $variantB->id],
        ]);
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    private function makeVariant(string $sku = 'TIN'): ShopifyProductVariant
    {
        static $variantCounter = 0;
        $variantCounter++;

        $account = ClientAccount::query()->create([
            'company_name' => 'ADGO Media, LLC '.$variantCounter,
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => "loc-test-{$variantCounter}.myshopify.com",
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
        $product = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => (string) (10 + $variantCounter),
            'title' => 'Vunella Travel Case',
            'status' => 'active',
        ]);

        return ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $product->id,
            'shopify_variant_id' => (string) (20 + $variantCounter),
            'shopify_inventory_item_id' => (string) (30 + $variantCounter),
            'title' => 'Default',
            'sku' => $sku,
        ]);
    }
}
