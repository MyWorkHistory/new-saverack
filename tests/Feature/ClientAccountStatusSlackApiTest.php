<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountStatusSlackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
            'billing.slack.bot_token' => 'xoxb-test-token',
            'app.url' => 'https://app.saverack.com',
            'crm.frontend_url' => 'https://app.saverack.com',
            'billing.slack.public_asset_base_url' => 'https://app.saverack.com',
        ]);
    }

    private function staffWithClientsUpdate(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'clients.update'],
            ['label' => 'Update client accounts', 'module' => 'clients']
        );
        $user = User::factory()->create(['client_account_id' => null, 'name' => 'Staff User']);
        $user->permissions()->attach($permission->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_status_patch_posts_to_in_house_slack_channel_via_webhook(): void
    {
        config(['billing.slack.bot_token' => null]);

        $this->staffWithClientsUpdate();

        Http::fake([
            'hooks.slack.com/*' => Http::response('ok', 200),
            'app.saverack.com/images/slack/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
        ]);

        $account = ClientAccount::create([
            'company_name' => 'Slack Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'slack-co@test.com',
            'in_house_slack' => 'slack-co',
            'shiphero_customer_account_id' => null,
        ]);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'status' => ClientAccount::STATUS_PAUSED,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', ClientAccount::STATUS_PAUSED);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'hooks.slack.com')) {
                return false;
            }

            $payload = $request->data();
            $attachment = $payload['attachments'][0] ?? [];
            $this->assertSame('#d32f2f', $attachment['color'] ?? null);
            $this->assertStringContainsString('/images/slack/shipping-status-paused-thumb.png', (string) ($attachment['thumb_url'] ?? ''));
            $this->assertStringContainsString('Slack Co is set to Paused.', (string) ($attachment['text'] ?? ''));
            $this->assertArrayNotHasKey('icon_url', $payload);

            return true;
        });
    }

    public function test_status_patch_with_bot_includes_attachment_and_icon_url(): void
    {
        $this->staffWithClientsUpdate();

        Http::fake([
            'app.saverack.com/images/slack/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
            'https://slack.com/api/conversations.join' => Http::response(['ok' => true], 200),
            'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => 'C1', 'ts' => '1'], 200),
        ]);

        $account = ClientAccount::create([
            'company_name' => 'Slack Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'slack-co@test.com',
            'in_house_slack' => 'slack-co',
        ]);

        $this->patchJson('/api/client-accounts/'.$account->id, [
            'status' => ClientAccount::STATUS_PAUSED,
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat.postMessage')) {
                return false;
            }

            $payload = $request->data();
            $attachment = $payload['attachments'][0] ?? [];
            $this->assertSame('Shipping Status Update', $payload['username'] ?? null);
            $this->assertStringContainsString('/images/slack/shipping-status-paused.png', (string) ($payload['icon_url'] ?? ''));
            $this->assertStringContainsString('/images/slack/shipping-status-paused-thumb.png', (string) ($attachment['thumb_url'] ?? ''));
            $this->assertStringContainsString('Slack Co is set to Paused.', (string) ($attachment['text'] ?? ''));

            return true;
        });
    }
}
