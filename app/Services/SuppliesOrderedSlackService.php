<?php

namespace App\Services;

use App\Models\Supply;
use App\Models\SupplyOrder;

class SuppliesOrderedSlackService
{
    private const USERNAME = 'Supplies Ordered';

    /** @var SlackDeliveryService */
    protected $slack;

    public function __construct(SlackDeliveryService $slack)
    {
        $this->slack = $slack;
    }

    /**
     * @return array{method: string, channel: string, ts: string|null}|null
     */
    public function send(SupplyOrder $order): ?array
    {
        $order->loadMissing('lines');
        $payload = $this->buildMessagePayload($order);
        if ($payload['text'] === '') {
            return null;
        }

        $channel = trim((string) (config('supplies.slack_channel') ?: '#supplies'));
        if ($channel === '') {
            $channel = '#supplies';
        }

        $options = [];
        if ($this->slack->hasBotToken()) {
            $options['customize_identity'] = true;
            $options['prefer_bot'] = true;
        }

        return $this->slack->post(
            $channel,
            $payload['text'],
            $payload['username'],
            $options
        );
    }

    /**
     * @return array{text: string, username: string}
     */
    public function buildMessagePayload(SupplyOrder $order): array
    {
        $lines = [];
        foreach ($order->lines as $line) {
            $typeLabel = Supply::typeLabel($line->type);
            $name = trim((string) $line->name);
            $qty = (int) $line->quantity;
            $lines[] = $typeLabel.' '.$name.' - QTY: '.$qty;
        }

        $text = implode("\n", $lines);
        $note = trim((string) ($order->note ?? ''));
        if ($note !== '') {
            $text = 'Notes: '.$note."\n\n".$text;
        }

        return [
            'text' => $text,
            'username' => self::USERNAME,
        ];
    }
}
