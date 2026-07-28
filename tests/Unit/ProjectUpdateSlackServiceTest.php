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
        ]);
    }

    public function test_build_message_payload_for_in_progress(): void
    {
        $project = new Project(['pid' => 'P-1009']);
        $project->id = 11;

        $payload = app(ProjectUpdateSlackService::class)->buildMessagePayload(
            $project,
            Project::STATUS_IN_PROGRESS
        );

        $this->assertSame('Project Update', $payload['username']);
        $this->assertSame(
            "Project #P-1009 is in-progress\n<https://app.saverack.com/admin/clients/projects/11|View Project>",
            $payload['text']
        );
    }

    public function test_build_message_payload_for_completed(): void
    {
        $project = new Project(['pid' => 'P-1009']);
        $project->id = 11;

        $payload = app(ProjectUpdateSlackService::class)->buildMessagePayload(
            $project,
            Project::STATUS_COMPLETED
        );

        $this->assertSame('Project Update', $payload['username']);
        $this->assertSame(
            "Project #P-1009 is completed\n<https://app.saverack.com/admin/clients/projects/11|View Project>",
            $payload['text']
        );
    }

    public function test_notify_posts_for_in_progress(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(true);
        $slack->method('channelFromInHouseSlack')
            ->with('demo-co')
            ->willReturn('#demo-co');
        $slack->expects($this->once())
            ->method('post')
            ->with(
                '#demo-co',
                $this->callback(function ($text) {
                    return str_contains((string) $text, 'Project #P-1009 is in-progress')
                        && str_contains((string) $text, 'View Project')
                        && str_contains((string) $text, '/admin/clients/projects/11');
                }),
                'Project Update',
                $this->anything()
            )
            ->willReturn(['method' => 'bot', 'channel' => '#demo-co', 'ts' => '1.0']);

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->once()->andReturnNull();

        $project = new Project(['pid' => 'P-1009']);
        $project->id = 11;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectUpdateSlackService::class)->notifyStatusChange($project, Project::STATUS_IN_PROGRESS);
    }

    public function test_notify_skips_pending(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->expects($this->never())->method('post');

        $this->app->instance(SlackDeliveryService::class, $slack);

        $project = new Project(['pid' => 'P-1009']);
        $project->id = 11;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectUpdateSlackService::class)->notifyStatusChange($project, Project::STATUS_PENDING);
        $this->assertTrue(true);
    }

    public function test_notify_skips_when_no_in_house_slack(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('channelFromInHouseSlack')->willReturn(null);
        $slack->expects($this->never())->method('post');

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->once()->andReturnNull();

        $project = new Project(['pid' => 'P-1009']);
        $project->id = 11;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => null,
        ]));

        app(ProjectUpdateSlackService::class)->notifyStatusChange($project, Project::STATUS_COMPLETED);
    }

    public function test_notify_failure_does_not_throw(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(false);
        $slack->method('channelFromInHouseSlack')->willReturn('#demo-co');
        $slack->method('post')->willThrowException(new \RuntimeException('slack down'));

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('warning')->once()->andReturnNull();

        $project = new Project(['pid' => 'P-1009']);
        $project->id = 11;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectUpdateSlackService::class)->notifyStatusChange($project, Project::STATUS_COMPLETED);
        $this->assertTrue(true);
    }
}
