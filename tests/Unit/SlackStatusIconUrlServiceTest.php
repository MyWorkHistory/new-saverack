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

    public function test_slack_avatar_url_prefers_avatars_directory(): void
    {
        $this->assertFileExists(public_path('images/slack/avatars/shipping-status-live.png'));

        $url = app(SlackStatusIconUrlService::class)->slackAvatarUrl(true);

        $this->assertSame(
            'https://app.saverack.com/images/slack/avatars/shipping-status-live.png',
            $url
        );
    }

    public function test_slack_avatar_url_for_paused(): void
    {
        $url = app(SlackStatusIconUrlService::class)->slackAvatarUrl(false);

        $this->assertSame(
            'https://app.saverack.com/images/slack/avatars/shipping-status-paused.png',
            $url
        );
    }

    public function test_builds_public_images_url(): void
    {
        $url = app(SlackStatusIconUrlService::class)->liveUrl();

        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-live.png',
            $url
        );
    }
}
