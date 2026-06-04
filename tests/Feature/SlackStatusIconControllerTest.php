<?php

namespace Tests\Feature;

use Tests\TestCase;

class SlackStatusIconControllerTest extends TestCase
{
    public function test_live_icon_returns_png(): void
    {
        $response = $this->get('/api/slack/status-icons/shipping-status-live.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_paused_icon_returns_png(): void
    {
        $response = $this->get('/api/slack/status-icons/shipping-status-paused.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_unknown_icon_is_not_found(): void
    {
        $this->get('/api/slack/status-icons/other.png')->assertNotFound();
    }

    public function test_web_route_serves_36_square_live_icon(): void
    {
        $avatar = public_path('images/slack/avatars/shipping-status-live.png');
        $this->assertFileExists($avatar);
        [$w, $h] = $this->pngDimensions((string) file_get_contents($avatar));
        $this->assertSame(36, $w);
        $this->assertSame(36, $h);

        $response = $this->get('/slack-icons/shipping-status-live.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_avatars_static_path_serves_36_square_icon(): void
    {
        $response = $this->get('/images/slack/avatars/shipping-status-live.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        [$w, $h] = $this->pngDimensions((string) $response->getContent());
        $this->assertSame(36, $w);
        $this->assertSame(36, $h);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pngDimensions(string $bytes): array
    {
        $this->assertGreaterThan(24, strlen($bytes));
        $w = unpack('N', substr($bytes, 16, 4))[1];
        $h = unpack('N', substr($bytes, 20, 4))[1];

        return [$w, $h];
    }
}
