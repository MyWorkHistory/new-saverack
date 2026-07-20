<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\Project;
use App\Services\ProjectCreatedSlackService;
use App\Services\SlackDeliveryService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class ProjectCreatedSlackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.saverack.com',
            'crm.frontend_url' => 'https://app.saverack.com',
        ]);
    }

    public function test_build_message_payload_matches_expected_copy(): void
    {
        $project = new Project(['pid' => '1001']);
        $project->id = 42;

        $payload = app(ProjectCreatedSlackService::class)->buildMessagePayload($project);

        $this->assertSame('Project Created', $payload['username']);
        $this->assertSame(
            "Project #1001 has been created\n<https://app.saverack.com/admin/clients/projects/42|View Project>",
            $payload['text']
        );
    }

    public function test_notify_posts_to_account_in_house_slack(): void
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
                    return str_contains((string) $text, 'Project #1001 has been created')
                        && str_contains((string) $text, 'View Project')
                        && str_contains((string) $text, '/admin/clients/projects/42');
                }),
                'Project Created',
                $this->anything()
            )
            ->willReturn(['method' => 'bot', 'channel' => '#demo-co', 'ts' => '1.0']);

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->once()->andReturnNull();

        $project = new Project(['pid' => '1001']);
        $project->id = 42;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectCreatedSlackService::class)->notify($project);
    }

    public function test_notify_skips_when_no_in_house_slack(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('channelFromInHouseSlack')->willReturn(null);
        $slack->expects($this->never())->method('post');

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->once()->andReturnNull();

        $project = new Project(['pid' => '1001']);
        $project->id = 42;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => null,
        ]));

        app(ProjectCreatedSlackService::class)->notify($project);
    }

    public function test_notify_failure_does_not_throw(): void
    {
        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(false);
        $slack->method('channelFromInHouseSlack')->willReturn('#demo-co');
        $slack->method('post')->willThrowException(new \RuntimeException('slack down'));

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('warning')->once()->andReturnNull();

        $project = new Project(['pid' => '1001']);
        $project->id = 42;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectCreatedSlackService::class)->notify($project);
        $this->assertTrue(true);
    }
}
