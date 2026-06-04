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
     * Native bot header: username + icon_url avatar, body in text. No Block Kit.
     *
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
            $slack['bot_only'] = true;
        }

        return [
            'username' => $username,
            'slack' => $slack,
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     shipheroLinkLabel: string,
     *     iconUrl: string,
     *     iconUrlFallbacks: array<int, string>
     * }|null
     */
    private function statusNotificationConfig(string $newStatus): ?array
    {
        $newStatus = strtolower(trim($newStatus));

        if ($newStatus === ClientAccount::STATUS_PAUSED) {
            return [
                'label' => 'Paused',
                'shipheroLinkLabel' => 'Set Pause in Shiphero',
                'iconUrl' => $this->iconUrls->pausedThumbUrl(),
                'iconUrlFallbacks' => [$this->iconUrls->pausedUrl()],
            ];
        }

        if ($newStatus === ClientAccount::STATUS_ACTIVE) {
            return [
                'label' => 'Live',
                'shipheroLinkLabel' => 'Set Live in Shiphero',
                'iconUrl' => $this->iconUrls->liveThumbUrl(),
                'iconUrlFallbacks' => [$this->iconUrls->liveUrl()],
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     text: string,
     *     username: string,
     *     icon_url: string,
     *     icon_url_fallbacks: array<int, string>
     * }|null
     */
    public function buildMessagePayload(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): ?array {
        $config = $this->statusNotificationConfig($newStatus);
        if ($config === null) {
            return null;
        }

        $payload = $this->buildStatusPayload(
            $account,
            $config['label'],
            $config['shipheroLinkLabel'],
            $actor
        );
        $payload['icon_url'] = $config['iconUrl'];
        $payload['icon_url_fallbacks'] = $config['iconUrlFallbacks'];

        return $payload;
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
     * @return array{text: string, username: string}
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
