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
        $iconUrl = (string) ($payload['icon_url'] ?? '');
        $iconAlt = (string) ($payload['icon_alt'] ?? 'Shipping status');
        $options = $this->deliveryOptions($text, $username, $iconUrl, $iconAlt);

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
     * Webhook: plain text + username + icon_url (no blocks — Slack rejects the whole
     * message when a block image URL is unreachable).
     * Bot-only: attachment author row for the truck icon.
     *
     * @return array{text: string, username: string, slack: array<string, mixed>}
     */
    private function deliveryOptions(string $text, string $username, string $iconUrl, string $iconAlt): array
    {
        unset($iconAlt);

        $slack = [];
        if ($iconUrl !== '') {
            $slack['icon_url'] = $iconUrl;
        }

        $preferBot = (bool) config('billing.slack.status_prefer_bot', false);
        if ($preferBot && $this->slack->hasBotToken()) {
            $slack['prefer_bot'] = true;

            return [
                'text' => $text,
                'username' => 'Save Rack',
                'slack' => $slack,
            ];
        }

        if ($this->slack->usesIncomingWebhook()) {
            return [
                'text' => $text,
                'username' => $username,
                'slack' => $slack,
            ];
        }

        if ($iconUrl !== '') {
            $slack['attachments'] = [
                [
                    'fallback' => $username."\n".$text,
                    'author_name' => $username,
                    'author_icon' => $iconUrl,
                    'text' => $text,
                    'mrkdwn_in' => ['text'],
                ],
            ];
        }

        return [
            'text' => $text,
            'username' => 'Save Rack',
            'slack' => $slack,
        ];
    }

    /**
     * @return array{text: string, username: string, icon_url: string, icon_alt: string}|null
     */
    public function buildMessagePayload(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): ?array {
        $newStatus = strtolower(trim($newStatus));

        if ($newStatus === ClientAccount::STATUS_PAUSED) {
            return $this->buildStatusPayload($account, 'Paused', 'Set Pause in Shiphero', self::ICON_PAUSED, 'Shipping paused', $actor);
        }

        if ($newStatus === ClientAccount::STATUS_ACTIVE) {
            return $this->buildStatusPayload($account, 'Live', 'Set Live in Shiphero', self::ICON_LIVE, 'Shipping live', $actor);
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
     * @return array{text: string, username: string, icon_url: string, icon_alt: string}
     */
    private function buildStatusPayload(
        ClientAccount $account,
        string $statusLabel,
        string $shipheroLinkLabel,
        string $iconFilename,
        string $iconAlt,
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
            'icon_url' => $this->slackIconUrl($iconFilename),
            'icon_alt' => $iconAlt,
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
        if ($filename === self::ICON_LIVE) {
            $explicit = config('billing.slack.status_icon_live_url');
        } else {
            $explicit = config('billing.slack.status_icon_paused_url');
        }
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $base = rtrim((string) config('billing.slack.public_asset_base_url'), '/');
        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }
        if ($base === '') {
            $base = rtrim((string) config('crm.frontend_url'), '/');
        }

        if (str_starts_with($base, 'http://')) {
            $base = 'https://'.substr($base, 7);
        }

        return $base.'/images/slack/'.$filename;
    }

    private function logIconUrlReachability(string $iconUrl, int $clientAccountId): void
    {
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
