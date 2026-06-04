<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class VerifySlackStatusSetupCommandTest extends TestCase
{
    public function test_command_fails_when_bot_token_missing(): void
    {
        config([
            'billing.slack.bot_token' => null,
            'billing.slack.public_asset_base_url' => 'https://app.saverack.com',
        ]);

        $this->artisan('slack:verify-status-setup')
            ->assertExitCode(1);
    }

    public function test_command_passes_when_bot_and_icons_ok(): void
    {
        config([
            'billing.slack.bot_token' => 'xoxb-test',
            'billing.slack.public_asset_base_url' => 'https://app.saverack.com',
        ]);

        Http::fake([
            'https://app.saverack.com/images/slack/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->artisan('slack:verify-status-setup')
            ->assertExitCode(0);
    }
}
