<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use App\Support\ClientAccountBillingPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountBillingPreferencesApiTest extends TestCase
{
    use RefreshDatabase;

    private function inventoryViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'inventory.view'],
            ['label' => 'View inventory', 'module' => 'inventory']
        );
    }

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

    private function billingViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.view'],
            ['label' => 'View billing', 'module' => 'billing']
        );
    }

    /**
     * @return array{0: ClientAccount, 1: User}
     */
    private function portalUser(): array
    {
        $account = ClientAccount::create([
            'company_name' => 'Packaging Portal Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'billing@packaging-portal.test',
        ]);
        $user = User::factory()->create([
            'client_account_id' => $account->id,
            'email' => 'portal@packaging-portal.test',
        ]);
        $user->permissions()->attach($this->inventoryViewPermission()->id);
        Sanctum::actingAs($user);

        return [$account, $user];
    }

    public function test_staff_patch_sets_postage_option(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($this->clientsUpdatePermission()->id);
        Sanctum::actingAs($user);

        $account = ClientAccount::create([
            'company_name' => 'Postage Staff Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'postage-staff@test.com',
        ]);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'postage_option' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_FEDEX,
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'postage_option',
            ClientAccountBillingPreferences::POSTAGE_CUSTOMER_FEDEX
        );
        $response->assertJsonPath(
            'postage_option_label',
            'Customer Provides Fedex Account'
        );

        $account->refresh();
        $this->assertSame(
            ClientAccountBillingPreferences::POSTAGE_CUSTOMER_FEDEX,
            $account->postage_option
        );
    }

    public function test_portal_patch_sets_packaging_option(): void
    {
        [$account] = $this->portalUser();

        $response = $this->patchJson('/api/portal/profile/packaging', [
            'packaging_option' => ClientAccountBillingPreferences::PACKAGING_CUSTOMER_ALL,
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'packaging_option',
            ClientAccountBillingPreferences::PACKAGING_CUSTOMER_ALL
        );
        $response->assertJsonPath(
            'packaging_option_label',
            'Customer Provides All Packaging Materials'
        );

        $account->refresh();
        $this->assertSame(
            ClientAccountBillingPreferences::PACKAGING_CUSTOMER_ALL,
            $account->packaging_option
        );
    }

    public function test_invoice_show_includes_client_account_preference_labels(): void
    {
        $staff = User::factory()->create(['client_account_id' => null]);
        $staff->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($staff);

        $account = ClientAccount::create([
            'company_name' => 'Invoice Prefs Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'invoice-prefs@test.com',
            'postage_option' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_UPS,
            'packaging_option' => ClientAccountBillingPreferences::PACKAGING_CUSTOMER_SOME,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-BILLING-PREFS-001',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'balance_due_cents' => 0,
            'amount_paid_cents' => 0,
        ]);

        $response = $this->getJson('/api/invoices/'.$invoice->id);

        $response->assertOk();
        $response->assertJsonPath(
            'client_account_postage_option',
            ClientAccountBillingPreferences::POSTAGE_CUSTOMER_UPS
        );
        $response->assertJsonPath(
            'client_account_postage_option_label',
            'Customer Provides UPS Account'
        );
        $response->assertJsonPath(
            'client_account_packaging_option',
            ClientAccountBillingPreferences::PACKAGING_CUSTOMER_SOME
        );
        $response->assertJsonPath(
            'client_account_packaging_option_label',
            'Customer Provides Some Packaging Materials'
        );
    }

    public function test_staff_patch_sets_payment_terms_days(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($this->clientsUpdatePermission()->id);
        Sanctum::actingAs($user);

        $account = ClientAccount::create([
            'company_name' => 'Payment Terms Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'payment-terms@test.com',
        ]);

        $response = $this->patchJson('/api/client-accounts/'.$account->id, [
            'payment_terms_days' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonPath('payment_terms_days', 5);

        $account->refresh();
        $this->assertSame(5, $account->payment_terms_days);
    }

    public function test_new_account_defaults_payment_terms_days_to_one(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach([
            $this->clientsViewPermission()->id,
            $this->clientsUpdatePermission()->id,
        ]);
        Sanctum::actingAs($user);

        $account = ClientAccount::create([
            'company_name' => 'Default Terms Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'default-terms@test.com',
        ]);

        $response = $this->getJson('/api/client-accounts/'.$account->id);

        $response->assertOk();
        $response->assertJsonPath('payment_terms_days', 1);
    }
}
