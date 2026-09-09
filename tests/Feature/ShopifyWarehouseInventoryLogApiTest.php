<?php

namespace Tests\Feature;

use App\Jobs\PushShopifyVariantInventoryJob;
use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyWarehouseInventoryLog;
use App\Models\ShopifyWarehouseLocation;
use App\Models\ShopifyWarehouseLocationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopifyWarehouseInventoryLogApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'client_account_id' => null,
            'name' => 'Alex Morgan',
        ]);
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_transfer_creates_two_inventory_log_rows(): void
    {
        $user = $this->actingAsAdmin();

        $from = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-005',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $to = ShopifyWarehouseLocation::query()->create([
            'name' => 'B-03-022',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('LOG-1');
        $item = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variant->id,
            'available' => 100,
        ]);

        $this->postJson("/api/shopify/locations/{$from->id}/transfer", [
            'item_id' => $item->id,
            'to_location_id' => $to->id,
            'quantity' => 50,
            'reason' => 'Restock',
        ])->assertOk();

        $logs = ShopifyWarehouseInventoryLog::query()
            ->where('shopify_variant_id', $variant->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $logs);

        $out = $logs->firstWhere('type', ShopifyWarehouseInventoryLog::TYPE_TRANSFER_OUT);
        $in = $logs->firstWhere('type', ShopifyWarehouseInventoryLog::TYPE_TRANSFER_IN);
        $this->assertNotNull($out);
        $this->assertNotNull($in);
        $this->assertSame($out->transfer_group, $in->transfer_group);
        $this->assertStringStartsWith('TRF-', (string) $out->transfer_group);
        $this->assertSame('Transfer From A-01-005 to B-03-022 - QTY: 50 - Restock', $out->note);
        $this->assertSame($out->note, $in->note);
        $this->assertSame(100, (int) $out->old_on_hand);
        $this->assertSame(50, (int) $out->new_on_hand);
        $this->assertSame(-50, (int) $out->quantity_delta);
        $this->assertSame(0, (int) $in->old_on_hand);
        $this->assertSame(50, (int) $in->new_on_hand);
        $this->assertSame(50, (int) $in->quantity_delta);
        $this->assertSame((int) $user->id, (int) $out->user_id);
        $this->assertSame(ShopifyWarehouseInventoryLog::TYPE_LABEL_TRANSFER, $out->type_label);
    }

    public function test_bulk_transfer_creates_two_rows_per_item(): void
    {
        $this->actingAsAdmin();

        $from = ShopifyWarehouseLocation::query()->create([
            'name' => 'FROM-BULK',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $to = ShopifyWarehouseLocation::query()->create([
            'name' => 'TO-BULK',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variantA = $this->makeVariant('BULK-A');
        $variantB = $this->makeVariant('BULK-B');
        $itemA = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variantA->id,
            'available' => 20,
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
            ->assertJsonPath('transferred', 2);

        $this->assertSame(2, ShopifyWarehouseInventoryLog::query()->where('shopify_variant_id', $variantA->id)->count());
        $this->assertSame(2, ShopifyWarehouseInventoryLog::query()->where('shopify_variant_id', $variantB->id)->count());
    }

    public function test_store_item_creates_adjustment_inventory_log(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'ADD-LOC',
            'type' => 'Large Shelf',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('ADD-LOG');

        $sync = \Mockery::mock(\App\Services\ShopifyProductSyncService::class);
        $sync->shouldReceive('pushInventoryToShopify')->andReturn(0);
        $this->app->instance(\App\Services\ShopifyProductSyncService::class, $sync);
        $this->app->forgetInstance(\App\Services\ShopifyWarehouseInventorySyncService::class);

        $this->postJson("/api/shopify/locations/{$location->id}/items", [
            'client_account_id' => $variant->connection->client_account_id,
            'shopify_variant_id' => $variant->id,
            'available' => 5,
            'reason' => 'Account Setup',
        ])->assertCreated();

        $log = ShopifyWarehouseInventoryLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(ShopifyWarehouseInventoryLog::TYPE_ADJUSTMENT, $log->type);
        $this->assertSame('Account Setup', $log->type_label);
        $this->assertSame(5, (int) $log->quantity_delta);
        $this->assertSame(0, (int) $log->old_on_hand);
        $this->assertSame(5, (int) $log->new_on_hand);
        $this->assertSame('Added 5', $log->note);
    }

    public function test_update_item_qty_creates_adjustment_inventory_log(): void
    {
        Bus::fake([PushShopifyVariantInventoryJob::class]);
        $this->actingAsAdmin();

        $location = ShopifyWarehouseLocation::query()->create([
            'name' => 'EDIT-LOC',
            'type' => 'Large Shelf',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('EDIT-LOG');
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
        ])->assertOk();

        $log = ShopifyWarehouseInventoryLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(ShopifyWarehouseInventoryLog::TYPE_ADJUSTMENT, $log->type);
        $this->assertSame('Cycle Counts / Physical Counts', $log->type_label);
        $this->assertSame(-6, (int) $log->quantity_delta);
        $this->assertSame(10, (int) $log->old_on_hand);
        $this->assertSame(4, (int) $log->new_on_hand);
    }

    public function test_transfer_requires_reason(): void
    {
        $this->actingAsAdmin();

        $from = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-REQ',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $to = ShopifyWarehouseLocation::query()->create([
            'name' => 'B-REQ',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('REQ-1');
        $item = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variant->id,
            'available' => 5,
        ]);

        $this->postJson("/api/shopify/locations/{$from->id}/transfer", [
            'item_id' => $item->id,
            'to_location_id' => $to->id,
            'quantity' => 2,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_logs_meta_includes_transfer_and_adjustment_reasons(): void
    {
        $this->actingAsAdmin();

        $types = $this->getJson('/api/shopify/inventory/logs/meta')
            ->assertOk()
            ->json('types');

        $this->assertIsArray($types);
        $this->assertSame('Transfer', $types[0]);
        $this->assertContains('Account Setup', $types);
        $this->assertContains('Restock', $types);
    }

    public function test_list_logs_filters_by_location_type_and_changed_by(): void
    {
        $user = $this->actingAsAdmin();

        $from = ShopifyWarehouseLocation::query()->create([
            'name' => 'A-01-005',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $to = ShopifyWarehouseLocation::query()->create([
            'name' => 'B-03-022',
            'type' => 'Large Bin',
            'pickable' => true,
            'sellable' => true,
        ]);
        $variant = $this->makeVariant('FILTER-1');
        $item = ShopifyWarehouseLocationItem::query()->create([
            'location_id' => $from->id,
            'shopify_variant_id' => $variant->id,
            'available' => 40,
        ]);

        $this->postJson("/api/shopify/locations/{$from->id}/transfer", [
            'item_id' => $item->id,
            'to_location_id' => $to->id,
            'quantity' => 10,
            'reason' => 'Restock',
        ])->assertOk();

        $this->getJson("/api/shopify/inventory/{$variant->id}/logs?q=B-03")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.location_name', 'B-03-022')
            ->assertJsonPath('data.0.quantity_delta', 10);

        $this->getJson("/api/shopify/inventory/{$variant->id}/logs?type=Transfer")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/shopify/inventory/'.$variant->id.'/logs?changed_by='.urlencode('Alex'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.changed_by.name', $user->name);

        $this->getJson("/api/shopify/inventory/{$variant->id}/logs?type=Account Setup")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    private function makeVariant(string $sku = 'TIN'): ShopifyProductVariant
    {
        static $variantCounter = 0;
        $variantCounter++;

        $account = ClientAccount::query()->create([
            'company_name' => 'Log Test Co '.$variantCounter,
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => "log-test-{$variantCounter}.myshopify.com",
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
        $product = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => (string) (100 + $variantCounter),
            'title' => 'The Multi-managed Snowboard',
            'status' => 'active',
        ]);

        return ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $product->id,
            'shopify_variant_id' => (string) (200 + $variantCounter),
            'shopify_inventory_item_id' => (string) (300 + $variantCounter),
            'title' => 'Default',
            'sku' => $sku,
        ]);
    }
}
