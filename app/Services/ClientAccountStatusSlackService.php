<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClientAccountStatusSlackService
{
    private const SHIPHERO_3PL_URL = 'https://app.shiphero.com/3pl';

    private const USERNAME = 'Shipping Status Update';

    private const COLOR_LIVE = '#2e7d32';

    private const COLOR_PAUSED = '#d32f2f';

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
        $iconUrl = (string) ($payload['icon_url'] ?? '');
        $isLive = $newStatus === ClientAccount::STATUS_ACTIVE;
        $options = $this->deliveryOptions($text, $username, $iconUrl, $isLive);

        if ($iconUrl !== '') {
            $this->logIconUrlReachability($iconUrl, (int) $account->id);
        }

        try {
            $result = $this->slack->post(
                $channel,
                (string) ($options['text'] ?? $text),
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
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Webhook: attachment color + PNG thumb_url (works without bot).
     * Bot: same attachment plus icon_url avatar.
     *
     * @return array{text: string, username: string, slack: array<string, mixed>}
     */
    private function deliveryOptions(string $text, string $username, string $iconUrl, bool $isLive): array
    {
        $slack = [
            'attachments' => [
                $this->buildAttachment($text, $iconUrl, $isLive),
            ],
        ];

        if ($this->slack->hasBotToken()) {
            $slack['customize_identity'] = true;
            $slack['prefer_bot'] = true;
            if ($iconUrl !== '') {
                $slack['icon_url'] = $iconUrl;
            }
        }

        return [
            'text' => '',
            'username' => $username,
            'slack' => $slack,
        ];
    }

    /**
     * @return array{color: string, fallback: string, text: string, mrkdwn_in: array<int, string>, thumb_url: string}
     */
    private function buildAttachment(string $text, string $iconUrl, bool $isLive): array
    {
        $attachment = [
            'color' => $isLive ? self::COLOR_LIVE : self::COLOR_PAUSED,
            'fallback' => $text,
            'text' => $text,
            'mrkdwn_in' => ['text'],
        ];

        if ($iconUrl !== '') {
            $attachment['thumb_url'] = $iconUrl;
        }

        return $attachment;
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
            return $this->buildStatusPayload($account, 'Paused', 'Set Pause in Shiphero', $this->iconUrls->pausedUrl(), $actor);
        }

        if ($newStatus === ClientAccount::STATUS_ACTIVE) {
            return $this->buildStatusPayload($account, 'Live', 'Set Live in Shiphero', $this->iconUrls->liveUrl(), $actor);
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
        string $iconUrl,
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
            'icon_url' => $iconUrl,
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

    private function logIconUrlReachability(string $iconUrl, int $clientAccountId): void
    {
        if ($iconUrl === '') {
            return;
        }

        try {
            $response = Http::timeout(5)->head($iconUrl);
            if (! $response->successful()) {
                $response = Http::timeout(5)->get($iconUrl);
            }

            $contentType = strtolower(trim((string) $response->header('Content-Type')));
            $ok = $response->successful() && str_contains($contentType, 'image');

            if ($ok) {
                return;
            }

            Log::warning('client_account.status_slack_icon_unreachable', [
                'client_account_id' => $clientAccountId,
                'icon_url' => $iconUrl,
                'http_status' => $response->status(),
                'content_type' => $contentType !== '' ? $contentType : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('client_account.status_slack_icon_unreachable', [
                'client_account_id' => $clientAccountId,
                'icon_url' => $iconUrl,
                'http_status' => null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
