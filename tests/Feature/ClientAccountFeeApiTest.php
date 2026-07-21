<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\Permission;
use App\Models\PricingFeeTemplate;
use App\Models\User;
use App\Services\ClientAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountFeeApiTest extends TestCase
{
    use RefreshDatabase;

    private function clientsUpdatePermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'clients.update'],
            ['label' => 'Update client accounts', 'module' => 'clients']
        );
    }

    private function clientsViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'clients.view'],
            ['label' => 'View client accounts', 'module' => 'clients']
        );
    }

    private function actingStaffWithClientsUpdate(): User
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach([
            $this->clientsViewPermission()->id,
            $this->clientsUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_account_show_includes_fees_items(): void
    {
        $this->actingStaffWithClientsUpdate();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Fees Items Co',
            'email' => 'fees-items@example.test',
        ]);

        PricingFeeTemplate::query()->create([
            'name' => 'First Pick',
            'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
            'amount' => 1.5,
            'sort_order' => 0,
        ]);

        app(ClientAccountService::class)->ensureDefaultFeeItems($account);

        $response = $this->getJson('/api/client-accounts/'.$account->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'fees' => [
                'items' => [
                    ['id', 'name', 'category', 'category_label', 'amount'],
                ],
            ],
        ]);
        $this->assertNotEmpty($response->json('fees.items'));
    }

    public function test_patch_updates_account_fee_amount_only_for_account(): void
    {
        $this->actingStaffWithClientsUpdate();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Patch Fee Co',
            'email' => 'patch-fee@example.test',
        ]);

        $template = PricingFeeTemplate::query()->create([
            'name' => 'Inbound Handling',
            'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
            'amount' => 2,
            'sort_order' => 0,
        ]);

        app(ClientAccountService::class)->ensureDefaultFeeItems($account);

        $fee = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('pricing_template_id', $template->id)
            ->firstOrFail();

        $response = $this->patchJson(
            '/api/client-accounts/'.$account->id.'/fees/'.$fee->id,
            ['amount' => 7.75]
        );

        $response->assertOk();

        $items = collect($response->json('fees.items'));
        $this->assertEquals(7.75, $items->firstWhere('id', $fee->id)['amount'] ?? null);

        $fee->refresh();
        $this->assertEquals('7.7500', (string) $fee->amount);

        $template->refresh();
        $this->assertEquals('2.0000', (string) $template->amount);
    }

    public function test_patch_fee_on_wrong_account_returns_not_found(): void
    {
        $this->actingStaffWithClientsUpdate();

        $accountA = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Account A',
            'email' => 'a@example.test',
        ]);
        $accountB = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Account B',
            'email' => 'b@example.test',
        ]);

        PricingFeeTemplate::query()->create([
            'name' => 'Kitting',
            'category' => PricingFeeTemplate::CATEGORY_CUSTOM_WORK,
            'amount' => 1,
            'sort_order' => 0,
        ]);

        app(ClientAccountService::class)->ensureDefaultFeeItems($accountA);
        app(ClientAccountService::class)->ensureDefaultFeeItems($accountB);

        $feeOnA = ClientAccountFee::query()
            ->where('client_account_id', $accountA->id)
            ->firstOrFail();

        $this->patchJson(
            '/api/client-accounts/'.$accountB->id.'/fees/'.$feeOnA->id,
            ['amount' => 5]
        )->assertNotFound();
    }

    public function test_guest_cannot_patch_account_fee(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Guest Fee Co',
            'email' => 'guest-fee@example.test',
        ]);

        $fee = ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => ClientAccountFee::GROUP_FULFILLMENT,
            'line_code' => ClientAccountFee::LINE_FIRST_PICK,
            'amount' => '1.0000',
            'currency' => 'USD',
            'sort_order' => 0,
        ]);

        $this->patchJson('/api/client-accounts/'.$account->id.'/fees/'.$fee->id, [
            'amount' => 2,
        ])->assertUnauthorized();
    }

    public function test_account_fees_payload_excludes_postage_rows(): void
    {
        $this->actingStaffWithClientsUpdate();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'No Postage Co',
            'email' => 'no-postage@example.test',
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'pricing_template_id' => null,
            'fee_group' => PricingFeeTemplate::CATEGORY_POSTAGE,
            'line_code' => 'postage_orphan',
            'label' => 'Should Hide',
            'description' => null,
            'icon_path' => null,
            'amount' => 12.5,
            'currency' => 'USD',
            'sort_order' => 99,
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'pricing_template_id' => null,
            'fee_group' => ClientAccountFee::GROUP_FULFILLMENT,
            'line_code' => ClientAccountFee::LINE_FIRST_PICK,
            'label' => 'First Pick',
            'description' => null,
            'icon_path' => null,
            'amount' => 1.5,
            'currency' => 'USD',
            'sort_order' => 0,
        ]);

        $payload = app(ClientAccountService::class)->feesPayloadForApi($account->fresh(['feeItems']));
        $categories = array_map(
            static fn ($item) => (string) ($item['category'] ?? ''),
            $payload['items'] ?? []
        );

        $this->assertContains(ClientAccountFee::GROUP_FULFILLMENT, $categories);
        $this->assertNotContains(PricingFeeTemplate::CATEGORY_POSTAGE, $categories);

        $response = $this->getJson('/api/client-accounts/'.$account->id);
        $response->assertOk();
        $apiCategories = array_map(
            static fn ($item) => (string) ($item['category'] ?? ''),
            $response->json('fees.items') ?? []
        );
        $this->assertNotContains(PricingFeeTemplate::CATEGORY_POSTAGE, $apiCategories);
    }
}
