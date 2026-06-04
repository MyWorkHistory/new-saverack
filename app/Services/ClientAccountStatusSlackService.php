<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\User;
use App\Support\CrmUrls;
use Illuminate\Support\Facades\Log;

class ClientAccountStatusSlackService
{
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

        $text = $this->buildMessageText($account, $oldStatus, $newStatus, $actor);

        try {
            $result = $this->slack->post($channel, $text, 'Account Status');
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

    public function buildMessageText(
        ClientAccount $account,
        string $oldStatus,
        string $newStatus,
        ?User $actor = null
    ): string {
        $company = trim((string) $account->company_name);
        if ($company === '') {
            $company = 'Account #'.$account->id;
        }

        $lines = [
            sprintf(
                'Account status changed: %s — %s → %s',
                $company,
                $this->statusLabel($oldStatus),
                $this->statusLabel($newStatus)
            ),
        ];

        if ($actor !== null) {
            $actorName = trim((string) $actor->name);
            if ($actorName !== '') {
                $lines[] = 'Updated by: '.$actorName;
            }
        }

        $lines[] = '<'.CrmUrls::clientAccountStaffUrl((int) $account->id).'|View Account>';

        return implode("\n", $lines);
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
