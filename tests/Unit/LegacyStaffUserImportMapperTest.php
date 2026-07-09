<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyStaffUserImportMapper;
use Tests\TestCase;

final class LegacyStaffUserImportMapperTest extends TestCase
{
    public function test_accepts_active_employee_row(): void
    {
        $row = (object) [
            'userType' => 'Employee',
            'status' => 2,
            'is_deleted' => 1,
        ];

        $this->assertTrue(LegacyStaffUserImportMapper::isImportableStaffRow($row));
    }

    public function test_rejects_client_portal_user_row(): void
    {
        $row = (object) [
            'userType' => 'User',
            'status' => 2,
            'is_deleted' => 1,
        ];

        $this->assertFalse(LegacyStaffUserImportMapper::isImportableStaffRow($row));
    }

    public function test_rejects_inactive_employee(): void
    {
        $row = (object) [
            'userType' => 'Employee',
            'status' => 3,
            'is_deleted' => 1,
        ];

        $this->assertFalse(LegacyStaffUserImportMapper::isImportableStaffRow($row));
    }

    public function test_rejects_deleted_employee(): void
    {
        $row = (object) [
            'userType' => 'Employee',
            'status' => 2,
            'is_deleted' => 2,
        ];

        $this->assertFalse(LegacyStaffUserImportMapper::isImportableStaffRow($row));
    }

    public function test_role_mapping_admin_and_staff(): void
    {
        $this->assertSame('admin', LegacyStaffUserImportMapper::resolveCrmRoleName(9));
        $this->assertSame('admin', LegacyStaffUserImportMapper::resolveCrmRoleName(4));
        $this->assertSame('staff', LegacyStaffUserImportMapper::resolveCrmRoleName(5));
    }

    public function test_maps_user_fields_for_eda(): void
    {
        $row = (object) [
            'id' => 120,
            'full_name' => 'Eda Abretil',
            'email' => 'eda@saverack.com',
            'status' => 2,
            'last_logged_in_at' => '2026-03-18 14:34:15',
            'ip_address' => '110.235.183.189',
        ];

        $mapped = LegacyStaffUserImportMapper::mapUserFields($row, 'hashed-password');

        $this->assertSame(120, $mapped['legacy_user_id']);
        $this->assertSame('Eda Abretil', $mapped['name']);
        $this->assertSame('eda@saverack.com', $mapped['email']);
        $this->assertSame('active', $mapped['status']);
        $this->assertSame('hashed-password', $mapped['password']);
        $this->assertArrayNotHasKey('client_account_id', $mapped);
    }

    public function test_maps_profile_fields(): void
    {
        $row = (object) [
            'role' => 5,
            'phone' => '+639317092949',
            'avatar' => '/assets/images/users/default.png',
            'userType' => 'Employee',
            'employeeType' => 1,
            'hireDate' => '2021-09-07',
            'crm_access' => 'Yes',
            'wh_access' => 'No',
            'slack' => 'eda',
            'slack_member_id' => 'U02D4R9A9GB',
            'manager' => 1,
            'manager_name' => 'Audi Kowalski',
        ];

        $mapped = LegacyStaffUserImportMapper::mapProfileFields($row);

        $this->assertSame(5, $mapped['legacy_numeric_role']);
        $this->assertSame('Full-Time', $mapped['employee_type']);
        $this->assertSame('2021-09-07', $mapped['hire_date']);
        $this->assertTrue($mapped['crm_access']);
        $this->assertFalse($mapped['wh_access']);
        $this->assertSame('eda', $mapped['slack']);
        $this->assertArrayHasKey('legacy_fields', $mapped);
        $this->assertSame(1, $mapped['legacy_fields']['manager']);
    }

    public function test_skips_invalid_dates(): void
    {
        $row = (object) [
            'role' => 5,
            'hireDate' => '0000-00-00',
            'birthday' => null,
        ];

        $mapped = LegacyStaffUserImportMapper::mapProfileFields($row);

        $this->assertArrayNotHasKey('hire_date', $mapped);
    }
}
