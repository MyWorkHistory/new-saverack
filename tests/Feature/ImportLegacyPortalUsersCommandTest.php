<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ImportLegacyPortalUsersCommandTest extends TestCase
{
    public function test_command_fails_when_legacy_connection_not_configured(): void
    {
        config(['database.connections.legacy_crm_test' => [
            'driver' => 'mysql',
            'database' => '',
        ]]);

        $this->artisan('crm:import-legacy-portal-users', [
            '--connection' => 'legacy_crm_test',
        ])->assertExitCode(1);
    }
}
