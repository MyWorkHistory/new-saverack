<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Support\ClientAccountBillingPreferences;
use App\Support\Legacy\LegacyCustomerAccountImportMapper;
use Tests\TestCase;

final class LegacyCustomerAccountImportMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LegacyCustomerAccountImportMapper::clearManagerCache();
    }

    public function test_maps_contract_date_from_active_date(): void
    {
        $row = (object) [
            'activeDate' => '2024-08-05',
            'created_at' => '2021-09-02 17:31:29',
        ];

        $this->assertSame('2024-08-05', LegacyCustomerAccountImportMapper::resolveContractDate($row));
    }

    public function test_maps_contract_date_fallback_to_created_at(): void
    {
        $row = (object) [
            'activeDate' => null,
            'created_at' => '2021-09-02 17:31:29',
        ];

        $this->assertSame('2021-09-02', LegacyCustomerAccountImportMapper::resolveContractDate($row));
    }

    public function test_maps_in_house_slack_and_client_channel_url(): void
    {
        $row = (object) [
            'slack_channel' => 'tmdk',
            'group_chat' => 'https://t.me/joinchat/wx-mz71LNvZlNzZh',
            'telegram_group' => null,
            'skype_group' => null,
            'manager' => null,
            'manager_name' => null,
            'c_email' => 'contact@example.test',
        ];

        $mapped = LegacyCustomerAccountImportMapper::mapEnrichmentFields($row);

        $this->assertSame('tmdk', $mapped['in_house_slack']);
        $this->assertSame('https://t.me/joinchat/wx-mz71LNvZlNzZh', $mapped['slack_channel']);
    }

    public function test_normalizes_payment_method_and_terms(): void
    {
        $this->assertSame('ACH', LegacyCustomerAccountImportMapper::normalizePaymentType('ach'));
        $this->assertSame('Credit Card', LegacyCustomerAccountImportMapper::normalizePaymentType('Credit Card'));
        $this->assertSame('Manual', LegacyCustomerAccountImportMapper::normalizePaymentType('unknown-method'));
        $this->assertSame(7, LegacyCustomerAccountImportMapper::normalizePaymentTermsDays(7));
    }

    public function test_builds_notes_from_reason_and_status_reason(): void
    {
        $this->assertSame(
            "Management\nOut of Business",
            LegacyCustomerAccountImportMapper::buildNotes('Management', 'Out of Business')
        );
    }

    public function test_maps_enrichment_without_account_manager_when_no_users(): void
    {
        $row = (object) [
            'manager' => null,
            'manager_name' => null,
            'activeDate' => '2024-08-05',
            'created_at' => '2021-09-02 17:31:29',
            'slack_channel' => 'tmdk',
            'group_chat' => 'https://join.skype.com/qZZep3c0CVHW',
            'stripe_customer_id' => 'cus_test123',
            'wp_chat_id' => 'wa_chat_1',
            'cc_charge' => 3.0,
            'payment_method' => 'ACH',
            'net' => 7,
            'b_email' => 'billing@example.test',
            'c_email' => 'contact@example.test',
            'reason' => 'Management',
            'status_reason' => 'Out of Business',
            'logo_path' => '/assets/images/customers/logo.jpg',
            'postage_check' => 'Save Rack',
            'account_balance' => 123.45,
        ];

        $mapped = LegacyCustomerAccountImportMapper::mapEnrichmentFields($row, 'contact@example.test');

        $this->assertArrayNotHasKey('account_manager_id', $mapped);
        $this->assertSame('2024-08-05', $mapped['contract_date']);
        $this->assertSame('tmdk', $mapped['in_house_slack']);
        $this->assertSame('cus_test123', $mapped['stripe_customer_id']);
        $this->assertSame('ACH', $mapped['default_payment_type']);
        $this->assertSame(7, $mapped['payment_terms_days']);
        $this->assertTrue($mapped['notify_email']);
        $this->assertSame('billing@example.test', $mapped['notification_email']);
        $this->assertSame("Management\nOut of Business", $mapped['notes']);
        $this->assertSame(ClientAccountBillingPreferences::POSTAGE_SAVE_RACK_ALL, $mapped['postage_option']);
        $this->assertSame(12345, $mapped['billing_available_funds_cents']);
    }

    public function test_clamps_negative_billing_balance_to_zero(): void
    {
        $this->assertSame(0, LegacyCustomerAccountImportMapper::normalizeBillingBalance(-37.19));
        $this->assertSame(3719, LegacyCustomerAccountImportMapper::normalizeBillingBalance(37.19));
    }

    public function test_merge_for_sync_fills_empty_fields_only_by_default(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Acme',
            'email' => 'acme@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
            'legacy_customer_id' => 28,
            'in_house_slack' => 'existing-channel',
            'stripe_customer_id' => 'cus_existing',
        ]);

        $mapped = [
            'in_house_slack' => 'tmdk',
            'stripe_customer_id' => 'cus_new',
            'contract_date' => '2024-08-05',
        ];

        $attrs = LegacyCustomerAccountImportMapper::mergeForSync($mapped, $account, false);

        $this->assertArrayHasKey('contract_date', $attrs);
        $this->assertArrayNotHasKey('in_house_slack', $attrs);
        $this->assertArrayNotHasKey('stripe_customer_id', $attrs);
    }

    public function test_merge_for_sync_overwrites_with_force(): void
    {
        $account = new ClientAccount([
            'company_name' => 'Acme',
            'email' => 'acme@example.test',
            'status' => ClientAccount::STATUS_ACTIVE,
            'legacy_customer_id' => 28,
            'in_house_slack' => 'existing-channel',
        ]);

        $mapped = [
            'in_house_slack' => 'tmdk',
        ];

        $attrs = LegacyCustomerAccountImportMapper::mergeForSync($mapped, $account, true);

        $this->assertSame(['in_house_slack' => 'tmdk'], $attrs);
    }

    public function test_normalize_company_name_collapses_whitespace_and_case(): void
    {
        $this->assertSame(
            'tmdk ventures llc',
            LegacyCustomerAccountImportMapper::normalizeCompanyName('  TMDK   Ventures LLC ')
        );
    }

    public function test_company_name_match_key_strips_legal_suffixes_and_punctuation(): void
    {
        $this->assertSame(
            'gadget viking',
            LegacyCustomerAccountImportMapper::companyNameMatchKey('Gadget Viking LLC')
        );
        $this->assertSame(
            'gadget viking',
            LegacyCustomerAccountImportMapper::companyNameMatchKey('Gadget Viking, Inc.')
        );
        $this->assertSame(
            'ian and ila test',
            LegacyCustomerAccountImportMapper::companyNameMatchKey('Ian & Ila Test Company')
        );
    }

    public function test_legacy_customer_id_backfill_only_when_empty_or_forced(): void
    {
        $empty = new ClientAccount(['legacy_customer_id' => null]);
        $this->assertSame(
            ['legacy_customer_id' => 28],
            LegacyCustomerAccountImportMapper::legacyCustomerIdBackfill(28, $empty, false)
        );

        $set = new ClientAccount(['legacy_customer_id' => 99]);
        $this->assertSame([], LegacyCustomerAccountImportMapper::legacyCustomerIdBackfill(28, $set, false));
        $this->assertSame(
            ['legacy_customer_id' => 28],
            LegacyCustomerAccountImportMapper::legacyCustomerIdBackfill(28, $set, true)
        );
    }
}
