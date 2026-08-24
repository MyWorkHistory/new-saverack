<?php

namespace Tests\Feature;

use App\Jobs\SendAdminBroadcastEmailJob;
use App\Mail\AdminBroadcastMailable;
use App\Models\AdminBroadcastEmail;
use App\Models\ClientAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminBroadcastEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminBroadcastEmailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: '.$e->getMessage());
        }
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

    private function actingAsStaff(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $staff = Role::query()->firstOrCreate(
            ['name' => 'staff'],
            ['label' => 'Staff', 'description' => 'Staff', 'is_system' => true]
        );
        $user->roles()->attach($staff->id);
        Sanctum::actingAs($user);

        return $user;
    }

    private function seedRecipients(): array
    {
        $active = ClientAccount::query()->create([
            'company_name' => 'Active Broadcast Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'active-broadcast@test.com',
        ]);
        $inactive = ClientAccount::query()->create([
            'company_name' => 'Inactive Broadcast Co',
            'status' => ClientAccount::STATUS_INACTIVE,
            'email' => 'inactive-broadcast@test.com',
        ]);

        $primary = User::factory()->create([
            'client_account_id' => $active->id,
            'is_account_primary' => true,
            'email' => 'broadcast-primary@test.com',
        ]);
        User::factory()->create([
            'client_account_id' => $active->id,
            'is_account_primary' => false,
            'email' => 'broadcast-secondary@test.com',
        ]);
        User::factory()->create([
            'client_account_id' => $inactive->id,
            'is_account_primary' => true,
            'email' => 'broadcast-inactive-primary@test.com',
        ]);

        return [$primary];
    }

    public function test_staff_cannot_list_broadcast_emails(): void
    {
        $this->actingAsStaff();

        $this->getJson('/api/admin/broadcast-emails')->assertForbidden();
    }

    public function test_admin_can_list_and_search_by_subject(): void
    {
        $this->actingAsAdmin();

        AdminBroadcastEmail::query()->create([
            'from_address' => 'info@saverack.com',
            'from_name' => 'Save Rack',
            'subject' => 'Warehouse Update',
            'body_html' => '<p>Hello</p>',
            'qty_sent' => 3,
            'recipient_count' => 3,
            'status' => AdminBroadcastEmail::STATUS_SENT,
            'sent_at' => now(),
        ]);
        AdminBroadcastEmail::query()->create([
            'from_address' => 'audi@saverack.com',
            'from_name' => 'Audi K | Save Rack',
            'subject' => 'Holiday Hours',
            'body_html' => '<p>Closed</p>',
            'qty_sent' => 1,
            'recipient_count' => 1,
            'status' => AdminBroadcastEmail::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $all = $this->getJson('/api/admin/broadcast-emails');
        $all->assertOk()->assertJsonPath('meta.total', 2);

        $filtered = $this->getJson('/api/admin/broadcast-emails?q=Holiday');
        $filtered->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject', 'Holiday Hours');
    }

    public function test_create_dispatches_job_and_job_sends_only_to_primaries(): void
    {
        $admin = $this->actingAsAdmin();
        [$primary] = $this->seedRecipients();

        Queue::fake();

        $response = $this->postJson('/api/admin/broadcast-emails', [
            'from_address' => 'info@saverack.com',
            'subject' => 'Client Update',
            'body_html' => '<p>Please <strong>read</strong> this.</p>',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email.subject', 'Client Update')
            ->assertJsonPath('recipient_count', 1);

        $broadcastId = (int) $response->json('email.id');
        $this->assertDatabaseHas('admin_broadcast_emails', [
            'id' => $broadcastId,
            'from_address' => 'info@saverack.com',
            'created_by_user_id' => $admin->id,
            'status' => AdminBroadcastEmail::STATUS_SENDING,
        ]);

        Queue::assertPushed(SendAdminBroadcastEmailJob::class, function ($job) use ($broadcastId) {
            return (int) $job->broadcastId === $broadcastId;
        });

        Mail::fake();
        (new SendAdminBroadcastEmailJob($broadcastId))->handle(app(AdminBroadcastEmailService::class));

        Mail::assertSent(AdminBroadcastMailable::class, function (AdminBroadcastMailable $mail) use ($primary) {
            return $mail->hasTo($primary->email)
                && $mail->subjectLine === 'Client Update'
                && $mail->fromAddress === 'info@saverack.com';
        });
        Mail::assertSent(AdminBroadcastMailable::class, 1);

        $broadcast = AdminBroadcastEmail::query()->findOrFail($broadcastId);
        $this->assertSame(AdminBroadcastEmail::STATUS_SENT, $broadcast->status);
        $this->assertSame(1, (int) $broadcast->qty_sent);
    }

    public function test_recipient_count_endpoint(): void
    {
        $this->actingAsAdmin();
        $this->seedRecipients();

        $this->getJson('/api/admin/broadcast-emails/recipient-count')
            ->assertOk()
            ->assertJsonPath('recipient_count', 1);
    }

    public function test_show_and_delete(): void
    {
        $this->actingAsAdmin();

        $broadcast = AdminBroadcastEmail::query()->create([
            'from_address' => 'info@saverack.com',
            'from_name' => 'Save Rack',
            'subject' => 'To Delete',
            'body_html' => '<p>Body</p>',
            'qty_sent' => 2,
            'recipient_count' => 2,
            'status' => AdminBroadcastEmail::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->getJson('/api/admin/broadcast-emails/'.$broadcast->id)
            ->assertOk()
            ->assertJsonPath('subject', 'To Delete')
            ->assertJsonPath('qty_sent', 2);

        $this->deleteJson('/api/admin/broadcast-emails/'.$broadcast->id)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('admin_broadcast_emails', ['id' => $broadcast->id]);
    }
}
