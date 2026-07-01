<?php

namespace Tests\Feature;

use App\Jobs\RefreshOrderDashboardSectionJob;
use App\Models\OrderDashboardSection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomeDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
    }

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

    public function test_home_dashboard_returns_sections_and_totals(): void
    {
        $this->actingAsAdmin();

        $now = now();
        OrderDashboardSection::query()->insert([
            'section_key' => OrderDashboardSection::KEY_READY_TO_SHIP,
            'payload' => json_encode([
                'accounts' => [
                    [
                        'account_id' => 12,
                        'account_name' => 'Home Dash Co',
                        'account_status' => 'active',
                        'orders_count' => 3,
                    ],
                ],
                'truncated' => false,
            ]),
            'total_count' => 3,
            'status' => OrderDashboardSection::STATUS_IDLE,
            'refreshed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->getJson('/api/home-dashboard');

        $response->assertOk()
            ->assertJsonPath('totals.ready_to_ship', 3)
            ->assertJsonPath('sections.ready_to_ship.total_count', 3)
            ->assertJsonPath('sections.ready_to_ship.accounts.0.account_name', 'Home Dash Co');
    }

    public function test_home_dashboard_refresh_enqueues_job(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/home-dashboard/refresh', [
            'section' => OrderDashboardSection::KEY_READY_TO_SHIP,
        ]);

        $response->assertOk()
            ->assertJsonPath('refresh_enqueued', true)
            ->assertJsonPath('section', OrderDashboardSection::KEY_READY_TO_SHIP);

        Queue::assertPushed(RefreshOrderDashboardSectionJob::class, function ($job) {
            return $job->sectionKey === OrderDashboardSection::KEY_READY_TO_SHIP;
        });
    }

    public function test_home_dashboard_refresh_asn_runs_sync(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/home-dashboard/refresh', [
            'section' => OrderDashboardSection::KEY_ASN_PENDING,
        ]);

        $response->assertOk()
            ->assertJsonPath('refresh_synced', true)
            ->assertJsonPath('section', OrderDashboardSection::KEY_ASN_PENDING);

        Queue::assertNothingPushed();
    }
}
