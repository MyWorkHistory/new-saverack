<?php

namespace Tests\Unit;

use App\Services\SlackStatusIconUrlService;
use Tests\TestCase;

final class SlackStatusIconUrlServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.saverack.com',
            'billing.slack.public_asset_base_url' => 'https://app.saverack.com',
        ]);
    }

    public function test_avatar_url_uses_api_route_for_local_file(): void
    {
        $this->assertFileExists(public_path('images/slack/shipping-status-live-thumb.png'));

        $url = app(SlackStatusIconUrlService::class)->avatarUrl(true);

        $this->assertSame(
            'https://app.saverack.com/api/slack/status-icons/shipping-status-live-thumb.png',
            $url
        );
    }

    public function test_avatar_url_ignores_broken_env_when_local_file_exists(): void
    {
        config([
            'billing.slack.status_icon_live_url' => 'https://broken.example.com/storage/slack-status-icons/shipping-status-live.png',
        ]);

        $url = app(SlackStatusIconUrlService::class)->avatarUrl(true);

        $this->assertSame(
            'https://app.saverack.com/api/slack/status-icons/shipping-status-live-thumb.png',
            $url
        );
    }

    public function test_legacy_storage_env_url_remapped_to_api_route(): void
    {
        config([
            'billing.slack.status_icon_live_url' => 'https://app.saverack.com/storage/slack-status-icons/shipping-status-live.png',
        ]);

        $url = app(SlackStatusIconUrlService::class)->liveUrl();

        $this->assertSame(
            'https://app.saverack.com/api/slack/status-icons/shipping-status-live.png',
            $url
        );
    }

    public function test_paused_avatar_url_uses_api_route(): void
    {
        $url = app(SlackStatusIconUrlService::class)->avatarUrl(false);

        $this->assertSame(
            'https://app.saverack.com/api/slack/status-icons/shipping-status-paused-thumb.png',
            $url
        );
    }
}
