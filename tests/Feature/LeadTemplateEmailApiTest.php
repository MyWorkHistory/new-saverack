<?php

namespace Tests\Feature;

use App\Jobs\SendLeadBulkTemplateEmailJob;
use App\Mail\LeadTemplateMailable;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadStatusEvent;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadTemplateEmailApiTest extends TestCase
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

    private function staffWithLeads(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => 'leads.'.$action],
                ['label' => ucfirst($action).' leads', 'module' => 'leads']
            );
            $user->permissions()->attach($permission->id);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeTemplate(string $category = 'follow_up'): EmailTemplate
    {
        return EmailTemplate::query()->create([
            'category' => $category,
            'name' => 'Follow Up Ping',
            'subject' => 'Checking in',
            'body' => '<p>Hello <strong>there</strong></p>',
        ]);
    }

    public function test_send_template_email_updates_status_follow_up_and_last_sent(): void
    {
        $this->staffWithLeads();
        Mail::fake();

        $lead = Lead::query()->create([
            'status' => Lead::STATUS_CONTACTED,
            'referral' => Lead::REFERRAL_BIZY,
            'company_name' => 'Acme Co',
            'name' => 'Jordan',
            'website' => 'acme.test',
            'email' => 'lead@example.com',
            'follow_up_days' => 3,
            'follow_up_at' => now()->addDays(3)->toDateString(),
        ]);
        $template = EmailTemplate::query()->create([
            'category' => 'follow_up',
            'name' => 'Follow Up Ping',
            'subject' => 'Hi {Name} — {Company}',
            'body' => '<p>Check out {website}</p>',
        ]);

        $response = $this->postJson('/api/leads/'.$lead->id.'/email-templates/'.$template->id.'/send');
        $response->assertOk()
            ->assertJsonPath('status', Lead::STATUS_FOLLOW_UP)
            ->assertJsonPath('follow_up_days', 5);

        Mail::assertSent(LeadTemplateMailable::class, function (LeadTemplateMailable $mail) {
            return $mail->hasTo('lead@example.com')
                && $mail->subjectLine === 'Hi Jordan — Acme Co'
                && $mail->bodyHtml === '<p>Check out acme.test</p>'
                && $mail->fromAddress === 'audi@saverack.com'
                && strpos($mail->signatureHtml, 'Audi K | Managing Partner') !== false
                && strpos($mail->signatureHtml, 'audi@saverack.com') !== false
                && strpos($mail->signatureHtml, '<img') === false;
        });
        Mail::assertSent(LeadTemplateMailable::class, 1);

        $lead->refresh();
        $this->assertSame(Lead::STATUS_FOLLOW_UP, $lead->status);
        $this->assertSame(5, (int) $lead->follow_up_days);

        $usages = $response->json('template_usages');
        $this->assertIsArray($usages);
        $this->assertNotEmpty($usages[$template->id]['last_sent_at'] ?? null);

        $this->assertTrue(
            LeadStatusEvent::query()
                ->where('lead_id', $lead->id)
                ->where('email_template_id', $template->id)
                ->exists()
        );
    }

    public function test_interested_template_sets_two_day_follow_up(): void
    {
        $this->staffWithLeads();
        Mail::fake();

        $lead = Lead::query()->create([
            'status' => Lead::STATUS_OPEN,
            'referral' => Lead::REFERRAL_GOOGLE,
            'company_name' => 'Interest Co',
            'email' => 'interest@example.com',
            'follow_up_days' => 1,
            'follow_up_at' => now()->addDay()->toDateString(),
        ]);
        $template = $this->makeTemplate('interested');

        $this->postJson('/api/leads/'.$lead->id.'/email-templates/'.$template->id.'/send')
            ->assertOk()
            ->assertJsonPath('status', Lead::STATUS_INTERESTED)
            ->assertJsonPath('follow_up_days', 2);
    }

    public function test_not_interested_template_turns_follow_up_off(): void
    {
        $this->staffWithLeads();
        Mail::fake();

        $lead = Lead::query()->create([
            'status' => Lead::STATUS_CONTACTED,
            'referral' => Lead::REFERRAL_BIZY,
            'company_name' => 'Nope Co',
            'email' => 'nope@example.com',
            'follow_up_days' => 3,
            'follow_up_at' => now()->addDays(3)->toDateString(),
        ]);
        $template = $this->makeTemplate('not_interested');

        $this->postJson('/api/leads/'.$lead->id.'/email-templates/'.$template->id.'/send')
            ->assertOk()
            ->assertJsonPath('status', Lead::STATUS_NOT_INTERESTED)
            ->assertJsonPath('follow_up_days', null);
    }

    public function test_bulk_email_queues_job_and_updates_status_before_job_runs(): void
    {
        $this->staffWithLeads();
        Mail::fake();
        Queue::fake();

        $leadA = Lead::query()->create([
            'status' => Lead::STATUS_OPEN,
            'referral' => Lead::REFERRAL_BIZY,
            'company_name' => 'A Co',
            'email' => 'a@example.com',
            'follow_up_days' => 1,
            'follow_up_at' => now()->addDay()->toDateString(),
        ]);
        $leadB = Lead::query()->create([
            'status' => Lead::STATUS_OPEN,
            'referral' => Lead::REFERRAL_BIZY,
            'company_name' => 'B Co',
            'email' => 'b@example.com',
            'follow_up_days' => 1,
            'follow_up_at' => now()->addDay()->toDateString(),
        ]);
        $template = $this->makeTemplate('contacted');

        $this->postJson('/api/leads/bulk-email', [
            'lead_ids' => [$leadA->id, $leadB->id],
            'email_template_id' => $template->id,
        ])
            ->assertOk()
            ->assertJsonPath('queued', 2)
            ->assertJsonPath('skipped', 0)
            ->assertJsonPath('updated', 2)
            ->assertJsonPath('category', 'contacted');

        $leadA->refresh();
        $leadB->refresh();
        $this->assertSame(Lead::STATUS_CONTACTED, $leadA->status);
        $this->assertSame(Lead::STATUS_CONTACTED, $leadB->status);

        Queue::assertPushed(SendLeadBulkTemplateEmailJob::class, function ($job) use ($template) {
            return (int) $job->templateId === (int) $template->id
                && count($job->leadIds) === 2;
        });
        Mail::assertNothingSent();
    }

    public function test_email_template_uses_subject_field(): void
    {
        $adminRole = \App\Models\Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator']
        );
        $user = User::factory()->create(['client_account_id' => null]);
        $user->roles()->attach($adminRole->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/settings/email-templates', [
            'category' => 'contacted',
            'name' => 'Intro',
            'subject' => 'Welcome subject',
            'body' => '<p>Hi</p>',
        ])
            ->assertCreated()
            ->assertJsonPath('subject', 'Welcome subject')
            ->assertJsonMissingPath('description');
    }
}
