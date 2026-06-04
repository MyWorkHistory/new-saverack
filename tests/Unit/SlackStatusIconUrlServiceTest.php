<?php

namespace Tests\Unit;

use App\Services\SlackStatusIconUrlService;
use Illuminate\Support\Facades\Http;
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

    public function test_resolve_reachable_icon_url_prefers_first_working_candidate(): void
    {
        Http::fake([
            'https://app.saverack.com/images/slack/shipping-status-live-thumb.png' => Http::response('', 404),
            'https://app.saverack.com/images/slack/shipping-status-live.png' => Http::response('', 200, ['Content-Type' => 'image/png']),
        ]);

        $url = app(SlackStatusIconUrlService::class)->resolveReachableIconUrl(true);

        $this->assertSame(
            'https://app.saverack.com/images/slack/shipping-status-live.png',
            $url
        );
    }

    public function test_builds_api_route_url(): void
    {
        $url = app(SlackStatusIconUrlService::class)->liveApiUrl();

        $this->assertSame(
            'https://app.saverack.com/api/slack/status-icons/shipping-status-live.png',
            $url
        );
    }
}
