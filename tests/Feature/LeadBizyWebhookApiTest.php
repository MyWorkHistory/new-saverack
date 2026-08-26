<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PricingFeeTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadBizyWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-bizy-webhook-secret';

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: '.$e->getMessage());
        }
        config(['services.leads.bizy_webhook_secret' => self::SECRET]);
    }

    public function test_rejects_missing_secret(): void
    {
        $this->postJson('/api/leads/webhooks/bizy', [
            'company_name' => 'Acme Co',
            'email' => 'hello@acme.test',
        ])->assertUnauthorized();
    }

    public function test_rejects_when_secret_not_configured(): void
    {
        config(['services.leads.bizy_webhook_secret' => '']);

        $this->withHeaders(['X-Leads-Webhook-Secret' => self::SECRET])
            ->postJson('/api/leads/webhooks/bizy', [
                'company_name' => 'Acme Co',
                'email' => 'hello@acme.test',
            ])
            ->assertStatus(500);
    }

    public function test_creates_bizy_lead_from_webhook(): void
    {
        PricingFeeTemplate::query()->create([
            'name' => 'First Pick',
            'description' => 'Pick fee',
            'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
            'amount' => 1.25,
            'sort_order' => 1,
        ]);

        $response = $this->withHeaders(['X-Leads-Webhook-Secret' => self::SECRET])
            ->postJson('/api/leads/webhooks/bizy', [
                'Company' => 'Blue Ridge Exotics',
                'Website' => 'blueridgeexotics.com',
                'Email' => 'sales@blueridgeexotics.com',
                'Response' => 'Interested in fulfillment',
                'Status' => 'New',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'created');
        $response->assertJsonPath('referral', Lead::REFERRAL_BIZY);
        $this->assertNotEmpty($response->json('lead_id'));

        $lead = Lead::query()->first();
        $this->assertNotNull($lead);
        $this->assertSame(Lead::REFERRAL_BIZY, $lead->referral);
        $this->assertSame(Lead::STATUS_OPEN, $lead->status);
        $this->assertSame('Blue Ridge Exotics', $lead->company_name);
        $this->assertSame('sales@blueridgeexotics.com', $lead->email);
        $this->assertSame('blueridgeexotics.com', $lead->website);
        $this->assertSame("Interested in fulfillment\n\nSheet status: New", $lead->comment);
    }

    public function test_duplicate_email_is_idempotent(): void
    {
        $existing = Lead::query()->create([
            'status' => Lead::STATUS_OPEN,
            'referral' => Lead::REFERRAL_BIZY,
            'company_name' => 'Existing Co',
            'email' => 'dup@example.com',
            'follow_up_days' => 1,
            'follow_up_at' => now()->addDay()->toDateString(),
        ]);

        $response = $this->withHeaders(['X-Leads-Webhook-Secret' => self::SECRET])
            ->postJson('/api/leads/webhooks/bizy', [
                'company_name' => 'New Name Should Not Win',
                'email' => 'DUP@example.com',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'duplicate');
        $response->assertJsonPath('lead_id', $existing->id);
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_accepts_bearer_secret(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.self::SECRET])
            ->postJson('/api/leads/webhooks/bizy', [
                'company_name' => 'Bearer Co',
                'email' => 'bearer@example.com',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'created');
    }

    public function test_validation_requires_company_and_email(): void
    {
        $this->withHeaders(['X-Leads-Webhook-Secret' => self::SECRET])
            ->postJson('/api/leads/webhooks/bizy', [
                'website' => 'example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name', 'email'], 'errors');
    }

    public function test_head_probe_ok(): void
    {
        $this->call('HEAD', '/api/leads/webhooks/bizy')->assertOk();
    }
}
