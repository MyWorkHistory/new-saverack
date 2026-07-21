<?php

namespace App\Services;

use App\Models\Tutorial;
use App\Support\CrmUrls;

class TutorialSlackService
{
    private const USERNAME = 'New Tutorial';

    /** @var SlackDeliveryService */
    protected $slack;

    public function __construct(SlackDeliveryService $slack)
    {
        $this->slack = $slack;
    }

    /**
     * @return array{method: string, channel: string, ts: string|null}
     */
    public function send(Tutorial $tutorial): array
    {
        $payload = $this->buildMessagePayload($tutorial);
        $channel = trim((string) (config('billing.slack.faq_channel') ?: '#faq'));
        if ($channel === '') {
            $channel = '#faq';
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
    public function buildMessagePayload(Tutorial $tutorial): array
    {
        $url = CrmUrls::tutorialStaffUrl((int) $tutorial->id);
        $lines = [
            (string) $tutorial->title,
            'Category: '.Tutorial::categoryLabel($tutorial->category),
            '<'.$url.'|View Tutorial>',
        ];

        return [
            'text' => implode("\n", $lines),
            'username' => self::USERNAME,
        ];
    }
}
