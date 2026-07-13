<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Support\Legacy\LegacyPortalUserImportMapper;
use App\Support\Legacy\LegacyStaffUserImportMapper;
use Tests\TestCase;

final class LegacyPortalUserImportMapperTest extends TestCase
{
    public function test_accepts_active_portal_user_row(): void
    {
        $row = (object) [
            'userType' => 'User',
            'role' => 2,
            'status' => 2,
            'is_deleted' => 1,
        ];

        $this->assertTrue(LegacyPortalUserImportMapper::isImportablePortalRow($row));
    }

    public function test_rejects_employee_row(): void
    {
        $row = (object) [
            'userType' => 'Employee',
            'role' => 2,
            'status' => 2,
            'is_deleted' => 1,
        ];

        $this->assertFalse(LegacyPortalUserImportMapper::isImportablePortalRow($row));
    }

    public function test_rejects_non_portal_role(): void
    {
        $row = (object) [
            'userType' => 'User',
            'role' => 4,
            'status' => 2,
            'is_deleted' => 1,
        ];

        $this->assertFalse(LegacyPortalUserImportMapper::isImportablePortalRow($row));
    }

    public function test_includes_pending_when_flag_set(): void
    {
        $row = (object) [
            'userType' => 'User',
            'role' => 2,
            'status' => 1,
            'is_deleted' => 1,
        ];

        $this->assertFalse(LegacyPortalUserImportMapper::isImportablePortalRow($row));
        $this->assertTrue(LegacyPortalUserImportMapper::isImportablePortalRow($row, true, false, false));
    }

    public function test_maps_roles_and_status(): void
    {
        $this->assertSame('customer_service', LegacyPortalUserImportMapper::resolveAccountUserRole(1));
        $this->assertSame('admin', LegacyPortalUserImportMapper::resolveAccountUserRole(2));
        $this->assertSame('pending', LegacyPortalUserImportMapper::mapLegacyStatus(1));
        $this->assertSame('active', LegacyPortalUserImportMapper::mapLegacyStatus(2));
        $this->assertSame('inactive', LegacyPortalUserImportMapper::mapLegacyStatus(3));
    }

    public function test_preserves_legacy_bcrypt_hash(): void
    {
        $hash = '$2y$10$haDN.blhV45aG1lkbbA4NuMQs25WhMT7tEHQEAk1NwHS/CiBCOXdy';
        $row = (object) ['password' => $hash];

        $this->assertSame($hash, LegacyPortalUserImportMapper::resolvePasswordHash($row, 'fallback'));
    }

    public function test_maps_user_fields_for_primary_admin(): void
    {
        $account = new ClientAccount();
        $account->id = 10;
        $account->email = 'info@example.com';

        $row = (object) [
            'id' => 64,
            'full_name' => 'Jane Client',
            'email' => 'info@example.com',
            'role' => 2,
            'status' => 2,
            'last_logged_in_at' => '2025-08-28 23:07:26',
            'ip_address' => '127.0.0.1',
        ];

        $mapped = LegacyPortalUserImportMapper::mapUserFields($row, $account, 'hashed', true);

        $this->assertSame(64, $mapped['legacy_user_id']);
        $this->assertSame(10, $mapped['client_account_id']);
        $this->assertSame('admin', $mapped['account_user_role']);
        $this->assertTrue($mapped['is_account_primary']);
        $this->assertSame('active', $mapped['status']);
        $this->assertSame('info@example.com', $mapped['email']);
    }

    public function test_maps_customer_service_secondary_user(): void
    {
        $account = new ClientAccount();
        $account->id = 10;
        $account->email = 'info@example.com';

        $row = (object) [
            'id' => 296,
            'full_name' => 'Customer Service',
            'email' => 'customerservice@immunisglobal.com',
            'role' => 1,
            'status' => 2,
        ];

        $mapped = LegacyPortalUserImportMapper::mapUserFields($row, $account, 'hashed', true);

        $this->assertSame('customer_service', $mapped['account_user_role']);
        $this->assertFalse($mapped['is_account_primary']);
    }

    public function test_skips_default_avatar_in_profile(): void
    {
        $row = (object) [
            'avatar' => '/assets/images/users/default.png',
            'phone' => '555-0100',
        ];

        $mapped = LegacyPortalUserImportMapper::mapProfileFields($row);

        $this->assertArrayNotHasKey('avatar_path', $mapped);
        $this->assertSame('555-0100', $mapped['phone']);
    }

    public function test_parses_legacy_customer_ids(): void
    {
        $this->assertSame([12, 34], LegacyPortalUserImportMapper::parseLegacyCustomerIds('12, 34'));
        $this->assertSame([], LegacyPortalUserImportMapper::parseLegacyCustomerIds(null));
    }

    public function test_norm_email_shared_with_staff_mapper(): void
    {
        $this->assertSame('test@example.com', LegacyStaffUserImportMapper::normEmail('  TEST@Example.com '));
    }
}
