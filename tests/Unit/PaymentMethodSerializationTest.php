<?php

namespace Tests\Unit;

use App\Services\AccountPaymentMethodService;
use App\Services\StripeInvoicePaymentService;
use Tests\TestCase;

class PaymentMethodSerializationTest extends TestCase
{
    public function test_list_row_includes_last4_and_type_label_for_card(): void
    {
        $service = app(StripeInvoicePaymentService::class);
        $row = $service->serializePaymentMethodListRow([
            'id' => 'pm_card_1',
            'type' => 'card',
            'card' => [
                'brand' => 'visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2030,
            ],
        ], 'pm_card_1');

        $this->assertSame('Credit Card', $row['type_label']);
        $this->assertSame('4242', $row['last4']);
        $this->assertTrue($row['is_default']);
    }

    public function test_list_row_for_bank(): void
    {
        $service = app(StripeInvoicePaymentService::class);
        $row = $service->serializePaymentMethodListRow([
            'id' => 'pm_bank_1',
            'type' => 'us_bank_account',
            'us_bank_account' => [
                'bank_name' => 'STRIPE TEST BANK',
                'last4' => '6789',
            ],
        ]);

        $this->assertSame('ACH Bank', $row['type_label']);
        $this->assertSame('6789', $row['last4']);
        $this->assertFalse($row['is_default']);
    }

    public function test_pin_matches_config_default(): void
    {
        config(['crm.payment_method_view_pin' => '0912']);
        $service = app(AccountPaymentMethodService::class);
        $this->assertTrue($service->pinMatches('0912'));
        $this->assertFalse($service->pinMatches('1234'));
    }
}
