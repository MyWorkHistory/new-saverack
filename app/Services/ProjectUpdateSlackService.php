<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\Project;
use App\Support\CrmUrls;
use Illuminate\Support\Facades\Log;

class ProjectUpdateSlackService
{
    private const USERNAME = 'Project Update';

    /** @var SlackDeliveryService */
    protected $slack;

    public function __construct(SlackDeliveryService $slack)
    {
        $this->slack = $slack;
    }

    /**
     * Post to the account in-house Slack channel when a project becomes
     * in-progress or completed. Failures are logged and do not block the update.
     */
    public function notifyStatusChange(Project $project, string $status): void
    {
        if (! in_array($status, [Project::STATUS_IN_PROGRESS, Project::STATUS_COMPLETED], true)) {
            return;
        }

        $project->loadMissing('clientAccount');
        $account = $project->clientAccount;
        if (! $account instanceof ClientAccount) {
            Log::info('project.status_slack_skipped', [
                'project_id' => $project->id,
                'status' => $status,
                'reason' => 'no_client_account',
            ]);

            return;
        }

        $payload = $this->buildMessagePayload($project, $status);
        $channel = $this->slack->channelFromInHouseSlack($account->in_house_slack);
        if ($channel === null || $channel === '') {
            Log::info('project.status_slack_skipped', [
                'project_id' => $project->id,
                'client_account_id' => $account->id,
                'status' => $status,
                'reason' => 'no_in_house_slack',
            ]);

            return;
        }

        $text = (string) ($payload['text'] ?? '');
        $username = (string) ($payload['username'] ?? self::USERNAME);
        $options = $this->deliveryOptions($username);

        try {
            $result = $this->slack->post(
                $channel,
                $text,
                (string) ($options['username'] ?? $username),
                $options['slack'] ?? []
            );
            Log::info('project.status_slack_sent', [
                'project_id' => $project->id,
                'client_account_id' => $account->id,
                'status' => $status,
                'slack_channel' => $result['channel'],
                'delivery' => $result['method'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('project.status_slack_failed', [
                'project_id' => $project->id,
                'client_account_id' => $account->id,
                'status' => $status,
                'slack_channel' => $channel,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{text: string, username: string}
     */
    public function buildMessagePayload(Project $project, string $status): array
    {
        $pid = trim((string) ($project->pid ?? ''));
        $label = $pid !== '' ? 'Project #'.$pid : 'Project #'.$project->id;
        $url = CrmUrls::projectStaffUrl((int) $project->id);
        $statusPhrase = $this->statusPhrase($status);

        $lines = [
            $label.' is '.$statusPhrase,
            '<'.$url.'|View Project>',
        ];

        return [
            'text' => implode("\n", $lines),
            'username' => self::USERNAME,
        ];
    }

    private function statusPhrase(string $status): string
    {
        if ($status === Project::STATUS_IN_PROGRESS) {
            return 'in-progress';
        }

        if ($status === Project::STATUS_COMPLETED) {
            return 'completed';
        }

        return str_replace('_', '-', $status);
    }

    /**
     * @return array{username: string, slack: array<string, mixed>}
     */
    private function deliveryOptions(string $username): array
    {
        $slack = [];
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
