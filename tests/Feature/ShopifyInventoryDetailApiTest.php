<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountShopifyConnection;
use App\Models\Role;
use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyVariantBundleComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopifyInventoryDetailApiTest extends TestCase
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

    /**
     * @return array{0:ClientAccountShopifyConnection,1:ShopifyProductVariant,2:ShopifyProductVariant}
     */
    private function seedBundlePair(): array
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Bundle Co',
            'status' => ClientAccount::STATUS_ACTIVE,
        ]);
        $connection = ClientAccountShopifyConnection::query()->create([
            'client_account_id' => $account->id,
            'shop_domain' => 'bundle.myshopify.com',
            'admin_api_access_token' => '',
            'status' => ClientAccountShopifyConnection::STATUS_CONNECTED,
        ]);

        $parentProduct = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => '10',
            'title' => 'Parent Bundle',
            'status' => 'active',
            'crm_product_kind' => ShopifyProduct::KIND_STANDARD,
        ]);
        $parent = ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $parentProduct->id,
            'shopify_variant_id' => '100',
            'shopify_inventory_item_id' => '1000',
            'title' => 'Default',
            'sku' => 'PARENT-1',
        ]);

        $childProduct = ShopifyProduct::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => '11',
            'title' => 'Child Item',
            'status' => 'active',
            'crm_product_kind' => ShopifyProduct::KIND_STANDARD,
        ]);
        $child = ShopifyProductVariant::query()->create([
            'connection_id' => $connection->id,
            'shopify_product_id' => $childProduct->id,
            'shopify_variant_id' => '110',
            'shopify_inventory_item_id' => '1100',
            'title' => 'Default',
            'sku' => 'CHILD-1',
        ]);

        return [$connection, $parent, $child];
    }

    public function test_settings_patch_updates_status_and_product_type(): void
    {
        $this->actingAsAdmin();
        [, $parent] = $this->seedBundlePair();

        $this->patchJson('/api/shopify/inventory/'.$parent->id.'/settings', [
            'status' => 'inactive',
            'product_type' => 'bundle',
        ])->assertOk()
            ->assertJsonPath('variant.status', 'inactive')
            ->assertJsonPath('variant.product_type', 'bundle')
            ->assertJsonPath('variant.bundle', true);

        $parent->product->refresh();
        $this->assertSame('inactive', $parent->product->status);
        $this->assertSame(ShopifyProduct::KIND_BUNDLE, $parent->product->crm_product_kind);
    }

    public function test_list_bundle_filter_uses_crm_product_kind(): void
    {
        $this->actingAsAdmin();
        [, $parent] = $this->seedBundlePair();

        $this->getJson('/api/shopify/inventory?bundle=yes')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $parent->product->crm_product_kind = ShopifyProduct::KIND_BUNDLE;
        $parent->product->save();

        $this->getJson('/api/shopify/inventory?bundle=yes')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $parent->id);
    }

    public function test_bundle_components_crud(): void
    {
        $this->actingAsAdmin();
        [, $parent, $child] = $this->seedBundlePair();

        $parent->product->crm_product_kind = ShopifyProduct::KIND_BUNDLE;
        $parent->product->save();

        $this->putJson('/api/shopify/inventory/'.$parent->id.'/bundle-components', [
            'items' => [
                ['component_variant_id' => $child->id, 'quantity' => 2],
            ],
        ])->assertOk()
            ->assertJsonPath('components.0.component_variant_id', $child->id)
            ->assertJsonPath('components.0.quantity', 2);

        $row = ShopifyVariantBundleComponent::query()->where('parent_variant_id', $parent->id)->first();
        $this->assertNotNull($row);

        $this->patchJson('/api/shopify/inventory/'.$parent->id.'/bundle-components/'.$row->id, [
            'quantity' => 5,
        ])->assertOk()->assertJsonPath('components.0.quantity', 5);

        $this->deleteJson('/api/shopify/inventory/'.$parent->id.'/bundle-components/'.$row->id)
            ->assertOk()
            ->assertJsonPath('components', []);
    }

    public function test_crm_image_upload_sets_display_url(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        [, $parent] = $this->seedBundlePair();

        $file = UploadedFile::fake()->image('crm.jpg', 80, 80);
        $this->post('/api/shopify/inventory/'.$parent->id.'/image', [
            'image' => $file,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('variant.id', $parent->id);

        $parent->refresh();
        $this->assertNotEmpty($parent->crm_image_path);
        Storage::disk('public')->assertExists($parent->crm_image_path);
    }
}
