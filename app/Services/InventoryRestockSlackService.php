<?php

namespace App\Services;

use App\Support\CrmUrls;
use Illuminate\Support\Facades\Log;

class InventoryRestockSlackService
{
    private const USERNAME = 'Restock Needed';

    /** @var SlackDeliveryService */
    protected $slack;

    /** @var SlackStatusIconUrlService */
    protected $iconUrls;

    public function __construct(SlackDeliveryService $slack, SlackStatusIconUrlService $iconUrls)
    {
        $this->slack = $slack;
        $this->iconUrls = $iconUrls;
    }

    /**
     * Post to #restock after a successful restock CSV upload.
     * Failures are logged and do not block the import response.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function notifyUpload(array $snapshot): void
    {
        $payload = $this->buildMessagePayload($snapshot);
        $channel = trim((string) (config('billing.slack.restock_channel') ?: '#restock'));
        if ($channel === '') {
            $channel = '#restock';
        }

        $text = (string) ($payload['text'] ?? '');
        $username = (string) ($payload['username'] ?? self::USERNAME);
        $iconUrl = (string) ($payload['icon_url'] ?? '');
        $iconUrlFallbacks = $payload['icon_url_fallbacks'] ?? [];
        if (! is_array($iconUrlFallbacks)) {
            $iconUrlFallbacks = [];
        }

        $options = $this->deliveryOptions($username, $iconUrl, $iconUrlFallbacks);

        try {
            $result = $this->slack->post(
                $channel,
                $text,
                (string) ($options['username'] ?? $username),
                $options['slack'] ?? []
            );
            Log::info('inventory.restock_slack_sent', [
                'slack_channel' => $result['channel'],
                'delivery' => $result['method'],
                'sku_count' => $payload['sku_count'] ?? null,
                'allocated_orders' => $payload['allocated_orders'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('inventory.restock_slack_failed', [
                'slack_channel' => $channel,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     text: string,
     *     username: string,
     *     icon_url: string,
     *     icon_url_fallbacks: array<int, string>,
     *     sku_count: int,
     *     allocated_orders: int
     * }
     */
    public function buildMessagePayload(array $snapshot): array
    {
        $skuCount = $this->skuCount($snapshot);
        $allocatedOrders = $this->allocatedOrdersTotal($snapshot);
        $viewUrl = CrmUrls::frontendBase().'/admin/inventory/restock';

        $lines = [
            $skuCount.' SKUs Need Restocking',
            $allocatedOrders.' Allocated Orders',
            '<'.$viewUrl.'|View Restocks>',
        ];

        return [
            'text' => implode("\n", $lines),
            'username' => self::USERNAME,
            'icon_url' => $this->iconUrls->restockThumbUrl(),
            'icon_url_fallbacks' => [$this->iconUrls->restockUrl()],
            'sku_count' => $skuCount,
            'allocated_orders' => $allocatedOrders,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function skuCount(array $snapshot): int
    {
        if (isset($snapshot['active_row_count']) && is_numeric($snapshot['active_row_count'])) {
            return max(0, (int) $snapshot['active_row_count']);
        }

        $rows = $snapshot['rows'] ?? [];
        if (! is_array($rows)) {
            return 0;
        }

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function allocatedOrdersTotal(array $snapshot): int
    {
        $rows = $snapshot['rows'] ?? [];
        if (! is_array($rows)) {
            return 0;
        }

        $total = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['allocated']) && is_numeric($row['allocated'])) {
                $total += (int) $row['allocated'];
            }
        }

        return max(0, $total);
    }

    /**
     * @param  array<int, string>  $iconUrlFallbacks
     * @return array{username: string, slack: array<string, mixed>}
     */
    private function deliveryOptions(string $username, string $iconUrl, array $iconUrlFallbacks = []): array
    {
        $slack = [];

        if ($iconUrl !== '') {
            $slack['icon_url'] = $iconUrl;
        }

        if ($iconUrlFallbacks !== []) {
            $slack['icon_url_fallbacks'] = $iconUrlFallbacks;
        }

        if ($this->slack->hasBotToken()) {
            $slack['customize_identity'] = true;
            $slack['prefer_bot'] = true;
        }

        return [
            'username' => $username,
            'slack' => $slack,
        ];
    }
}
