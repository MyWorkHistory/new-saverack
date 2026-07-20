<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\Project;
use App\Support\CrmUrls;
use Illuminate\Support\Facades\Log;

class ProjectCreatedSlackService
{
    private const USERNAME = 'Project Created';

    /** @var SlackDeliveryService */
    protected $slack;

    public function __construct(SlackDeliveryService $slack)
    {
        $this->slack = $slack;
    }

    /**
     * Post to the account in-house Slack channel when a project is created.
     * Failures are logged and do not block project creation.
     */
    public function notify(Project $project): void
    {
        $project->loadMissing('clientAccount');
        $account = $project->clientAccount;
        if (! $account instanceof ClientAccount) {
            Log::info('project.created_slack_skipped', [
                'project_id' => $project->id,
                'reason' => 'no_client_account',
            ]);

            return;
        }

        $payload = $this->buildMessagePayload($project);
        $channel = $this->slack->channelFromInHouseSlack($account->in_house_slack);
        if ($channel === null || $channel === '') {
            Log::info('project.created_slack_skipped', [
                'project_id' => $project->id,
                'client_account_id' => $account->id,
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
            Log::info('project.created_slack_sent', [
                'project_id' => $project->id,
                'client_account_id' => $account->id,
                'slack_channel' => $result['channel'],
                'delivery' => $result['method'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('project.created_slack_failed', [
                'project_id' => $project->id,
                'client_account_id' => $account->id,
                'slack_channel' => $channel,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{text: string, username: string}
     */
    public function buildMessagePayload(Project $project): array
    {
        $pid = trim((string) ($project->pid ?? ''));
        $label = $pid !== '' ? 'Project #'.$pid : 'Project #'.$project->id;
        $url = CrmUrls::projectStaffUrl((int) $project->id);

        $lines = [
            $label.' has been created',
            '<'.$url.'|View Project>',
        ];

        return [
            'text' => implode("\n", $lines),
            'username' => self::USERNAME,
        ];
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
