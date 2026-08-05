<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\CustomBill;
use App\Models\CustomBillItem;
use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingBillsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithBillingView(): User
    {
        $user = User::factory()->create();
        $perm = Permission::query()->firstOrCreate(
            ['key' => 'billing.view'],
            ['label' => 'View billing', 'module' => 'billing']
        );
        $user->permissions()->sync([$perm->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeCustomBill(ClientAccount $account, array $overrides = []): CustomBill
    {
        return CustomBill::query()->create(array_merge([
            'bill_number' => 1001 + CustomBill::query()->count(),
            'name' => 'Test Bill',
            'status' => CustomBill::STATUS_OPEN,
            'client_account_id' => $account->id,
            'bill_date' => now()->toDateString(),
            'total_cents' => 1000,
        ], $overrides));
    }

    public function test_custom_bill_ref_uses_line_reference_over_project(): void
    {
        $this->actingWithBillingView();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Ref Co',
            'email' => 'ref@example.test',
        ]);
        $bill = $this->makeCustomBill($account);
        CustomBillItem::query()->create([
            'custom_bill_id' => $bill->id,
            'line_type' => InvoiceLineCategory::OTHER,
            'name' => 'Line',
            'quantity' => 1,
            'unit_price_cents' => 1000,
            'line_total_cents' => 1000,
            'sku' => 'REF-ABC',
            'sort_order' => 0,
        ]);
        Project::query()->create([
            'pid' => 'P-9999',
            'client_account_id' => $account->id,
            'name' => 'Linked Project',
            'status' => Project::STATUS_PENDING,
            'custom_bill_id' => $bill->id,
        ]);

        $row = collect($this->getJson('/api/billing/bills?bill_kind=custom')->assertOk()->json('data'))
            ->firstWhere('id', $bill->id);

        $this->assertNotNull($row);
        $this->assertSame('Reference #', $row['ref_label']);
        $this->assertSame('REF-ABC', $row['ref_value']);
    }

    public function test_custom_bill_ref_falls_back_to_project_when_no_sku(): void
    {
        $this->actingWithBillingView();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Project Ref Co',
            'email' => 'pref@example.test',
        ]);
        $bill = $this->makeCustomBill($account);
        Project::query()->create([
            'pid' => 'P-1017',
            'client_account_id' => $account->id,
            'name' => 'No Sku Project',
            'status' => Project::STATUS_PENDING,
            'custom_bill_id' => $bill->id,
        ]);

        $row = collect($this->getJson('/api/billing/bills?bill_kind=custom')->assertOk()->json('data'))
            ->firstWhere('id', $bill->id);

        $this->assertNotNull($row);
        $this->assertSame('Project #', $row['ref_label']);
        $this->assertSame('P-1017', $row['ref_value']);
    }

    public function test_search_finds_custom_bill_by_line_sku(): void
    {
        $this->actingWithBillingView();
        $account = ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Search Co',
            'email' => 'search@example.test',
        ]);
        $match = $this->makeCustomBill($account, ['name' => 'Match Bill']);
        CustomBillItem::query()->create([
            'custom_bill_id' => $match->id,
            'line_type' => InvoiceLineCategory::OTHER,
            'name' => 'Line',
            'quantity' => 1,
            'unit_price_cents' => 500,
            'line_total_cents' => 500,
            'sku' => 'UNIQUE-SKU-42',
            'sort_order' => 0,
        ]);
        $this->makeCustomBill($account, ['name' => 'Other Bill']);

        $ids = collect($this->getJson('/api/billing/bills?bill_kind=custom&search=UNIQUE-SKU-42')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$match->id], $ids);
    }
}
