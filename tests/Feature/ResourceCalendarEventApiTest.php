<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ResourceCalendarEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResourceCalendarEventApiTest extends TestCase
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

    private function staffWithCreate(): User
    {
        $user = $this->staffWithView();
        $user->permissions()->attach($this->resourcesCreatePermission()->id);

        return $user;
    }

    public function test_guest_cannot_access_calendar_events(): void
    {
        $this->getJson('/api/resources/calendar-events?start=2026-07-01&end=2026-07-31')
            ->assertUnauthorized();
    }

    public function test_user_without_resources_view_cannot_list_events(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/resources/calendar-events?start=2026-07-01&end=2026-07-31')
            ->assertForbidden();
    }

    public function test_user_with_resources_view_can_read_meta(): void
    {
        Sanctum::actingAs($this->staffWithView());

        $this->getJson('/api/resources/calendar-events/meta')
            ->assertOk()
            ->assertJsonStructure(['categories']);
    }

    public function test_shared_event_visible_to_all_users_with_view(): void
    {
        $creator = $this->staffWithCreate();
        $viewer = $this->staffWithView();

        $event = ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $creator->id,
            'title' => 'Team Meeting',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-10',
            'is_personal' => false,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/resources/calendar-events?start=2026-07-01&end=2026-07-31')
            ->assertOk()
            ->assertJsonFragment(['id' => $event->id, 'title' => 'Team Meeting']);
    }

    public function test_personal_event_visible_only_to_creator(): void
    {
        $creator = $this->staffWithCreate();
        $other = $this->staffWithView();

        ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $creator->id,
            'title' => 'Private Day',
            'category' => ResourceCalendarEvent::CATEGORY_OUT_OF_OFFICE,
            'start_date' => '2026-07-12',
            'end_date' => '2026-07-12',
            'is_personal' => true,
        ]);

        Sanctum::actingAs($creator);
        $this->getJson('/api/resources/calendar-events?start=2026-07-01&end=2026-07-31')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Private Day']);

        Sanctum::actingAs($other);
        $this->getJson('/api/resources/calendar-events?start=2026-07-01&end=2026-07-31')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Private Day']);
    }

    public function test_creator_can_crud_own_personal_event(): void
    {
        $user = $this->staffWithCreate();
        $user->permissions()->attach($this->resourcesUpdatePermission()->id);
        $user->permissions()->attach($this->resourcesDeletePermission()->id);
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/resources/calendar-events', [
            'title' => 'My OOO',
            'category' => ResourceCalendarEvent::CATEGORY_OUT_OF_OFFICE,
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
            'is_personal' => true,
        ])->assertCreated();

        $eventId = (int) $create->json('id');

        $this->patchJson("/api/resources/calendar-events/{$eventId}", [
            'title' => 'Updated OOO',
        ])->assertOk()
            ->assertJsonFragment(['title' => 'Updated OOO']);

        $this->deleteJson("/api/resources/calendar-events/{$eventId}")
            ->assertOk();
    }

    public function test_other_user_cannot_update_or_delete_personal_event(): void
    {
        $creator = $this->staffWithCreate();
        $other = $this->staffWithView();
        $other->permissions()->attach($this->resourcesUpdatePermission()->id);
        $other->permissions()->attach($this->resourcesDeletePermission()->id);

        $event = ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $creator->id,
            'title' => 'Creator Personal',
            'category' => ResourceCalendarEvent::CATEGORY_HOLIDAY,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-20',
            'is_personal' => true,
        ]);

        Sanctum::actingAs($other);

        $this->patchJson("/api/resources/calendar-events/{$event->id}", [
            'title' => 'Hacked',
        ])->assertForbidden();

        $this->deleteJson("/api/resources/calendar-events/{$event->id}")
            ->assertForbidden();
    }

    public function test_user_with_update_cannot_edit_shared_event_created_by_someone_else(): void
    {
        $creator = $this->staffWithCreate();
        $editor = $this->staffWithView();
        $editor->permissions()->attach($this->resourcesUpdatePermission()->id);

        $event = ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $creator->id,
            'title' => 'Shared Project',
            'category' => ResourceCalendarEvent::CATEGORY_PROJECT,
            'start_date' => '2026-07-22',
            'end_date' => '2026-07-25',
            'is_personal' => false,
        ]);

        Sanctum::actingAs($editor);

        $this->patchJson("/api/resources/calendar-events/{$event->id}", [
            'title' => 'Shared Project Updated',
        ])->assertForbidden();
    }

    public function test_user_with_delete_cannot_delete_shared_event_created_by_someone_else(): void
    {
        $creator = $this->staffWithCreate();
        $deleter = $this->staffWithView();
        $deleter->permissions()->attach($this->resourcesDeletePermission()->id);

        $event = ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $creator->id,
            'title' => 'Shared Receiving',
            'category' => ResourceCalendarEvent::CATEGORY_RECEIVING,
            'start_date' => '2026-07-28',
            'end_date' => '2026-07-28',
            'is_personal' => false,
        ]);

        Sanctum::actingAs($deleter);

        $this->deleteJson("/api/resources/calendar-events/{$event->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('resource_calendar_events', ['id' => $event->id]);
    }

    public function test_administrator_can_edit_and_delete_shared_event_created_by_someone_else(): void
    {
        $creator = $this->staffWithCreate();
        $adminRole = \App\Models\Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole->id);
        $admin->permissions()->attach($this->resourcesViewPermission()->id);

        $event = ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $creator->id,
            'title' => 'Admin Managed Event',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => '2026-07-30',
            'end_date' => '2026-07-30',
            'is_personal' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/resources/calendar-events/{$event->id}", [
            'title' => 'Admin Updated Event',
        ])->assertOk()
            ->assertJsonFragment(['title' => 'Admin Updated Event']);

        $this->deleteJson("/api/resources/calendar-events/{$event->id}")
            ->assertOk();

        $this->assertDatabaseMissing('resource_calendar_events', ['id' => $event->id]);
    }

    public function test_validation_requires_end_date_on_or_after_start_date(): void
    {
        Sanctum::actingAs($this->staffWithCreate());

        $this->postJson('/api/resources/calendar-events', [
            'title' => 'Bad Range',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-09',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_upcoming_endpoint_returns_future_events(): void
    {
        $user = $this->staffWithCreate();
        Sanctum::actingAs($user);

        ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $user->id,
            'title' => 'Past Event',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(9)->toDateString(),
            'is_personal' => false,
        ]);

        ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $user->id,
            'title' => 'Future Event',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'is_personal' => false,
        ]);

        $this->getJson('/api/resources/calendar-events?upcoming=1&limit=4')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Future Event'])
            ->assertJsonMissing(['title' => 'Past Event']);
    }

    public function test_monthly_repeat_creates_materialized_occurrences(): void
    {
        Sanctum::actingAs($this->staffWithCreate());

        $response = $this->postJson('/api/resources/calendar-events', [
            'title' => 'Monthly Sync',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => '2026-01-15',
            'end_date' => '2026-01-16',
            'repeat' => ResourceCalendarEvent::REPEAT_MONTHLY,
            'is_personal' => false,
        ])->assertCreated();

        $this->assertSame(24, (int) $response->json('created_count'));
        $this->assertDatabaseCount('resource_calendar_events', 24);
        $this->assertNotNull($response->json('series_id'));
        $this->assertDatabaseHas('resource_calendar_events', [
            'title' => 'Monthly Sync',
            'start_date' => '2026-02-15',
            'end_date' => '2026-02-16',
        ]);
    }

    public function test_yearly_repeat_creates_materialized_occurrences(): void
    {
        Sanctum::actingAs($this->staffWithCreate());

        $response = $this->postJson('/api/resources/calendar-events', [
            'title' => 'Yearly Review',
            'category' => ResourceCalendarEvent::CATEGORY_HOLIDAY,
            'start_date' => '2026-07-04',
            'end_date' => '2026-07-04',
            'repeat' => ResourceCalendarEvent::REPEAT_YEARLY,
        ])->assertCreated();

        $this->assertSame(10, (int) $response->json('created_count'));
        $this->assertDatabaseCount('resource_calendar_events', 10);
        $this->assertDatabaseHas('resource_calendar_events', [
            'title' => 'Yearly Review',
            'start_date' => '2027-07-04',
        ]);
    }

    public function test_update_can_change_repeat_on_edit(): void
    {
        $user = $this->staffWithCreate();
        Sanctum::actingAs($user);

        $event = ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $user->id,
            'title' => 'One-off Meeting',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-01',
            'repeat' => ResourceCalendarEvent::REPEAT_NONE,
            'is_personal' => false,
        ]);

        $this->patchJson('/api/resources/calendar-events/'.$event->id, [
            'repeat' => ResourceCalendarEvent::REPEAT_MONTHLY,
        ])
            ->assertOk()
            ->assertJsonPath('repeat', ResourceCalendarEvent::REPEAT_MONTHLY);

        $this->assertDatabaseCount('resource_calendar_events', 24);
        $this->assertNotNull($event->fresh()->series_id);
    }

    public function test_list_endpoint_paginates_events(): void
    {
        $user = $this->staffWithCreate();
        Sanctum::actingAs($user);

        ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $user->id,
            'title' => 'Listable Event',
            'category' => ResourceCalendarEvent::CATEGORY_PROJECT,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-01',
            'is_personal' => false,
        ]);

        $this->getJson('/api/resources/calendar-events/list?per_page=25')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Listable Event'])
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
    }

    public function test_bulk_delete_removes_selected_events(): void
    {
        $user = $this->staffWithCreate();
        $user->permissions()->attach($this->resourcesDeletePermission()->id);
        Sanctum::actingAs($user);

        $a = ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $user->id,
            'title' => 'Bulk A',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'is_personal' => false,
        ]);
        $b = ResourceCalendarEvent::query()->create([
            'created_by_user_id' => $user->id,
            'title' => 'Bulk B',
            'category' => ResourceCalendarEvent::CATEGORY_MEETING,
            'start_date' => '2026-09-02',
            'end_date' => '2026-09-02',
            'is_personal' => false,
        ]);

        $this->deleteJson('/api/resources/calendar-events/bulk', [
            'ids' => [$a->id, $b->id],
        ])->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseMissing('resource_calendar_events', ['id' => $a->id]);
        $this->assertDatabaseMissing('resource_calendar_events', ['id' => $b->id]);
    }
}
