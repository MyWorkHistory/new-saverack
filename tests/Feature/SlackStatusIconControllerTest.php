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

    public function test_web_route_serves_live_icon(): void
    {
        $response = $this->get('/slack-icons/shipping-status-live.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_static_images_path_serves_live_icon(): void
    {
        $response = $this->get('/images/slack/shipping-status-live.png');

        $response->assertOk();
        $this->assertStringContainsString('image/png', (string) $response->headers->get('Content-Type'));
    }

    public function test_avatar_route_serves_live_icon(): void
    {
        $response = $this->get('/images/slack/avatars/shipping-status-live.png');

        $response->assertOk();
        $this->assertStringContainsString('image/png', (string) $response->headers->get('Content-Type'));
    }
}
