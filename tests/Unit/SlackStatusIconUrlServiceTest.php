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

    public function test_avatar_url_uses_local_thumb_file(): void
    {
        $this->assertFileExists(public_path('images/slack/shipping-status-live-thumb.png'));

        $url = app(SlackStatusIconUrlService::class)->avatarUrl(true);

        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-live-thumb.png',
            $url
        );
    }

    public function test_avatar_url_ignores_broken_env_when_local_file_exists(): void
    {
        config([
            'billing.slack.status_icon_live_url' => 'https://broken.example.com/storage/slack-status-icons/shipping-status-live.png',
            'billing.slack.status_icon_live_thumb_url' => 'https://broken.example.com/storage/slack-status-icons/shipping-status-live.png',
        ]);

        $url = app(SlackStatusIconUrlService::class)->avatarUrl(true);

        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-live-thumb.png',
            $url
        );
    }

    public function test_legacy_storage_env_url_remapped_when_local_file_missing(): void
    {
        $thumb = public_path('images/slack/shipping-status-live-thumb.png');
        $backup = $thumb.'.bak';
        $hadThumb = is_file($thumb);
        if ($hadThumb) {
            rename($thumb, $backup);
        }

        config([
            'billing.slack.status_icon_live_url' => 'https://app.saverack.com/storage/slack-status-icons/shipping-status-live.png',
        ]);

        try {
            $url = app(SlackStatusIconUrlService::class)->avatarUrl(true);
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

    public function test_paused_avatar_url_uses_local_thumb(): void
    {
        $url = app(SlackStatusIconUrlService::class)->avatarUrl(false);

        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-paused-thumb.png',
            $url
        );
    }
}
