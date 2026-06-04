<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VerifySlackStatusSetupCommandTest extends TestCase
{
    public function test_command_fails_when_bot_token_missing(): void
    {
        config([
            'billing.slack.bot_token' => null,
            'billing.slack.public_asset_base_url' => 'https://app.saverack.com',
        ]);

        Storage::fake('public');

        $this->artisan('slack:verify-status-setup')
            ->assertExitCode(1);
    }

    public function test_command_passes_when_bot_and_icons_ok(): void
    {
        config([
            'billing.slack.bot_token' => 'xoxb-test',
            'billing.slack.public_asset_base_url' => 'https://app.saverack.com',
        ]);

        Storage::fake('public');

        Http::fake([
            'https://app.saverack.com/storage/slack-status-icons/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->artisan('slack:verify-status-setup')
            ->assertExitCode(0);
    }
}
