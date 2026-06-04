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
}
