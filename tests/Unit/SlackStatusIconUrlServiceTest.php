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
}
