<?php

namespace Tests\Unit;

use App\Services\SlackStatusIconUrlService;
use Illuminate\Support\Facades\Storage;
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

        Storage::fake('public');
    }

    public function test_builds_storage_url_and_publishes_icon(): void
    {
        $source = public_path('images/slack/shipping-status-live.png');
        $this->assertFileExists($source);

        $url = app(SlackStatusIconUrlService::class)->liveUrl();

        $this->assertSame(
            'https://app.saverack.com/storage/slack-status-icons/shipping-status-live.png',
            $url
        );
        Storage::disk('public')->assertExists('slack-status-icons/shipping-status-live.png');
    }
}
