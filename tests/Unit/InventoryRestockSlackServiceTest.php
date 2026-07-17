<?php

namespace Tests\Unit;

use App\Services\InventoryRestockSlackService;
use App\Services\SlackDeliveryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class InventoryRestockSlackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.saverack.com',
            'crm.frontend_url' => 'https://app.saverack.com',
            'billing.slack.public_asset_base_url' => 'https://app.saverack.com',
            'billing.slack.restock_channel' => '#restock',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleSnapshot(): array
    {
        return [
            'active_row_count' => 2,
            'rows' => [
                ['sku' => 'ABC-1', 'allocated' => 15],
                ['sku' => 'ABC-2', 'allocated' => 25],
            ],
        ];
    }

    public function test_build_message_payload_matches_screenshot_copy(): void
    {
        $payload = app(InventoryRestockSlackService::class)->buildMessagePayload($this->sampleSnapshot());

        $this->assertSame('Restock Needed', $payload['username']);
        $this->assertSame(
            "Restocks Needed\n2 SKUs Need Restocking\n40 Allocated Orders\n<https://app.saverack.com/admin/inventory/restock|View Restocks>",
            $payload['text']
        );
        $this->assertSame(2, $payload['sku_count']);
        $this->assertSame(40, $payload['allocated_orders']);
        $this->assertStringContainsString('/restock-needed-thumb.png', $payload['icon_url']);
        $this->assertStringContainsString('/restock-needed.png', $payload['icon_url_fallbacks'][0]);
    }

    public function test_notify_upload_posts_to_restock_channel(): void
    {
        config([
            'billing.slack.webhook_url' => 'https://hooks.slack.com/services/T/B/x',
            'billing.slack.bot_token' => 'xoxb-test-token',
        ]);

        Http::fake([
            'https://slack.com/api/*' => Http::response(['ok' => true, 'channel' => 'C_RESTOCK', 'ts' => '1.0'], 200),
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        Log::shouldReceive('info')->andReturnNull();

        app(InventoryRestockSlackService::class)->notifyUpload($this->sampleSnapshot());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat.postMessage')) {
                return false;
            }

            $payload = $request->data();

            return ($payload['username'] ?? '') === 'Restock Needed'
                && ($payload['channel'] ?? '') === '#restock'
                && str_contains((string) ($payload['text'] ?? ''), '2 SKUs Need Restocking')
                && str_contains((string) ($payload['text'] ?? ''), '40 Allocated Orders')
                && str_contains((string) ($payload['icon_url'] ?? ''), 'restock-needed-thumb.png')
                && ! array_key_exists('blocks', $payload);
        });
    }

    public function test_notify_upload_failure_does_not_throw(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(false);
        $slack->method('post')->willThrowException(new \RuntimeException('slack down'));

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('warning')->once()->andReturnNull();

        app(InventoryRestockSlackService::class)->notifyUpload($this->sampleSnapshot());

        $this->assertTrue(true);
    }
}
