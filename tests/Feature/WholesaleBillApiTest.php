<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PricingFeeTemplate;
use App\Models\Role;
use App\Models\User;
use App\Models\WholesaleBill;
use App\Models\WholesaleOrder;
use App\Models\WholesaleOrderFeeLine;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WholesaleBillApiTest extends TestCase
{
    use RefreshDatabase;

    private function account(): ClientAccount
    {
        return ClientAccount::query()->create([
            'status' => ClientAccount::STATUS_ACTIVE,
            'company_name' => 'Wholesale Bill Co',
            'email' => 'wholesale-bill@example.test',
        ]);
    }

    private function order(ClientAccount $account): WholesaleOrder
    {
        return WholesaleOrder::query()->create([
            'client_account_id' => $account->id,
            'order_number' => 'WO-BILL-100',
            'order_type' => WholesaleOrder::TYPE_B2B,
            'status' => WholesaleOrder::STATUS_COMPLETED,
            'items_count' => 1,
        ]);
    }

    private function actingAsAdministrator(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $user = User::factory()->create(['client_account_id' => null]);
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_fee_lines_support_wholesale_packaging_and_custom_sources(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        $packagingFee = ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_PACKAGING,
            'line_code' => 'packaging_large_carton',
            'label' => 'Large Carton',
            'amount' => '4.7500',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);
        $this->actingAsAdministrator();

        $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/fee-lines', [
            'line_type' => WholesaleOrderFeeLine::TYPE_MASTER_CARTON,
            'source' => WholesaleOrderFeeLine::SOURCE_WHOLESALE,
            'name' => 'Master Carton',
            'quantity' => 2,
            'unit_price' => 3.5,
        ])->assertOk();

        $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/fee-lines', [
            'line_type' => 'fee_'.$packagingFee->id,
            'source' => WholesaleOrderFeeLine::SOURCE_PACKAGING,
            'client_account_fee_id' => $packagingFee->id,
            'name' => 'Large Carton',
            'quantity' => 3,
            'unit_price' => 4.75,
        ])->assertOk();

        $custom = $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/fee-lines', [
            'line_type' => 'custom_new',
            'source' => WholesaleOrderFeeLine::SOURCE_CUSTOM,
            'name' => 'Special Handling',
            'quantity' => 1,
            'unit_price' => 10,
        ])->assertOk();

        $this->assertCount(3, $custom->json('fee_lines'));
        $this->assertDatabaseHas('wholesale_order_fee_lines', [
            'wholesale_order_id' => $order->id,
            'source' => WholesaleOrderFeeLine::SOURCE_PACKAGING,
            'name' => 'Large Carton',
        ]);
        $this->assertDatabaseHas('wholesale_order_fee_lines', [
            'wholesale_order_id' => $order->id,
            'source' => WholesaleOrderFeeLine::SOURCE_CUSTOM,
            'name' => 'Special Handling',
        ]);
    }

    public function test_create_bill_and_add_to_invoice_as_single_breakdown_line(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        WholesaleOrderFeeLine::query()->create([
            'wholesale_order_id' => $order->id,
            'line_type' => WholesaleOrderFeeLine::TYPE_MASTER_CARTON,
            'source' => WholesaleOrderFeeLine::SOURCE_WHOLESALE,
            'name' => 'Master Carton',
            'quantity' => 2,
            'unit_price_cents' => 350,
        ]);
        WholesaleOrderFeeLine::query()->create([
            'wholesale_order_id' => $order->id,
            'line_type' => 'custom_test',
            'source' => WholesaleOrderFeeLine::SOURCE_CUSTOM,
            'name' => 'Special Handling',
            'quantity' => 1,
            'unit_price_cents' => 1000,
        ]);
        $this->actingAsAdministrator();

        $created = $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/create-bill')
            ->assertCreated()
            ->assertJsonPath('total_cents', 1700)
            ->assertJsonPath('display_name', 'Wholesale Order #WO-BILL-100');
        $billId = (int) $created->json('id');
        $this->assertSame($billId, (int) $order->fresh()->wholesale_bill_id);

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-WHOLESALE-1',
            'client_account_id' => $account->id,
            'status' => Invoice::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'balance_due_cents' => 0,
        ]);

        $this->postJson('/api/billing/wholesale-bills/'.$billId.'/add-to-invoice', [
            'invoice_id' => $invoice->id,
        ])->assertOk()->assertJsonPath('status', WholesaleBill::STATUS_INVOICED);

        $line = InvoiceItem::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('Wholesale Order #WO-BILL-100', $line->display_name);
        $this->assertSame('WO-BILL-100', $line->metadata['order_number']);
        $this->assertCount(2, $line->metadata['breakdown']);
        $this->assertSame('Master Carton', $line->metadata['breakdown'][0]['name']);
        $this->assertSame('Special Handling', $line->metadata['breakdown'][1]['name']);

        $detail = app(InvoiceService::class)->toDetailArray($invoice->fresh());
        $wholesaleRow = collect($detail['presentation']['rows'] ?? [])->firstWhere('groupKey', 'wholesale');
        $this->assertNotNull($wholesaleRow);
        $this->assertSame('WO-BILL-100', $wholesaleRow['details'][0]['order_number']);
        $this->assertSame('Master Carton', $wholesaleRow['details'][0]['name']);
        $this->assertSame('Special Handling', $wholesaleRow['details'][1]['name']);
    }

    public function test_update_bill_date_and_delete_open_bill(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        WholesaleOrderFeeLine::query()->create([
            'wholesale_order_id' => $order->id,
            'line_type' => WholesaleOrderFeeLine::TYPE_MASTER_CARTON,
            'source' => WholesaleOrderFeeLine::SOURCE_WHOLESALE,
            'name' => 'Master Carton',
            'quantity' => 1,
            'unit_price_cents' => 500,
        ]);
        $this->actingAsAdministrator();

        $created = $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/create-bill')
            ->assertCreated();
        $billId = (int) $created->json('id');

        $this->patchJson('/api/billing/wholesale-bills/'.$billId, [
            'bill_date' => '2026-08-15',
        ])->assertOk()->assertJsonPath('bill_date', '2026-08-15');

        $this->deleteJson('/api/billing/wholesale-bills/'.$billId)
            ->assertOk();

        $this->assertDatabaseMissing('wholesale_bills', ['id' => $billId]);
        $this->assertNull($order->fresh()->wholesale_bill_id);
    }

    public function test_portal_and_staff_share_order_comments(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        $portalUser = User::factory()->create(['client_account_id' => $account->id]);
        Sanctum::actingAs($portalUser);

        $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/comments', [
            'body' => 'Please use the blue cartons.',
        ])->assertCreated()->assertJsonPath('body', 'Please use the blue cartons.');

        $this->assertDatabaseHas('wholesale_order_comments', [
            'wholesale_order_id' => $order->id,
            'user_id' => $portalUser->id,
        ]);

        $staff = $this->actingAsAdministrator();
        $this->postJson('/api/admin/wholesale-orders/'.$order->id.'/comments', [
            'body' => 'Confirmed — blue cartons staged.',
        ])->assertCreated();

        $show = $this->getJson('/api/admin/wholesale-orders/'.$order->id)->assertOk();
        $bodies = collect($show->json('comments'))->pluck('body')->all();
        $this->assertContains('Please use the blue cartons.', $bodies);
        $this->assertContains('Confirmed — blue cartons staged.', $bodies);

        Sanctum::actingAs($portalUser);
        $portalShow = $this->getJson('/api/admin/wholesale-orders/'.$order->id)->assertOk();
        $portalBodies = collect($portalShow->json('comments'))->pluck('body')->all();
        $this->assertContains('Please use the blue cartons.', $portalBodies);
        $this->assertContains('Confirmed — blue cartons staged.', $portalBodies);
        $this->assertNotNull($staff->id);
    }
}
