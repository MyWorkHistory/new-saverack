<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Support\ClientAccountBillingPreferences;
use Tests\TestCase;

final class InvoiceDetailClientAccountBillingPreferencesTest extends TestCase
{
    public function test_detail_array_includes_client_account_billing_preference_labels(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Prefs Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'prefs@test.com',
            'postage_option' => ClientAccountBillingPreferences::POSTAGE_CUSTOMER_USPS,
            'packaging_option' => ClientAccountBillingPreferences::PACKAGING_CUSTOMER_SOME,
        ]);
        $account->id = 77;

        $invoice = new Invoice([
            'client_account_id' => $account->id,
            'invoice_number' => 'INV-PREFS-001',
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_cents' => 0,
            'tax_cents' => 0,
            'total_cents' => 0,
            'balance_due_cents' => 0,
            'amount_paid_cents' => 0,
        ]);
        $invoice->id = 5;
        $invoice->created_at = now();
        $invoice->updated_at = now();
        $invoice->setRelation('clientAccount', $account);
        $invoice->setRelation('items', collect());
        $invoice->setRelation('histories', collect());
        $invoice->setRelation('createdBy', null);

        $detail = app(InvoiceService::class)->toDetailArray($invoice);

        $this->assertSame(
            ClientAccountBillingPreferences::POSTAGE_CUSTOMER_USPS,
            $detail['client_account_postage_option']
        );
        $this->assertSame(
            'Customer Provides USPS Account',
            $detail['client_account_postage_option_label']
        );
        $this->assertSame(
            ClientAccountBillingPreferences::PACKAGING_CUSTOMER_SOME,
            $detail['client_account_packaging_option']
        );
        $this->assertSame(
            'Customer Provides Some Packaging Materials',
            $detail['client_account_packaging_option_label']
        );
    }
}
