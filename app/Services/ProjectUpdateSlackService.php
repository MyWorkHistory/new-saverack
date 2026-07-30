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
     * Post to the account in-house Slack channel and the shared #projects channel
     * when project status changes (except Draft). Failures are logged and do not block.
     */
    public function notifyStatusChange(Project $project, string $status): void
    {
        if ($status === Project::STATUS_DRAFT) {
            return;
        }

        if (! in_array($status, Project::STATUSES, true)) {
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
        if ($payload === null) {
            return;
        }

        $channels = $this->resolveChannels($account);
        if ($channels === []) {
            Log::info('project.status_slack_skipped', [
                'project_id' => $project->id,
                'client_account_id' => $account->id,
                'status' => $status,
                'reason' => 'no_slack_channels',
            ]);

            return;
        }

        $text = (string) ($payload['text'] ?? '');
        $username = (string) ($payload['username'] ?? self::USERNAME);
        $options = $this->deliveryOptions($username);

        foreach ($channels as $channel) {
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
    }

    /**
     * @return array{text: string, username: string}|null
     */
    public function buildMessagePayload(Project $project, string $status): ?array
    {
        $body = $this->statusBody($project, $status);
        if ($body === null) {
            return null;
        }

        $url = CrmUrls::projectStaffUrl((int) $project->id);
        $lines = [
            $body,
            '<'.$url.'|View Project>',
        ];

        return [
            'text' => implode("\n", $lines),
            'username' => self::USERNAME,
        ];
    }

    private function statusBody(Project $project, string $status): ?string
    {
        $pid = trim((string) ($project->pid ?? ''));
        $label = $pid !== '' ? 'Project #'.$pid : 'Project #'.$project->id;

        switch ($status) {
            case Project::STATUS_PENDING:
                return $label.' has been quoted and is ready to start.';
            case Project::STATUS_IN_PROGRESS:
                return $label.' has currently in-progress';
            case Project::STATUS_REVIEW:
                return $label.' is ready for review';
            case Project::STATUS_COMPLETED:
                return $label.' is completed';
            default:
                return null;
        }
    }

    /**
     * @return list<string>
     */
    private function resolveChannels(ClientAccount $account): array
    {
        $channels = [];

        $accountChannel = $this->slack->channelFromInHouseSlack($account->in_house_slack);
        if ($accountChannel !== null && $accountChannel !== '') {
            $channels[] = $accountChannel;
        }

        $projectsChannel = trim((string) config('projects.slack_channel', '#projects'));
        if ($projectsChannel !== '') {
            if ($projectsChannel[0] !== '#' && ! preg_match('/^C[A-Z0-9]+$/i', $projectsChannel)) {
                $projectsChannel = '#'.$projectsChannel;
            }
            $channels[] = $projectsChannel;
        }

        return array_values(array_unique($channels));
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
