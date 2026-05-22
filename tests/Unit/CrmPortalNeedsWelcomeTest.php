<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Mirrors resources/crm/utils/crmUser.js crmPortalNeedsWelcome — update both when logic changes.
 */
class CrmPortalNeedsWelcomeTest extends TestCase
{
    private function crmPortalNeedsWelcome(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        $raw = $user['client_account_id'] ?? null;
        if ($raw === null) {
            return false;
        }
        $isPortal = is_int($raw) ? $raw > 0 : (trim((string) $raw) !== '' && trim((string) $raw) !== '0');
        if (! $isPortal) {
            return false;
        }

        return ($user['client_account_status'] ?? '') === 'pending';
    }

    public function test_true_only_when_portal_user_and_client_account_pending(): void
    {
        $this->assertTrue($this->crmPortalNeedsWelcome([
            'client_account_id' => 1,
            'client_account_status' => 'pending',
        ]));
    }

    public function test_false_when_account_active_even_if_user_pending_or_setup_incomplete(): void
    {
        $this->assertFalse($this->crmPortalNeedsWelcome([
            'client_account_id' => 1,
            'client_account_status' => 'active',
            'status' => 'pending',
            'shiphero_ready' => false,
            'portal_setup_complete' => false,
        ]));
    }

    public function test_false_for_staff_without_client_account(): void
    {
        $this->assertFalse($this->crmPortalNeedsWelcome([
            'client_account_id' => null,
            'client_account_status' => 'pending',
        ]));
    }
}
