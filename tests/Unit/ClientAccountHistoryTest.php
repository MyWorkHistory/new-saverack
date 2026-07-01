<?php

namespace Tests\Unit;

use App\Support\ClientAccountHistory;
use PHPUnit\Framework\TestCase;

class ClientAccountHistoryTest extends TestCase
{
    public function test_summarize_update_uses_history_section_when_provided(): void
    {
        $this->assertSame(
            'Personal Information',
            ClientAccountHistory::summarizeUpdate(['email', 'phone'], 'account')
        );
        $this->assertSame(
            'Address',
            ClientAccountHistory::summarizeUpdate(['street', 'city'], 'address')
        );
        $this->assertSame(
            'Settings',
            ClientAccountHistory::summarizeUpdate(['email', 'slack_channel', 'account_manager_id'], 'left')
        );
        $this->assertSame(
            'Settings',
            ClientAccountHistory::summarizeUpdate(['stripe_customer_id'], 'settings')
        );
    }

    public function test_summarize_fields_groups_by_category_without_section(): void
    {
        $this->assertSame(
            'Personal Information',
            ClientAccountHistory::summarizeFields(['company_name', 'email'])
        );
        $this->assertSame(
            'Address',
            ClientAccountHistory::summarizeFields(['street', 'zip'])
        );
        $this->assertSame(
            'Settings',
            ClientAccountHistory::summarizeFields(['account_manager_id', 'slack_channel'])
        );
        $this->assertSame(
            'Settings',
            ClientAccountHistory::summarizeFields(['shiphero_customer_account_id'])
        );
        $this->assertSame(
            'Status',
            ClientAccountHistory::summarizeFields(['status'])
        );
        $this->assertSame(
            'Fees',
            ClientAccountHistory::summarizeFields(['fees'])
        );
    }
}
