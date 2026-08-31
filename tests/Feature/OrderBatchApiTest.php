<?php

namespace Tests\Feature;

use App\Models\OrderBatch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderBatchApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'client_account_id' => null,
            'status' => 'active',
            'name' => 'Admin User',
        ]);
        $admin = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'description' => 'Full access', 'is_system' => true]
        );
        $user->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_portal_user_forbidden(): void
    {
        $user = User::factory()->create(['client_account_id' => 1, 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/order-batches')->assertForbidden();
    }

    public function test_can_create_multiple_batches_and_skip_duplicates(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/order-batches', [
            'lines' => "Batch 7763953\n7763954\n7763924",
        ])->assertCreated()
            ->assertJsonPath('created', 3)
            ->assertJsonPath('skipped', 0);

        $this->postJson('/api/order-batches', [
            'lines' => "7763953\n9990001",
        ])->assertCreated()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1);

        $this->assertSame(4, OrderBatch::query()->count());
        $row = OrderBatch::query()->where('batch_number', '7763953')->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderBatch::STATUS_PENDING, $row->status);
        $this->assertSame($admin->id, (int) $row->created_by_user_id);
        $this->assertNull($row->completed_by_user_id);
    }

    public function test_can_create_from_shiphero_links_and_list_exposes_url(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/order-batches', [
            'lines' => "https://shipping.shiphero.com/bulk-ship/batch/?batchId=7768504\n".
                "https://shipping.shiphero.com/bulk-ship/batch/?batchId=7768505",
        ])->assertCreated()
            ->assertJsonPath('created', 2);

        $this->assertDatabaseHas('order_batches', ['batch_number' => '7768504']);
        $this->assertDatabaseHas('order_batches', ['batch_number' => '7768505']);

        $list = $this->getJson('/api/order-batches')->assertOk();
        $first = collect($list->json('data'))->firstWhere('batch_number', '7768504');
        $this->assertNotNull($first);
        $this->assertSame(
            'https://shipping.shiphero.com/bulk-ship/batch/?batchId=7768504',
            $first['batch_url']
        );
    }

    public function test_status_completed_registers_user(): void
    {
        $admin = $this->actingAsAdmin();
        $batch = OrderBatch::query()->create([
            'batch_number' => '111',
            'status' => OrderBatch::STATUS_PENDING,
            'created_by_user_id' => $admin->id,
        ]);

        $other = User::factory()->create([
            'client_account_id' => null,
            'status' => 'active',
            'name' => 'Completer',
        ]);
        $adminRole = Role::query()->where('name', 'admin')->first();
        $other->roles()->attach($adminRole->id);
        Sanctum::actingAs($other);

        $this->patchJson('/api/order-batches/'.$batch->id, [
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('batch.status', 'completed')
            ->assertJsonPath('batch.user_name', 'Completer')
            ->assertJsonPath('batch.completed_by_user_id', $other->id);

        $batch->refresh();
        $this->assertSame($other->id, (int) $batch->completed_by_user_id);
        $this->assertNotNull($batch->completed_at);
    }

    public function test_bulk_complete_updates_found_and_reports_missing(): void
    {
        $admin = $this->actingAsAdmin();
        OrderBatch::query()->create([
            'batch_number' => '7763953',
            'status' => OrderBatch::STATUS_PENDING,
            'created_by_user_id' => $admin->id,
        ]);
        OrderBatch::query()->create([
            'batch_number' => '7763954',
            'status' => OrderBatch::STATUS_PENDING,
            'created_by_user_id' => $admin->id,
        ]);

        $this->postJson('/api/order-batches/complete', [
            'lines' => "7763953\n7763954\n9999999",
        ])->assertOk()
            ->assertJsonPath('updated', 2)
            ->assertJsonPath('missing_count', 1)
            ->assertJsonPath('missing.0', '9999999');

        $this->assertSame(2, OrderBatch::query()->where('status', 'completed')->count());
        $this->assertSame(
            $admin->id,
            (int) OrderBatch::query()->where('batch_number', '7763953')->value('completed_by_user_id')
        );
    }

    public function test_index_filters_by_batch_number_and_user(): void
    {
        $admin = $this->actingAsAdmin();
        OrderBatch::query()->create([
            'batch_number' => '111',
            'status' => OrderBatch::STATUS_PENDING,
            'created_by_user_id' => $admin->id,
        ]);
        OrderBatch::query()->create([
            'batch_number' => '222',
            'status' => OrderBatch::STATUS_PENDING,
            'created_by_user_id' => $admin->id,
        ]);

        $this->getJson('/api/order-batches?q=111')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.batch_number', '111');

        $this->getJson('/api/order-batches?user_id='.$admin->id)->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_index_filters_by_status(): void
    {
        $admin = $this->actingAsAdmin();
        OrderBatch::query()->create([
            'batch_number' => '100',
            'status' => OrderBatch::STATUS_PENDING,
            'created_by_user_id' => $admin->id,
        ]);
        OrderBatch::query()->create([
            'batch_number' => '200',
            'status' => OrderBatch::STATUS_COMPLETED,
            'created_by_user_id' => $admin->id,
            'completed_by_user_id' => $admin->id,
            'completed_at' => now(),
        ]);

        $this->getJson('/api/order-batches?status=pending')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.batch_number', '100');

        $this->getJson('/api/order-batches?status=completed')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.batch_number', '200');
    }

    public function test_index_sorts_by_batch_number(): void
    {
        $admin = $this->actingAsAdmin();
        OrderBatch::query()->create([
            'batch_number' => '300',
            'status' => OrderBatch::STATUS_PENDING,
            'created_by_user_id' => $admin->id,
        ]);
        OrderBatch::query()->create([
            'batch_number' => '100',
            'status' => OrderBatch::STATUS_PENDING,
            'created_by_user_id' => $admin->id,
        ]);

        $asc = $this->getJson('/api/order-batches?sort_by=batch_number&sort_dir=asc')->assertOk();
        $numbers = collect($asc->json('data'))->pluck('batch_number')->values()->all();
        $this->assertSame(['100', '300'], $numbers);
    }
}
