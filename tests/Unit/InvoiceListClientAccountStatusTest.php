<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Tests\TestCase;

final class InvoiceListClientAccountStatusTest extends TestCase
{
    public function test_list_array_includes_client_account_status(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Status List Co',
            'status' => ClientAccount::STATUS_PAUSED,
            'email' => 'status-list@test.com',
        ]);
        $account->id = 42;

        $invoice = new Invoice([
            'client_account_id' => $account->id,
            'invoice_number' => 'INV-9001',
            'status' => 'open',
            'currency' => 'USD',
            'subtotal_cents' => 100,
            'tax_cents' => 0,
            'total_cents' => 100,
            'balance_due_cents' => 100,
            'amount_paid_cents' => 0,
        ]);
        $invoice->id = 1;
        $invoice->created_at = now();
        $invoice->updated_at = now();
        $invoice->setRelation('clientAccount', $account);

        $row = app(InvoiceService::class)->toListArray($invoice);

        $this->assertSame(ClientAccount::STATUS_PAUSED, $row['client_account_status']);
        $this->assertSame('Status List Co', $row['client_company_name']);
    }
}
