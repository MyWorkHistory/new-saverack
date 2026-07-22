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

    public function test_account_fees_payload_includes_postage_rows(): void
    {
        $this->actingStaffWithClientsUpdate();

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Postage Co',
            'email' => 'postage@example.test',
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'pricing_template_id' => null,
            'fee_group' => PricingFeeTemplate::CATEGORY_POSTAGE,
            'line_code' => 'postage_usps',
            'label' => 'USPS',
            'description' => null,
            'icon_path' => null,
            'amount' => 0.25,
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
        $this->assertContains(PricingFeeTemplate::CATEGORY_POSTAGE, $categories);

        $clientFacing = app(ClientAccountService::class)->feesPayloadForApi(
            $account->fresh(['feeItems']),
            false,
            true
        );
        $clientCategories = array_map(
            static fn ($item) => (string) ($item['category'] ?? ''),
            $clientFacing['items'] ?? []
        );
        $this->assertContains(ClientAccountFee::GROUP_FULFILLMENT, $clientCategories);
        $this->assertNotContains(PricingFeeTemplate::CATEGORY_POSTAGE, $clientCategories);

        $response = $this->getJson('/api/client-accounts/'.$account->id);
        $response->assertOk();
        $apiCategories = array_map(
            static fn ($item) => (string) ($item['category'] ?? ''),
            $response->json('fees.items') ?? []
        );
        $this->assertContains(PricingFeeTemplate::CATEGORY_POSTAGE, $apiCategories);
    }

    public function test_guest_cannot_download_account_pricing_pdf(): void
    {
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'PDF Guest Co',
            'email' => 'pdf-guest@example.test',
        ]);

        $this->get('/api/client-accounts/'.$account->id.'/fees/pricing.pdf')
            ->assertUnauthorized();
    }

    public function test_staff_can_download_account_pricing_pdf_when_pending(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach([$this->clientsViewPermission()->id]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Acme Widgets',
            'email' => 'pdf-pending@example.test',
            'fulfillment_pricing_status' => ClientAccount::FULFILLMENT_PRICING_STATUS_PENDING,
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'pricing_template_id' => null,
            'fee_group' => ClientAccountFee::GROUP_FULFILLMENT,
            'line_code' => ClientAccountFee::LINE_FIRST_PICK,
            'label' => 'First Pick',
            'description' => 'Per order first pick',
            'icon_path' => null,
            'amount' => 1.5,
            'currency' => 'USD',
            'sort_order' => 0,
        ]);

        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'pricing_template_id' => null,
            'fee_group' => PricingFeeTemplate::CATEGORY_POSTAGE,
            'line_code' => 'postage_usps',
            'label' => 'USPS Postage UniqueLabel',
            'description' => 'Should not appear in PDF',
            'icon_path' => null,
            'amount' => 0.25,
            'currency' => 'USD',
            'sort_order' => 99,
        ]);

        $response = $this->get('/api/client-accounts/'.$account->id.'/fees/pricing.pdf');

        $response->assertOk();
        $this->assertStringContainsString(
            'application/pdf',
            (string) $response->headers->get('content-type')
        );
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('Acme-Widgets-Pricing.pdf', $disposition);
        $content = $response->getContent();
        $this->assertGreaterThan(100, strlen($content));
        $this->assertStringNotContainsString('USPS Postage UniqueLabel', $content);
    }

    public function test_staff_account_fees_include_effective_cost_and_patch_override(): void
    {
        $this->actingStaffWithClientsUpdate();

        $template = PricingFeeTemplate::query()->create([
            'name' => 'Inbound Fee',
            'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
            'amount' => 3,
            'cost' => 1.25,
            'sort_order' => 0,
        ]);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Cost Co',
            'email' => 'cost-co@example.test',
        ]);

        app(ClientAccountService::class)->ensureDefaultFeeItems($account);

        $fee = ClientAccountFee::query()
            ->where('client_account_id', $account->id)
            ->where('pricing_template_id', $template->id)
            ->firstOrFail();

        $show = $this->getJson('/api/client-accounts/'.$account->id);
        $show->assertOk();
        $item = collect($show->json('fees.items'))->firstWhere('id', $fee->id);
        $this->assertNotNull($item);
        $this->assertSame(1.25, $item['cost']);
        $this->assertSame(1.25, $item['default_cost']);
        $this->assertFalse($item['cost_is_override']);

        $patch = $this->patchJson('/api/client-accounts/'.$account->id.'/fees/'.$fee->id, [
            'amount' => 3,
            'cost' => 2.5,
        ]);
        $patch->assertOk();
        $patchedItem = collect($patch->json('fees.items'))->firstWhere('id', $fee->id);
        $this->assertSame(2.5, $patchedItem['cost']);
        $this->assertTrue($patchedItem['cost_is_override']);

        $template->update(['cost' => 9]);
        $showAfterDefaultChange = $this->getJson('/api/client-accounts/'.$account->id);
        $itemAfter = collect($showAfterDefaultChange->json('fees.items'))->firstWhere('id', $fee->id);
        $this->assertSame(2.5, $itemAfter['cost']);
        $this->assertSame(9.0, $itemAfter['default_cost']);

        $clear = $this->patchJson('/api/client-accounts/'.$account->id.'/fees/'.$fee->id, [
            'amount' => 3,
            'cost' => null,
        ]);
        $clear->assertOk();
        $clearedItem = collect($clear->json('fees.items'))->firstWhere('id', $fee->id);
        $this->assertSame(9.0, $clearedItem['cost']);
        $this->assertFalse($clearedItem['cost_is_override']);
    }
}
