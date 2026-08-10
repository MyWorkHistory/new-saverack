<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Permission;
use App\Models\PricingFeeTemplate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithLeads(array $actions = ['view', 'create', 'update', 'delete']): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        foreach ($actions as $action) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => 'leads.'.$action],
                ['label' => ucfirst($action).' leads', 'module' => 'leads']
            );
            $user->permissions()->attach($permission->id);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function administratorUser(): User
    {
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator']
        );
        $user = User::factory()->create(['client_account_id' => null]);
        $user->roles()->attach($adminRole->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_create_defaults_to_open_and_one_day_follow_up(): void
    {
        $this->staffWithLeads();

        PricingFeeTemplate::query()->create([
            'name' => 'First Pick',
            'description' => 'Pick fee',
            'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
            'amount' => 1.25,
            'sort_order' => 1,
        ]);

        $response = $this->postJson('/api/leads', [
            'company_name' => 'Blue Ridge Exotics',
            'email' => 'sales@blueridgeexotics.com',
            'website' => 'blueridgeexotics.com',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', Lead::STATUS_OPEN);
        $response->assertJsonPath('follow_up_days', 1);
        $response->assertJsonPath('company_name', 'Blue Ridge Exotics');
        $this->assertNotEmpty($response->json('fees.items'));

        $lead = Lead::query()->first();
        $this->assertNotNull($lead);
        $this->assertSame(Lead::STATUS_OPEN, $lead->status);
        $this->assertSame(1, (int) $lead->follow_up_days);
        $this->assertTrue($lead->feeItems()->exists());
    }

    public function test_quick_add_parses_pasted_text(): void
    {
        $this->staffWithLeads();

        $text = <<<'TEXT'
Company: Blue Ridge Exotics
Website: blueridgeexotics.com
Email: sales@blueridgeexotics.com

Email Thread:
Can you send over some details on what this would look like for us?
TEXT;

        $response = $this->postJson('/api/leads/quick-add', ['text' => $text]);

        $response->assertCreated();
        $response->assertJsonPath('company_name', 'Blue Ridge Exotics');
        $response->assertJsonPath('email', 'sales@blueridgeexotics.com');
        $response->assertJsonPath('website', 'blueridgeexotics.com');
        $response->assertJsonPath(
            'comment',
            'Can you send over some details on what this would look like for us?'
        );
        $response->assertJsonPath('status', Lead::STATUS_OPEN);
        $response->assertJsonPath('referral', Lead::REFERRAL_BIZY);
    }

    public function test_quick_add_google_format_sets_referral(): void
    {
        $this->staffWithLeads();

        $text = <<<'TEXT'
Full Name	:	Cherrie, Deas
Company Name	:	TALAA LLC
Email	:	Info@rheeboutique.com
Phone Number	:	8136679100
Store Website URL	:	rheeboutique.com
Tell us about any special requirements	:	Hello.

Subject: Partnership
TEXT;

        $response = $this->postJson('/api/leads/quick-add', [
            'text' => $text,
            'referral' => Lead::REFERRAL_GOOGLE,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('referral', Lead::REFERRAL_GOOGLE);
        $response->assertJsonPath('referral_label', 'Google');
        $response->assertJsonPath('name', 'Cherrie Deas');
        $response->assertJsonPath('company_name', 'TALAA LLC');
        $response->assertJsonPath('email', 'Info@rheeboutique.com');
        $response->assertJsonPath('website', 'rheeboutique.com');
        $this->assertStringContainsString('Phone: 8136679100', (string) $response->json('comment'));
    }

    public function test_meta_directory_stats_and_status_filter(): void
    {
        $this->staffWithLeads(['view', 'update']);

        Lead::query()->create([
            'status' => Lead::STATUS_OPEN,
            'referral' => Lead::REFERRAL_BIZY,
            'company_name' => 'Open Co',
            'email' => 'open@test.com',
            'follow_up_days' => 1,
            'follow_up_at' => now()->addDay()->toDateString(),
        ]);
        Lead::query()->create([
            'status' => Lead::STATUS_CONTACTED,
            'referral' => Lead::REFERRAL_GOOGLE,
            'company_name' => 'Contacted Co',
            'email' => 'contacted@test.com',
            'follow_up_days' => 3,
            'follow_up_at' => now()->addDays(3)->toDateString(),
        ]);
        Lead::query()->create([
            'status' => Lead::STATUS_NOT_INTERESTED,
            'referral' => Lead::REFERRAL_BIZY,
            'company_name' => 'Closed Co',
            'email' => 'closed@test.com',
            'follow_up_days' => 7,
            'follow_up_at' => now()->addDays(7)->toDateString(),
        ]);

        $meta = $this->getJson('/api/leads/meta');
        $meta->assertOk();
        $meta->assertJsonPath('directory_stats.open', 1);
        $meta->assertJsonPath('directory_stats.contacted', 1);
        $this->assertArrayNotHasKey('not_interested', $meta->json('directory_stats'));
        $meta->assertJsonPath('referrals.0', Lead::REFERRAL_BIZY);

        $list = $this->getJson('/api/leads?status=open');
        $list->assertOk();
        $list->assertJsonPath('total', 1);
        $list->assertJsonPath('data.0.company_name', 'Open Co');

        $byReferral = $this->getJson('/api/leads?referral=google');
        $byReferral->assertOk();
        $byReferral->assertJsonPath('total', 1);
        $byReferral->assertJsonPath('data.0.referral', Lead::REFERRAL_GOOGLE);
    }

    public function test_can_update_status_and_follow_up_days(): void
    {
        $this->staffWithLeads(['view', 'update']);

        $lead = Lead::query()->create([
            'status' => Lead::STATUS_OPEN,
            'company_name' => 'Update Co',
            'email' => 'update@test.com',
            'follow_up_days' => 1,
            'follow_up_at' => now()->addDay()->toDateString(),
        ]);

        $response = $this->patchJson('/api/leads/'.$lead->id, [
            'status' => Lead::STATUS_FOLLOW_UP,
            'follow_up_days' => 15,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', Lead::STATUS_FOLLOW_UP);
        $response->assertJsonPath('follow_up_days', 15);

        $lead->refresh();
        $this->assertSame(Lead::STATUS_FOLLOW_UP, $lead->status);
        $this->assertSame(15, (int) $lead->follow_up_days);
    }

    public function test_can_set_follow_up_off(): void
    {
        $this->staffWithLeads(['view', 'update']);

        $lead = Lead::query()->create([
            'status' => Lead::STATUS_OPEN,
            'company_name' => 'Off Co',
            'email' => 'off@test.com',
            'follow_up_days' => 3,
            'follow_up_at' => now()->addDays(3)->toDateString(),
        ]);

        $response = $this->patchJson('/api/leads/'.$lead->id, [
            'follow_up_days' => null,
        ]);

        $response->assertOk();
        $response->assertJsonPath('follow_up_days', null);
        $response->assertJsonPath('follow_up_at', null);
        $response->assertJsonPath('follow_up_label', '—');

        $lead->refresh();
        $this->assertNull($lead->follow_up_days);
        $this->assertNull($lead->follow_up_at);
    }

    public function test_create_seeds_open_status_event(): void
    {
        $this->staffWithLeads();

        $response = $this->postJson('/api/leads', [
            'company_name' => 'Event Co',
            'email' => 'event@test.com',
        ]);

        $response->assertCreated();
        $events = $response->json('status_events');
        $this->assertIsArray($events);
        $this->assertNotEmpty($events);
        $this->assertSame(Lead::STATUS_OPEN, $events[0]['status']);
        $this->assertSame('Lead created', $events[0]['note']);
    }

    public function test_can_update_created_at(): void
    {
        $this->staffWithLeads(['view', 'update']);

        $lead = Lead::query()->create([
            'status' => Lead::STATUS_OPEN,
            'company_name' => 'Date Co',
            'email' => 'date@test.com',
            'follow_up_days' => 1,
            'follow_up_at' => now()->addDay()->toDateString(),
        ]);

        $response = $this->patchJson('/api/leads/'.$lead->id, [
            'created_at' => '2024-01-15',
        ]);

        $response->assertOk();
        $lead->refresh();
        $this->assertSame('2024-01-15', $lead->created_at->toDateString());
    }

    public function test_view_permission_required(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/leads')->assertForbidden();
    }

    public function test_admin_can_access_without_explicit_permission(): void
    {
        $this->administratorUser();

        $this->getJson('/api/leads/meta')->assertOk();
    }
}
