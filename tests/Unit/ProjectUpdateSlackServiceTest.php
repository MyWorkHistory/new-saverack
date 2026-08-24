<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\Project;
use App\Services\ProjectUpdateSlackService;
use App\Services\SlackDeliveryService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class ProjectUpdateSlackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.saverack.com',
            'crm.frontend_url' => 'https://app.saverack.com',
            'projects.slack_channel' => '#projects',
        ]);
    }

    public function test_build_message_payload_for_pending(): void
    {
        $project = new Project(['pid' => 'P-1015', 'name' => 'Quote Ready']);
        $project->id = 11;

        $payload = app(ProjectUpdateSlackService::class)->buildMessagePayload(
            $project,
            Project::STATUS_PENDING
        );

        $this->assertSame('Project Update', $payload['username']);
        $this->assertSame(
            "Project #P-1015 is quoted\nName: Quote Ready\n<https://app.saverack.com/admin/clients/projects/11|View Project>",
            $payload['text']
        );
    }

    public function test_build_message_payload_for_in_progress(): void
    {
        $project = new Project(['pid' => 'P-1015', 'name' => 'Active Work']);
        $project->id = 11;

        $payload = app(ProjectUpdateSlackService::class)->buildMessagePayload(
            $project,
            Project::STATUS_IN_PROGRESS
        );

        $this->assertSame('Project Update', $payload['username']);
        $this->assertSame(
            "Project #P-1015 is in progress\nName: Active Work\n<https://app.saverack.com/admin/clients/projects/11|View Project>",
            $payload['text']
        );
    }

    public function test_build_message_payload_for_review(): void
    {
        $project = new Project(['pid' => 'P-1015', 'name' => 'Needs Review']);
        $project->id = 11;

        $payload = app(ProjectUpdateSlackService::class)->buildMessagePayload(
            $project,
            Project::STATUS_REVIEW
        );

        $this->assertSame(
            "Project #P-1015 is ready for review\nName: Needs Review\n<https://app.saverack.com/admin/clients/projects/11|View Project>",
            $payload['text']
        );
    }

    public function test_build_message_payload_for_completed(): void
    {
        $project = new Project(['pid' => 'P-1015', 'name' => 'Done Deal']);
        $project->id = 11;

        $payload = app(ProjectUpdateSlackService::class)->buildMessagePayload(
            $project,
            Project::STATUS_COMPLETED
        );

        $this->assertSame(
            "Project #P-1015 is completed\nName: Done Deal\n<https://app.saverack.com/admin/clients/projects/11|View Project>",
            $payload['text']
        );
    }

    public function test_notify_posts_to_account_and_projects_channels_for_pending(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(true);
        $slack->method('channelFromInHouseSlack')
            ->with('demo-co')
            ->willReturn('#demo-co');
        $slack->method('normalizeChannelName')
            ->with('projects')
            ->willReturn('#projects');

        $posted = [];
        $slack->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(function ($channel, $text, $username) use (&$posted) {
                $posted[] = $channel;
                $this->assertSame('Project Update', $username);
                $this->assertStringContainsString('is quoted', (string) $text);
                $this->assertStringContainsString('Name:', (string) $text);

                return ['method' => 'bot', 'channel' => $channel, 'ts' => '1.0'];
            });

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->twice()->andReturnNull();

        $project = new Project(['pid' => 'P-1015', 'name' => 'Demo Project']);
        $project->id = 11;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectUpdateSlackService::class)->notifyStatusChange($project, Project::STATUS_PENDING);

        $this->assertSame(['#demo-co', '#projects'], $posted);
    }

    public function test_notify_posts_to_projects_when_no_in_house_slack(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(true);
        $slack->method('channelFromInHouseSlack')->willReturn(null);
        $slack->method('normalizeChannelName')
            ->with('projects')
            ->willReturn('#projects');
        $slack->expects($this->once())
            ->method('post')
            ->with(
                '#projects',
                $this->anything(),
                'Project Update',
                $this->anything()
            )
            ->willReturn(['method' => 'bot', 'channel' => '#projects', 'ts' => '1.0']);

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->once()->andReturnNull();

        $project = new Project(['pid' => 'P-1015', 'name' => 'Demo Project']);
        $project->id = 11;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => null,
        ]));

        app(ProjectUpdateSlackService::class)->notifyStatusChange($project, Project::STATUS_PENDING);
    }

    public function test_notify_skips_draft(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->expects($this->never())->method('post');

        $this->app->instance(SlackDeliveryService::class, $slack);

        $project = new Project(['pid' => 'P-1015', 'name' => 'Demo Project']);
        $project->id = 11;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectUpdateSlackService::class)->notifyStatusChange($project, Project::STATUS_DRAFT);
        $this->assertTrue(true);
    }

    public function test_notify_failure_does_not_throw(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(false);
        $slack->method('channelFromInHouseSlack')->willReturn('#demo-co');
        $slack->method('normalizeChannelName')->willReturn('#projects');
        $slack->method('post')->willThrowException(new \RuntimeException('slack down'));

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('warning')->atLeast()->once()->andReturnNull();

        $project = new Project(['pid' => 'P-1015', 'name' => 'Demo Project']);
        $project->id = 11;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectUpdateSlackService::class)->notifyStatusChange($project, Project::STATUS_COMPLETED);
        $this->assertTrue(true);
    }
}
