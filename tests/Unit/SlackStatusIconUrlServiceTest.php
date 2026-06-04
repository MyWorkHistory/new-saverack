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

    public function test_builds_public_images_url(): void
    {
        $source = public_path('images/slack/shipping-status-live.png');
        $this->assertFileExists($source);

        $url = app(SlackStatusIconUrlService::class)->liveUrl();

        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-live.png',
            $url
        );
    }

    public function test_builds_thumb_url_for_attachment(): void
    {
        $source = public_path('images/slack/shipping-status-paused-thumb.png');
        $this->assertFileExists($source);

        $url = app(SlackStatusIconUrlService::class)->pausedThumbUrl();

        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-paused-thumb.png',
            $url
        );
    }

    public function test_legacy_storage_env_url_remapped_to_public_images_path(): void
    {
        config([
            'billing.slack.status_icon_live_url' => 'https://app.saverack.com/storage/slack-status-icons/shipping-status-live.png',
        ]);

        $url = app(SlackStatusIconUrlService::class)->liveUrl();

        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-live.png',
            $url
        );
    }

    public function test_live_thumb_falls_back_to_full_icon_when_thumb_file_missing(): void
    {
        $thumb = public_path('images/slack/shipping-status-live-thumb.png');
        $backup = $thumb.'.bak';
        $hadThumb = is_file($thumb);
        if ($hadThumb) {
            rename($thumb, $backup);
        }

        try {
            $url = app(SlackStatusIconUrlService::class)->liveThumbUrl();
            $this->assertSame(
                'https://app.saverack.com/images/slack/shipping-status-live.png',
                $url
            );
        } finally {
            if ($hadThumb && is_file($backup)) {
                rename($backup, $thumb);
            }
        }
    }
}
