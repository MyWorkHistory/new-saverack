<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use App\Support\CrmUrls;
use Illuminate\Support\Facades\Log;

class ClientAccountStatusSlackService
{
    private const SHIPHERO_3PL_URL = 'https://app.shiphero.com/3pl';

    private const TRUCK_EMOJI = ':truck:';

    /** @var SlackDeliveryService */
    protected $slack;

    public function __construct(SlackDeliveryService $slack)
    {
        $this->slack = $slack;
    }

    /**
     * Post to the account in-house Slack channel when CRM status changes.
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

        $channel = $this->slack->channelFromInHouseSlack($account->in_house_slack);
        if ($channel === null || $channel === '') {
            Log::info('client_account.status_slack_skipped', [
                'client_account_id' => $account->id,
                'reason' => 'no_in_house_slack',
            ]);

            return;
        }

        $payload = $this->buildMessagePayload($account, $oldStatus, $newStatus, $actor);
        $text = (string) ($payload['text'] ?? '');
        $username = (string) ($payload['username'] ?? 'Account Status');
        $options = [];
        if (! empty($payload['icon_emoji'])) {
            $options['icon_emoji'] = $payload['icon_emoji'];
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
     * @return array{text: string, username?: string, icon_emoji?: string, attachments?: array<int, array<string, mixed>>}
     */
    public function buildMessagePayload(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): array {
        $newStatus = strtolower(trim($newStatus));

        if ($newStatus === ClientAccount::STATUS_PAUSED) {
            return $this->buildPausedPayload($account, $actor);
        }

        if ($newStatus === ClientAccount::STATUS_ACTIVE) {
            return $this->buildLivePayload($account, $actor);
        }

        return $this->buildGenericPayload($account, $oldStatus, $newStatus, $actor);
    }

    public function buildMessageText(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): string {
        return (string) ($this->buildMessagePayload($account, $oldStatus, $newStatus, $actor)['text'] ?? '');
    }

    /**
     * @return array{text: string, username: string, icon_emoji: string, attachments: array<int, array<string, mixed>>}
     */
    private function buildPausedPayload(ClientAccount $account, ?User $actor): array
    {
        $lines = [
            $this->companyLine($account),
            'Please pause this account for shipments.',
            '<'.self::SHIPHERO_3PL_URL.'|Pause in ShipHero>',
        ];
        $this->appendActorLine($lines, $actor);
        $lines[] = '<'.CrmUrls::clientAccountStaffUrl((int) $account->id).'|View Account>';

        return [
            'text' => implode("\n", $lines),
            'username' => 'Account Paused',
            'icon_emoji' => self::TRUCK_EMOJI,
            'attachments' => [
                [
                    'color' => 'd32f2f',
                    'fallback' => 'Account Paused',
                ],
            ],
        ];
    }

    /**
     * @return array{text: string, username: string, icon_emoji: string, attachments: array<int, array<string, mixed>>}
     */
    private function buildLivePayload(ClientAccount $account, ?User $actor): array
    {
        $lines = [
            $this->companyLine($account),
            'Please set this account live for shipments.',
            '<'.self::SHIPHERO_3PL_URL.'|Pause in ShipHero>',
        ];
        $this->appendActorLine($lines, $actor);
        $lines[] = '<'.CrmUrls::clientAccountStaffUrl((int) $account->id).'|View Account>';

        return [
            'text' => implode("\n", $lines),
            'username' => 'Account Live',
            'icon_emoji' => self::TRUCK_EMOJI,
            'attachments' => [
                [
                    'color' => '2e7d32',
                    'fallback' => 'Account Live',
                ],
            ],
        ];
    }

    /**
     * @return array{text: string, username: string}
     */
    private function buildGenericPayload(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor
    ): array {
        $company = $this->companyLine($account);

        $lines = [
            sprintf(
                'Account status changed: %s — %s → %s',
                $company,
                $this->statusLabel($oldStatus),
                $this->statusLabel($newStatus)
            ),
        ];
        $this->appendActorLine($lines, $actor);
        $lines[] = '<'.CrmUrls::clientAccountStaffUrl((int) $account->id).'|View Account>';

        return [
            'text' => implode("\n", $lines),
            'username' => 'Account Status',
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

    private function statusLabel(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return 'Unknown';
        }

        return ucfirst($status);
    }
}
