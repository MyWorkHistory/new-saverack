<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Supply;
use App\Models\SupplyOrder;
use App\Models\SupplyOrderLine;
use App\Models\User;
use App\Services\SuppliesOrderedSlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SuppliesApiTest extends TestCase
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

    private function permission(string $key): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            ['label' => $key, 'module' => explode('.', $key)[0]]
        );
    }

    private function staffWith(array $keys): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $ids = [];
        foreach ($keys as $key) {
            $ids[] = $this->permission($key)->id;
        }
        $user->permissions()->sync($ids);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeSupply(array $overrides = []): Supply
    {
        return Supply::query()->create(array_merge([
            'type' => Supply::TYPE_BOX,
            'name' => '9x9x4',
            'link' => 'https://example.com/box',
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_guest_cannot_list_settings_supplies(): void
    {
        $this->getJson('/api/settings/supplies')->assertUnauthorized();
    }

    public function test_non_admin_cannot_create_settings_supply(): void
    {
        $this->staffWith(['resources_supplies.view']);

        $this->postJson('/api/settings/supplies', [
            'type' => Supply::TYPE_BOX,
            'name' => '9x9x4',
        ])->assertForbidden();
    }

    public function test_staff_with_update_can_create_shared_catalog_supply(): void
    {
        $this->staffWith(['resources_supplies.view', 'resources_supplies.update']);

        $this->postJson('/api/settings/supplies', [
            'type' => Supply::TYPE_BOX,
            'name' => 'Shared Box',
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Shared Box');
    }

    public function test_crm_staff_without_explicit_supplies_perms_can_view_shared_catalog(): void
    {
        $this->makeSupply(['name' => 'Audi Box']);
        $staff = User::factory()->create(['client_account_id' => null, 'status' => 'active']);
        Sanctum::actingAs($staff);

        $this->getJson('/api/admin/supplies')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Audi Box']);
    }

    public function test_two_staff_users_see_identical_shared_catalog(): void
    {
        $this->makeSupply(['name' => 'Box A']);
        $this->makeSupply(['name' => 'Box B']);
        $this->makeSupply(['name' => 'Box C']);

        $this->staffWith(['resources_supplies.view']);
        $staffCatalog = $this->getJson('/api/admin/supplies');
        $staffCatalog->assertOk();
        $staffNames = collect($staffCatalog->json('data'))->pluck('name')->sort()->values()->all();

        $this->actingAsAdmin();
        $adminCatalog = $this->getJson('/api/admin/supplies');
        $adminCatalog->assertOk();
        $adminNames = collect($adminCatalog->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame($adminNames, $staffNames);
        $this->assertCount(3, $staffNames);
    }

    public function test_all_staff_see_shared_catalog_and_team_order_history(): void
    {
        $rikki = $this->staffWith(['resources_supplies.view', 'resources_supplies.create']);
        $this->makeSupply(['name' => 'Rikki Box']);

        $nelson = User::factory()->create(['client_account_id' => null, 'name' => 'Nelson']);
        $nelson->permissions()->sync([
            $this->permission('resources_supplies.view')->id,
            $this->permission('resources_supplies.create')->id,
        ]);
        Sanctum::actingAs($nelson);

        $catalog = $this->getJson('/api/admin/supplies');
        $catalog->assertOk();
        $names = collect($catalog->json('data'))->pluck('name')->all();
        $this->assertContains('Rikki Box', $names);

        $box = Supply::query()->where('name', 'Rikki Box')->first();
        $this->assertNotNull($box);

        $slack = Mockery::mock(\App\Services\SuppliesOrderedSlackService::class);
        $slack->shouldReceive('send')->once()->andReturn(null);
        $this->app->instance(\App\Services\SuppliesOrderedSlackService::class, $slack);

        $this->actingAsAdmin();
        $this->postJson('/api/admin/supply-orders', [
            'lines' => [
                ['supply_id' => $box->id, 'quantity' => 3],
            ],
        ])->assertCreated();

        Sanctum::actingAs($rikki);

        $history = $this->getJson('/api/admin/supply-orders');
        $history->assertOk();
        $this->assertGreaterThanOrEqual(1, count($history->json('data')));
        $this->assertSame('Rikki Box', $history->json('data.0.name'));
    }

    public function test_admin_can_crud_settings_supplies(): void
    {
        $this->actingAsAdmin();

        $create = $this->postJson('/api/settings/supplies', [
            'type' => Supply::TYPE_POLY_MAILER,
            'name' => '6x9',
            'link' => 'https://example.com/poly',
        ]);
        $create->assertCreated()
            ->assertJsonPath('name', '6x9')
            ->assertJsonPath('type', Supply::TYPE_POLY_MAILER)
            ->assertJsonPath('type_label', 'Poly Mailer');

        $id = (int) $create->json('id');

        $this->getJson('/api/settings/supplies')
            ->assertOk()
            ->assertJsonPath('data.0.name', '6x9');

        $this->patchJson("/api/settings/supplies/{$id}", [
            'name' => '6x9 Updated',
        ])
            ->assertOk()
            ->assertJsonPath('name', '6x9 Updated');

        $this->deleteJson("/api/settings/supplies/{$id}")->assertOk();
        $this->assertDatabaseMissing('supplies', ['id' => $id]);
    }

    public function test_staff_with_view_can_list_admin_supplies_but_not_settings_create(): void
    {
        $this->staffWith(['resources_supplies.view']);
        $this->makeSupply();

        $this->getJson('/api/admin/supplies')
            ->assertOk()
            ->assertJsonPath('data.0.name', '9x9x4');

        $this->postJson('/api/settings/supplies', [
            'type' => Supply::TYPE_BOX,
            'name' => 'Nope',
        ])->assertForbidden();
    }

    public function test_staff_with_create_cannot_submit_order(): void
    {
        $this->staffWith(['resources_supplies.view', 'resources_supplies.create']);
        $supply = $this->makeSupply();

        $this->postJson('/api/admin/supply-orders', [
            'lines' => [
                ['supply_id' => $supply->id, 'quantity' => 10],
            ],
        ])->assertForbidden();
    }

    public function test_admin_can_submit_supply_order_without_explicit_supplies_permissions(): void
    {
        $admin = $this->actingAsAdmin();
        $supply = $this->makeSupply(['name' => 'Admin Box']);

        $slack = Mockery::mock(SuppliesOrderedSlackService::class);
        $slack->shouldReceive('send')
            ->once()
            ->andReturn(['method' => 'bot', 'channel' => '#supplies', 'ts' => '1.0']);
        $this->app->instance(SuppliesOrderedSlackService::class, $slack);

        $this->postJson('/api/admin/supply-orders', [
            'lines' => [
                ['supply_id' => $supply->id, 'quantity' => 3],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('lines.0.quantity', 3);

        $this->assertDatabaseHas('supply_orders', [
            'user_id' => $admin->id,
        ]);
    }

    public function test_submit_order_creates_history_and_calls_slack(): void
    {
        $user = $this->actingAsAdmin();
        $box = $this->makeSupply(['name' => '9x9x4', 'type' => Supply::TYPE_BOX]);
        $poly = $this->makeSupply([
            'name' => '6x9',
            'type' => Supply::TYPE_POLY_MAILER,
            'link' => null,
        ]);

        $slack = Mockery::mock(SuppliesOrderedSlackService::class);
        $slack->shouldReceive('send')
            ->once()
            ->with(Mockery::type(SupplyOrder::class))
            ->andReturn(['method' => 'bot', 'channel' => '#supplies', 'ts' => '1.0']);
        $this->app->instance(SuppliesOrderedSlackService::class, $slack);

        $response = $this->postJson('/api/admin/supply-orders', [
            'lines' => [
                ['supply_id' => $box->id, 'quantity' => 100],
                ['supply_id' => $poly->id, 'quantity' => 2000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('lines.0.quantity', 100)
            ->assertJsonPath('slack_warning', null);

        $this->assertDatabaseHas('supply_orders', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('supply_order_lines', [
            'supply_id' => $box->id,
            'name' => '9x9x4',
            'type' => Supply::TYPE_BOX,
            'quantity' => 100,
        ]);
        $this->assertDatabaseHas('supply_order_lines', [
            'supply_id' => $poly->id,
            'name' => '6x9',
            'type' => Supply::TYPE_POLY_MAILER,
            'quantity' => 2000,
        ]);

        $history = $this->getJson('/api/admin/supply-orders');
        $history->assertOk();
        $this->assertGreaterThanOrEqual(2, count($history->json('data')));
    }

    public function test_submit_order_survives_slack_failure(): void
    {
        $this->actingAsAdmin();
        $supply = $this->makeSupply();

        $slack = Mockery::mock(SuppliesOrderedSlackService::class);
        $slack->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('slack down'));
        $this->app->instance(SuppliesOrderedSlackService::class, $slack);

        $this->postJson('/api/admin/supply-orders', [
            'lines' => [
                ['supply_id' => $supply->id, 'quantity' => 5],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('slack_warning', 'Order saved, but Slack notification failed.');

        $this->assertSame(1, SupplyOrder::query()->count());
        $this->assertSame(1, SupplyOrderLine::query()->count());
    }

    public function test_staff_without_create_cannot_submit_order(): void
    {
        $this->staffWith(['resources_supplies.view']);
        $supply = $this->makeSupply();

        $this->postJson('/api/admin/supply-orders', [
            'lines' => [
                ['supply_id' => $supply->id, 'quantity' => 10],
            ],
        ])->assertForbidden();
    }

    public function test_admin_can_submit_order_with_note(): void
    {
        $this->actingAsAdmin();
        $supply = $this->makeSupply();

        $slack = Mockery::mock(SuppliesOrderedSlackService::class);
        $slack->shouldReceive('send')->once()->andReturn(null);
        $this->app->instance(SuppliesOrderedSlackService::class, $slack);

        $this->postJson('/api/admin/supply-orders', [
            'lines' => [
                ['supply_id' => $supply->id, 'quantity' => 2],
            ],
            'note' => 'Need these ASAP',
        ])
            ->assertCreated()
            ->assertJsonPath('note', 'Need these ASAP');

        $this->assertDatabaseHas('supply_orders', [
            'note' => 'Need these ASAP',
        ]);
    }

    public function test_admin_can_update_history_line_quantity(): void
    {
        $user = $this->actingAsAdmin();
        $order = SupplyOrder::query()->create([
            'user_id' => $user->id,
            'submitted_at' => now(),
            'note' => 'Rush',
        ]);
        $line = SupplyOrderLine::query()->create([
            'supply_order_id' => $order->id,
            'supply_id' => null,
            'name' => '9x9x4',
            'type' => Supply::TYPE_BOX,
            'link' => null,
            'quantity' => 10,
        ]);

        $this->patchJson("/api/admin/supply-order-lines/{$line->id}", [
            'quantity' => 25,
        ])
            ->assertOk()
            ->assertJsonPath('quantity', 25)
            ->assertJsonPath('order_note', 'Rush');

        $this->assertDatabaseHas('supply_order_lines', [
            'id' => $line->id,
            'quantity' => 25,
        ]);
    }

    public function test_staff_cannot_update_history_line_quantity(): void
    {
        $this->staffWith(['resources_supplies.view', 'resources_supplies.create']);
        $order = SupplyOrder::query()->create([
            'user_id' => User::factory()->create(['client_account_id' => null])->id,
            'submitted_at' => now(),
        ]);
        $line = SupplyOrderLine::query()->create([
            'supply_order_id' => $order->id,
            'supply_id' => null,
            'name' => '9x9x4',
            'type' => Supply::TYPE_BOX,
            'link' => null,
            'quantity' => 10,
        ]);

        $this->patchJson("/api/admin/supply-order-lines/{$line->id}", [
            'quantity' => 25,
        ])->assertForbidden();
    }

    public function test_history_search_filters_by_name_and_type(): void
    {
        $user = $this->actingAsAdmin();
        $order = SupplyOrder::query()->create([
            'user_id' => $user->id,
            'submitted_at' => now(),
        ]);
        SupplyOrderLine::query()->create([
            'supply_order_id' => $order->id,
            'supply_id' => null,
            'name' => '9x9x4',
            'type' => Supply::TYPE_BOX,
            'link' => null,
            'quantity' => 10,
        ]);
        SupplyOrderLine::query()->create([
            'supply_order_id' => $order->id,
            'supply_id' => null,
            'name' => '6x9',
            'type' => Supply::TYPE_POLY_MAILER,
            'link' => null,
            'quantity' => 20,
        ]);

        $this->getJson('/api/admin/supply-orders?q=Poly')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '6x9');
    }
}
