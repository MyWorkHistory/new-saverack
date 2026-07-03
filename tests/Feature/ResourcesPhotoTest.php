<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ResourcePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResourcesPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function resourcesViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'resources.view'],
            ['label' => 'View resources', 'module' => 'resources']
        );
    }

    private function resourcesCreatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'resources.create'],
            ['label' => 'Create resources', 'module' => 'resources']
        );
    }

    private function resourcesDeletePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'resources.delete'],
            ['label' => 'Delete resources', 'module' => 'resources']
        );
    }

    private function staffWithView(): User
    {
        $user = User::factory()->create();
        $user->permissions()->attach($this->resourcesViewPermission()->id);

        return $user;
    }

    public function test_guest_cannot_access_photos(): void
    {
        $this->getJson('/api/resources/photos')->assertUnauthorized();
    }

    public function test_user_without_resources_view_cannot_list_photos(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/resources/photos')->assertForbidden();
    }

    public function test_user_with_resources_view_can_list_photos(): void
    {
        Sanctum::actingAs($this->staffWithView());

        $this->getJson('/api/resources/photos')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_user_without_create_cannot_upload_photo(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->staffWithView());

        $this->post('/api/resources/photos', [
            'name' => 'Shelf layout',
            'photo' => UploadedFile::fake()->image('shelf.jpg'),
        ])->assertForbidden();
    }

    public function test_user_with_create_can_upload_list_and_download_photo(): void
    {
        Storage::fake('local');
        $user = $this->staffWithView();
        $user->permissions()->attach($this->resourcesCreatePermission()->id);
        Sanctum::actingAs($user);

        $this->post('/api/resources/photos', [
            'name' => 'Shelf layout',
            'photo' => UploadedFile::fake()->image('shelf.jpg'),
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Shelf layout');

        $photoId = (int) ResourcePhoto::query()->value('id');
        $this->assertNotNull($photoId);

        $this->getJson('/api/resources/photos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $photoId)
            ->assertJsonPath('data.0.name', 'Shelf layout');

        $this->get("/api/resources/photos/{$photoId}/file")
            ->assertOk();
    }

    public function test_user_with_delete_can_remove_photo(): void
    {
        Storage::fake('local');
        $user = $this->staffWithView();
        $user->permissions()->attach([
            $this->resourcesCreatePermission()->id,
            $this->resourcesDeletePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $this->post('/api/resources/photos', [
            'name' => 'To remove',
            'photo' => UploadedFile::fake()->image('remove.jpg'),
        ])->assertCreated();

        $photo = ResourcePhoto::query()->first();
        $this->assertNotNull($photo);

        $this->deleteJson("/api/resources/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('resource_photos', ['id' => $photo->id]);
    }
}
