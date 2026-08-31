<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyWarehouseLocation;
use App\Models\ShopifyWarehouseLocationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        $this->getJson('/api/shopify/locations/meta')
            ->assertOk()
            ->assertJsonFragment(['Large Shelf', 'Medium Shelf', 'Small Shelf'])
            ->assertJsonFragment(['Cycle Count', 'Receiving Discrepancy', 'Return']);
    }

    public function test_can_add_item_to_location_with_reason(): void
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
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['to_location_id']);
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
