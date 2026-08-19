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

    private function makeVariant(): ShopifyProductVariant
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'ADGO Media, LLC',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => 'loc-test.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
        $product = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => '10',
            'title' => 'Vunella Travel Case',
            'status' => 'active',
        ]);

        return ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $product->id,
            'shopify_variant_id' => '20',
            'shopify_inventory_item_id' => '30',
            'title' => 'Default',
            'sku' => 'TIN',
        ]);
    }
}
