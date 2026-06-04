<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ClientAccountStatusSlackService
{
    private const SHIPHERO_3PL_URL = 'https://app.shiphero.com/3pl';

    private const USERNAME = 'Shipping Status Update';

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
     * Post to the account in-house Slack channel when CRM status changes to active or paused.
     * Failures are logged and do not block the CRM save.
     */
    public function notifyStatusChange(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): void {
        $oldStatus = strtolower(trim($oldStatus));
        $newStatus = strtolower(trim($newStatus));

        if ($oldStatus === $newStatus) {
            return;
        }

        $payload = $this->buildMessagePayload($account, $oldStatus, $newStatus, $actor);
        if ($payload === null) {
            return;
        }

        $channel = $this->slack->channelFromInHouseSlack($account->in_house_slack);
        if ($channel === null || $channel === '') {
            Log::info('client_account.status_slack_skipped', [
                'client_account_id' => $account->id,
                'reason' => 'no_in_house_slack',
            ]);

            return;
        }

        $text = (string) ($payload['text'] ?? '');
        $username = (string) ($payload['username'] ?? self::USERNAME);
        $isLive = $newStatus === ClientAccount::STATUS_ACTIVE;
        $statusLabel = $isLive ? 'Live' : 'Paused';
        $iconUrl = $this->iconUrls->resolveReachableIconUrl($isLive);

        $options = $this->deliveryOptions($text, $username, $iconUrl, $statusLabel);
        $postText = $this->slack->hasBotToken() ? $text : self::USERNAME;

        try {
            $result = $this->slack->post(
                $channel,
                $postText,
                (string) ($options['username'] ?? $username),
                $options['slack'] ?? []
            );
            Log::info('client_account.status_slack_sent', [
                'client_account_id' => $account->id,
                'slack_channel' => $result['channel'],
                'delivery' => $result['method'],
                'icon_url' => $iconUrl !== '' ? $iconUrl : null,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'actor_id' => $actor !== null ? $actor->id : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('client_account.status_slack_failed', [
                'client_account_id' => $account->id,
                'slack_channel' => $channel,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'icon_url' => $iconUrl !== '' ? $iconUrl : null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bot: native username + icon_url header. Blocks: same header when webhook fallback is used.
     *
     * @return array{username: string, slack: array<string, mixed>}
     */
    private function deliveryOptions(string $text, string $username, string $iconUrl, string $statusLabel): array
    {
        $blocks = $this->buildBlocks($text, $iconUrl, $statusLabel);
        $slack = [];

        if ($iconUrl !== '') {
            $slack['icon_url'] = $iconUrl;
        }

        if ($this->slack->hasBotToken()) {
            $slack['customize_identity'] = true;
            $slack['prefer_bot'] = true;
            $slack['blocks_for_webhook_fallback'] = $blocks;
        } else {
            $slack['blocks'] = $blocks;
        }

        return [
            'username' => $username,
            'slack' => $slack,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBlocks(string $text, string $iconUrl, string $statusLabel): array
    {
        $contextElements = [
            ['type' => 'mrkdwn', 'text' => '*'.self::USERNAME.'*'],
        ];

        if ($iconUrl !== '') {
            array_unshift($contextElements, [
                'type' => 'image',
                'image_url' => $iconUrl,
                'alt_text' => $statusLabel,
            ]);
        }

        return [
            [
                'type' => 'context',
                'elements' => $contextElements,
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $text,
                ],
            ],
        ];
    }

    /**
     * @return array{text: string, username: string, icon_url: string}|null
     */
    public function buildMessagePayload(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): ?array {
        $newStatus = strtolower(trim($newStatus));

        if ($newStatus === ClientAccount::STATUS_PAUSED) {
            return $this->buildStatusPayload($account, 'Paused', 'Set Pause in Shiphero', $actor);
        }

        if ($newStatus === ClientAccount::STATUS_ACTIVE) {
            return $this->buildStatusPayload($account, 'Live', 'Set Live in Shiphero', $actor);
        }

        return null;
    }

    public function buildMessageText(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): string {
        $payload = $this->buildMessagePayload($account, $oldStatus, $newStatus, $actor);

        return $payload !== null ? (string) ($payload['text'] ?? '') : '';
    }

    /**
     * @return array{text: string, username: string, icon_url: string}
     */
    private function buildStatusPayload(
        ClientAccount $account,
        string $statusLabel,
        string $shipheroLinkLabel,
        ?User $actor
    ): array {
        $lines = [
            $this->companyLine($account).' is set to '.$statusLabel.'.',
        ];
        $this->appendActorLine($lines, $actor);
        $lines[] = '<'.self::SHIPHERO_3PL_URL.'|'.$shipheroLinkLabel.'>';

        return [
            'text' => implode("\n", $lines),
            'username' => self::USERNAME,
            'icon_url' => '',
        ];
    }

    private function companyLine(ClientAccount $account): string
    {
        $company = trim((string) $account->company_name);

        return $company !== '' ? $company : 'Account #'.$account->id;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function appendActorLine(array &$lines, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        $actorName = trim((string) $actor->name);
        if ($actorName !== '') {
            $lines[] = 'Updated by: '.$actorName;
        }
    }
}
