<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyProductVariantActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopifyProductVariantTimelineApiTest extends TestCase
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

    private function makeVariant(): ShopifyProductVariant
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Timeline Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => 'timeline-co.myshopify.com',
            'admin_api_access_token' => 'shpat_test',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);
        $product = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => '9001',
            'title' => 'Old Title',
            'status' => 'active',
        ]);

        return ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $product->id,
            'shopify_variant_id' => '9002',
            'shopify_inventory_item_id' => '9003',
            'title' => 'Default',
            'sku' => 'TL-1',
            'barcode' => 'OLD-BAR',
            'weight' => 1,
            'weight_unit' => 'lb',
            'length' => 1,
            'width' => 2,
            'height' => 3,
            'dimension_unit' => 'INCHES',
        ]);
    }

    public function test_inventory_show_seeds_product_created_timeline(): void
    {
        $this->actingAsAdmin();
        $variant = $this->makeVariant();

        $timeline = $this->getJson("/api/shopify/inventory/{$variant->id}")
            ->assertOk()
            ->json('variant.timeline');

        $this->assertIsArray($timeline);
        $this->assertNotEmpty($timeline);
        $this->assertSame(ShopifyProductVariantActivity::TYPE_CREATED, $timeline[0]['type']);
        $this->assertSame('Product Created', $timeline[0]['title']);
    }

    public function test_update_variant_records_field_timeline_events(): void
    {
        $user = $this->actingAsAdmin();
        $variant = $this->makeVariant();

        $this->patchJson("/api/shopify/inventory/{$variant->id}", [
            'product_title' => 'New Title',
            'barcode' => 'NEW-BAR',
            'weight' => 5.5,
            'weight_unit' => 'lb',
            'length' => 10,
            'width' => 4,
            'height' => 2,
            'dimension_unit' => 'INCHES',
        ])->assertOk();

        $types = ShopifyProductVariantActivity::query()
            ->where('shopify_variant_id', $variant->id)
            ->orderBy('id')
            ->pluck('type')
            ->all();

        $this->assertContains(ShopifyProductVariantActivity::TYPE_NAME, $types);
        $this->assertContains(ShopifyProductVariantActivity::TYPE_BARCODE, $types);
        $this->assertContains(ShopifyProductVariantActivity::TYPE_WEIGHT, $types);
        $this->assertContains(ShopifyProductVariantActivity::TYPE_DIMENSIONS, $types);

        $name = ShopifyProductVariantActivity::query()
            ->where('shopify_variant_id', $variant->id)
            ->where('type', ShopifyProductVariantActivity::TYPE_NAME)
            ->first();
        $this->assertSame('Product Name Updated to: New Title', $name->title);
        $this->assertSame((int) $user->id, (int) $name->actor_user_id);

        $dims = ShopifyProductVariantActivity::query()
            ->where('shopify_variant_id', $variant->id)
            ->where('type', ShopifyProductVariantActivity::TYPE_DIMENSIONS)
            ->first();
        $this->assertSame(10.0, (float) ($dims->meta['length'] ?? 0));
        $this->assertSame(4.0, (float) ($dims->meta['width'] ?? 0));
        $this->assertSame(2.0, (float) ($dims->meta['height'] ?? 0));
    }
}
