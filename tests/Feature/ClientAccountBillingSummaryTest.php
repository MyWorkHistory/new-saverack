<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAccountBillingSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function billingViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'billing.view'],
            ['label' => 'View billing', 'module' => 'billing']
        );
    }

    private function clientsViewPermission(): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => 'clients.view'],
            ['label' => 'View client accounts', 'module' => 'clients']
        );
    }

    public function test_staff_can_load_billing_summary_for_client_account(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->sync([
            $this->billingViewPermission()->id,
            $this->clientsViewPermission()->id,
        ]);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Scoped Billing Co',
            'email' => 'scoped-billing@test.com',
        ]);

        Invoice::query()->create([
            'invoice_number' => 'INV-SCOPED-001',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 5000,
            'tax_cents' => 0,
            'total_cents' => 5000,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 5000,
        ]);

        $this->getJson('/api/billing/summary?client_account_id='.$account->id)
            ->assertOk()
            ->assertJsonPath('draft_invoice_count', 1);
    }

    public function test_staff_without_clients_view_cannot_load_other_account_summary(): void
    {
        $user = User::factory()->create(['client_account_id' => null]);
        $user->permissions()->attach($this->billingViewPermission()->id);
        Sanctum::actingAs($user);

        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Forbidden Billing Co',
            'email' => 'forbidden-billing@test.com',
        ]);

        $this->getJson('/api/billing/summary?client_account_id='.$account->id)
            ->assertForbidden();
    }
}
