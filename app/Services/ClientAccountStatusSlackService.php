<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ClientAccountStatusSlackService
{
    private const SHIPHERO_3PL_URL = 'https://app.shiphero.com/3pl';

    private const USERNAME = 'Shipping Status Update';

    private const ICON_LIVE = 'shipping-status-live.png';

    private const ICON_PAUSED = 'shipping-status-paused.png';

    /** @var SlackDeliveryService */
    protected $slack;

    public function __construct(SlackDeliveryService $slack)
    {
        $this->slack = $slack;
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
        $options = [];
        if (! empty($payload['icon_url'])) {
            $options['icon_url'] = $payload['icon_url'];
        }
        if (! empty($payload['blocks']) && is_array($payload['blocks'])) {
            $options['blocks'] = $payload['blocks'];
        }
        if (! empty($payload['attachments']) && is_array($payload['attachments'])) {
            $options['attachments'] = $payload['attachments'];
        }

        try {
            $result = $this->slack->post($channel, $text, $username, $options);
            Log::info('client_account.status_slack_sent', [
                'client_account_id' => $account->id,
                'slack_channel' => $result['channel'],
                'delivery' => $result['method'],
                'icon_url' => $payload['icon_url'] ?? null,
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
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{
     *     text: string,
     *     username: string,
     *     icon_url: string,
     *     blocks: array<int, array<string, mixed>>,
     *     attachments: array<int, array<string, mixed>>
     * }|null
     */
    public function buildMessagePayload(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): ?array {
        $newStatus = strtolower(trim($newStatus));

        if ($newStatus === ClientAccount::STATUS_PAUSED) {
            return $this->buildStatusPayload($account, 'Paused', 'Set Pause in Shiphero', self::ICON_PAUSED, $actor);
        }

        if ($newStatus === ClientAccount::STATUS_ACTIVE) {
            return $this->buildStatusPayload($account, 'Live', 'Set Live in Shiphero', self::ICON_LIVE, $actor);
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
     * @return array{
     *     text: string,
     *     username: string,
     *     icon_url: string,
     *     blocks: array<int, array<string, mixed>>,
     *     attachments: array<int, array<string, mixed>>
     * }
     */
    private function buildStatusPayload(
        ClientAccount $account,
        string $statusLabel,
        string $shipheroLinkLabel,
        string $iconFilename,
        ?User $actor
    ): array {
        $lines = [
            $this->companyLine($account).' is set to '.$statusLabel.'.',
        ];
        $this->appendActorLine($lines, $actor);
        $lines[] = '<'.self::SHIPHERO_3PL_URL.'|'.$shipheroLinkLabel.'>';

        $text = implode("\n", $lines);
        $iconUrl = $this->slackIconUrl($iconFilename);

        return [
            'text' => $text,
            'username' => self::USERNAME,
            'icon_url' => $iconUrl,
            'blocks' => $this->buildBlocks($text, $iconUrl, $statusLabel),
            'attachments' => [
                [
                    'fallback' => self::USERNAME,
                    'author_name' => self::USERNAME,
                    'author_icon' => $iconUrl,
                    'text' => $text,
                    'mrkdwn_in' => ['text'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBlocks(string $text, string $iconUrl, string $altText): array
    {
        return [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => self::USERNAME,
                    'emoji' => false,
                ],
            ],
            [
                'type' => 'image',
                'image_url' => $iconUrl,
                'alt_text' => $altText,
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

    private function slackIconUrl(string $filename): string
    {
        $base = rtrim((string) config('billing.slack.public_asset_base_url'), '/');
        if ($base === '') {
            $base = rtrim((string) config('crm.frontend_url'), '/');
        }
        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }

        if (str_starts_with($base, 'http://')) {
            $base = 'https://'.substr($base, 7);
        }

        return $base.'/images/slack/'.$filename;
    }
}
