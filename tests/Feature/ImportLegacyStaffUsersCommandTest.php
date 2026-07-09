<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImportLegacyStaffUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_legacy_connection_not_configured(): void
    {
        config(['database.connections.legacy_crm_test' => [
            'driver' => 'mysql',
            'database' => '',
        ]]);

        $this->artisan('crm:import-legacy-staff-users', [
            '--connection' => 'legacy_crm_test',
        ])->assertExitCode(1);
    }
}
