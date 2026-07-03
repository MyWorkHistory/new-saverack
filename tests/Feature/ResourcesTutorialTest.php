<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ResourcePhoto;
use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResourcesTutorialTest extends TestCase
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

    private function resourcesUpdatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'resources.update'],
            ['label' => 'Update resources', 'module' => 'resources']
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

    public function test_guest_cannot_access_tutorials(): void
    {
        $this->getJson('/api/resources/tutorials')->assertUnauthorized();
    }

    public function test_user_without_resources_view_cannot_list_tutorials(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/resources/tutorials')->assertForbidden();
    }

    public function test_user_with_resources_view_can_list_and_read_meta(): void
    {
        Sanctum::actingAs($this->staffWithView());

        $this->getJson('/api/resources/tutorials/meta')
            ->assertOk()
            ->assertJsonStructure(['categories']);

        $this->getJson('/api/resources/tutorials')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }

    public function test_user_without_create_cannot_store_tutorial(): void
    {
        Sanctum::actingAs($this->staffWithView());

        $this->postJson('/api/resources/tutorials', [
            'title' => 'Receiving basics',
            'category' => 'receiving',
        ])->assertForbidden();
    }

    public function test_user_with_create_can_store_tutorial(): void
    {
        $user = $this->staffWithView();
        $user->permissions()->attach($this->resourcesCreatePermission()->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/resources/tutorials', [
            'title' => 'Receiving basics',
            'description' => 'See https://example.com/docs',
            'category' => 'receiving',
        ])
            ->assertCreated()
            ->assertJsonPath('title', 'Receiving basics')
            ->assertJsonPath('category', 'receiving');

        $this->assertDatabaseHas('tutorials', [
            'title' => 'Receiving basics',
            'category' => 'receiving',
            'created_by' => $user->id,
        ]);
    }

    public function test_invalid_category_is_rejected(): void
    {
        $user = $this->staffWithView();
        $user->permissions()->attach($this->resourcesCreatePermission()->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/resources/tutorials', [
            'title' => 'Bad category',
            'category' => 'not-a-category',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);
    }

    public function test_user_with_update_can_patch_tutorial(): void
    {
        $user = $this->staffWithView();
        $user->permissions()->attach([
            $this->resourcesCreatePermission()->id,
            $this->resourcesUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $tutorial = Tutorial::query()->create([
            'title' => 'Old title',
            'category' => 'orders',
            'created_by' => $user->id,
        ]);

        $this->patchJson("/api/resources/tutorials/{$tutorial->id}", [
            'title' => 'New title',
        ])
            ->assertOk()
            ->assertJsonPath('title', 'New title');
    }

    public function test_user_with_delete_can_destroy_tutorial(): void
    {
        $user = $this->staffWithView();
        $user->permissions()->attach([
            $this->resourcesCreatePermission()->id,
            $this->resourcesDeletePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $tutorial = Tutorial::query()->create([
            'title' => 'To delete',
            'category' => 'inventory',
            'created_by' => $user->id,
        ]);

        $this->deleteJson("/api/resources/tutorials/{$tutorial->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tutorials', ['id' => $tutorial->id]);
    }

    public function test_user_without_delete_cannot_destroy_tutorial(): void
    {
        $user = $this->staffWithView();
        $user->permissions()->attach($this->resourcesCreatePermission()->id);
        Sanctum::actingAs($user);

        $tutorial = Tutorial::query()->create([
            'title' => 'Protected',
            'category' => 'orders',
            'created_by' => $user->id,
        ]);

        $this->deleteJson("/api/resources/tutorials/{$tutorial->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('tutorials', ['id' => $tutorial->id]);
    }

    public function test_show_includes_photos_array(): void
    {
        Sanctum::actingAs($this->staffWithView());

        $tutorial = Tutorial::query()->create([
            'title' => 'With photos',
            'category' => 'orders',
        ]);

        $this->getJson("/api/resources/tutorials/{$tutorial->id}")
            ->assertOk()
            ->assertJsonPath('photos', []);
    }

    public function test_user_with_create_can_upload_tutorial_photo(): void
    {
        Storage::fake('local');
        $user = $this->staffWithView();
        $user->permissions()->attach($this->resourcesCreatePermission()->id);
        Sanctum::actingAs($user);

        $tutorial = Tutorial::query()->create([
            'title' => 'Photo tutorial',
            'category' => 'receiving',
            'created_by' => $user->id,
        ]);

        $this->post("/api/resources/tutorials/{$tutorial->id}/photos", [
            'name' => 'Step 1',
            'photo' => UploadedFile::fake()->image('step1.jpg'),
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Step 1');

        $this->getJson("/api/resources/tutorials/{$tutorial->id}")
            ->assertOk()
            ->assertJsonPath('photos.0.name', 'Step 1');
    }

    public function test_destroy_tutorial_deletes_associated_photos(): void
    {
        Storage::fake('local');
        $user = $this->staffWithView();
        $user->permissions()->attach([
            $this->resourcesCreatePermission()->id,
            $this->resourcesDeletePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $tutorial = Tutorial::query()->create([
            'title' => 'Cascade delete',
            'category' => 'inventory',
            'created_by' => $user->id,
        ]);

        $this->post("/api/resources/tutorials/{$tutorial->id}/photos", [
            'name' => 'Attached',
            'photo' => UploadedFile::fake()->image('attached.jpg'),
        ])->assertCreated();

        $photoId = (int) ResourcePhoto::query()->value('id');
        $this->assertNotNull($photoId);

        $this->deleteJson("/api/resources/tutorials/{$tutorial->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tutorials', ['id' => $tutorial->id]);
        $this->assertDatabaseMissing('resource_photos', ['id' => $photoId]);
    }

    public function test_user_with_view_can_add_comment_with_attachment(): void
    {
        Storage::fake('local');
        $user = $this->staffWithView();
        Sanctum::actingAs($user);

        $tutorial = Tutorial::query()->create([
            'title' => 'Commented tutorial',
            'category' => 'returns',
            'created_by' => $user->id,
        ]);

        $file = UploadedFile::fake()->image('note.png');

        $this->post("/api/resources/tutorials/{$tutorial->id}/comments", [
            'body' => 'See www.example.com for more',
            'attachment' => $file,
        ])
            ->assertCreated()
            ->assertJsonPath('body', 'See www.example.com for more')
            ->assertJsonStructure(['attachment' => ['original_name']]);

        $this->assertDatabaseHas('tutorial_comments', [
            'tutorial_id' => $tutorial->id,
            'user_id' => $user->id,
            'body' => 'See www.example.com for more',
        ]);

        $commentId = (int) $this->getJson("/api/resources/tutorials/{$tutorial->id}")
            ->json('comments.0.id');

        $this->get("/api/resources/tutorials/{$tutorial->id}/comments/{$commentId}/attachment")
            ->assertOk();
    }
}
