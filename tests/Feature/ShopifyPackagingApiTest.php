<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShopifyPackagingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopifyPackagingApiTest extends TestCase
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

    public function test_packaging_requires_admin(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/shopify/packaging')->assertForbidden();
    }

    public function test_create_list_filter_update_and_delete_packaging(): void
    {
        $this->actingAsAdmin();

        $created = $this->postJson('/api/shopify/packaging', [
            'name' => '6x6x4',
            'sku' => 'BOX-664',
            'category' => 'packaging',
            'type' => 'box',
            'cost' => 0.50,
            'price' => 0.60,
            'on_hand' => 550,
            'length' => 6,
            'width' => 6,
            'height' => 4,
            'weight' => 0.2,
        ])->assertCreated()->json('item');

        $this->assertSame('Packaging', $created['category_label']);
        $this->assertSame('Box', $created['type_label']);
        $this->assertSame(50, $created['cost_cents']);
        $this->assertSame(60, $created['price_cents']);
        $this->assertSame(550, $created['on_hand']);
        $this->assertEquals(0.083, $created['cubic_ft']);
        $this->assertArrayNotHasKey('barcode', $created);

        $this->postJson('/api/shopify/packaging', [
            'name' => 'Kraft Roll',
            'category' => 'packaging_materials',
            'type' => 'kraft_paper',
            'cost' => 1,
            'price' => 1.25,
            'on_hand' => 12,
        ])->assertCreated();

        $this->postJson('/api/shopify/packaging', [
            'name' => 'Wrong Type',
            'category' => 'packaging',
            'type' => 'peanuts',
        ])->assertStatus(422);

        $list = $this->getJson('/api/shopify/packaging?q=6x6&category=packaging&type=box')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('6x6x4', $list[0]['name']);

        $updated = $this->patchJson('/api/shopify/packaging/'.$created['id'], [
            'name' => '6x6x4',
            'sku' => 'BOX-664-B',
            'category' => 'packaging',
            'type' => 'poly_mailer',
            'cost' => 0.55,
            'price' => 0.70,
            'on_hand' => 540,
            'length' => 6,
            'width' => 6,
            'height' => 4,
        ])->assertOk()->json('item');

        $this->assertSame('BOX-664-B', $updated['sku']);
        $this->assertSame('Poly Mailer', $updated['type_label']);
        $this->assertSame(540, $updated['on_hand']);

        $this->deleteJson('/api/shopify/packaging/'.$created['id'])->assertOk();
        $this->assertNull(ShopifyPackagingItem::query()->find($created['id']));
    }

    public function test_upload_and_remove_icon(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $id = $this->postJson('/api/shopify/packaging', [
            'name' => 'Bubble Mailer',
            'category' => 'packaging',
            'type' => 'bubble_mailer',
        ])->assertCreated()->json('item.id');

        $uploaded = $this->postJson('/api/shopify/packaging/'.$id.'/image', [
            'image' => UploadedFile::fake()->image('icon.png', 80, 80),
        ])->assertOk()->json('item');

        $this->assertNotEmpty($uploaded['image_url']);
        $item = ShopifyPackagingItem::query()->findOrFail($id);
        Storage::disk('public')->assertExists($item->image_path);

        $cleared = $this->patchJson('/api/shopify/packaging/'.$id, [
            'name' => 'Bubble Mailer',
            'category' => 'packaging',
            'type' => 'bubble_mailer',
            'remove_image' => true,
        ])->assertOk()->json('item');

        $this->assertNull($cleared['image_url']);
    }
}
