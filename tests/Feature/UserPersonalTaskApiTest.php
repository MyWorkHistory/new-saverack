<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPersonalTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPersonalTaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_lists_only_own_tasks_with_counts(): void
    {
        $userA = User::factory()->create(['client_account_id' => null]);
        $userB = User::factory()->create(['client_account_id' => null]);

        UserPersonalTask::query()->create([
            'user_id' => $userA->id,
            'title' => 'My task',
            'is_completed' => false,
        ]);
        UserPersonalTask::query()->create([
            'user_id' => $userA->id,
            'title' => 'Done task',
            'is_completed' => true,
            'completed_at' => now()->subHour(),
        ]);
        UserPersonalTask::query()->create([
            'user_id' => $userB->id,
            'title' => 'Other user task',
            'is_completed' => false,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/me/personal-tasks');

        $response->assertOk()
            ->assertJsonPath('incomplete_count', 1)
            ->assertJsonPath('total_count', 2)
            ->assertJsonPath('max_tasks', 10)
            ->assertJsonCount(2, 'tasks');

        $titles = collect($response->json('tasks'))->pluck('title')->all();
        $this->assertSame(['My task', 'Done task'], $titles);
    }

    public function test_user_cannot_update_or_delete_another_users_task(): void
    {
        $userA = User::factory()->create(['client_account_id' => null]);
        $userB = User::factory()->create(['client_account_id' => null]);

        $task = UserPersonalTask::query()->create([
            'user_id' => $userB->id,
            'title' => 'Private task',
            'is_completed' => false,
        ]);

        Sanctum::actingAs($userA);

        $this->patchJson("/api/me/personal-tasks/{$task->id}", ['is_completed' => true])
            ->assertNotFound();

        $this->deleteJson("/api/me/personal-tasks/{$task->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('user_personal_tasks', ['id' => $task->id]);
    }

    public function test_create_rejects_eleventh_task(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        for ($i = 1; $i <= 10; $i++) {
            UserPersonalTask::query()->create([
                'user_id' => $user->id,
                'title' => "Task {$i}",
                'is_completed' => false,
            ]);
        }

        $response = $this->postJson('/api/me/personal-tasks', ['title' => 'Task 11']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_toggle_complete_sets_and_clears_completed_at(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        $task = UserPersonalTask::query()->create([
            'user_id' => $user->id,
            'title' => 'Toggle me',
            'is_completed' => false,
        ]);

        $complete = $this->patchJson("/api/me/personal-tasks/{$task->id}", ['is_completed' => true]);
        $complete->assertOk()
            ->assertJsonPath('is_completed', true);
        $this->assertNotNull($task->fresh()->completed_at);

        $incomplete = $this->patchJson("/api/me/personal-tasks/{$task->id}", ['is_completed' => false]);
        $incomplete->assertOk()
            ->assertJsonPath('is_completed', false);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_delete_frees_slot_for_new_task(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        for ($i = 1; $i <= 10; $i++) {
            UserPersonalTask::query()->create([
                'user_id' => $user->id,
                'title' => "Task {$i}",
                'is_completed' => false,
            ]);
        }

        $toDelete = UserPersonalTask::query()->where('user_id', $user->id)->first();
        $this->deleteJson("/api/me/personal-tasks/{$toDelete->id}")->assertOk();

        $create = $this->postJson('/api/me/personal-tasks', ['title' => 'Replacement task']);
        $create->assertCreated()
            ->assertJsonPath('title', 'Replacement task');
    }
}
