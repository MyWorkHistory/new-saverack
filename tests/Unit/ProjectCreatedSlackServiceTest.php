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
            'projects.slack_channel' => '#projects',
        ]);
    }

    public function test_build_message_payload_matches_expected_copy(): void
    {
        $project = new Project(['pid' => 'P-1015', 'name' => 'Warehouse Refresh']);
        $project->id = 42;

        $payload = app(ProjectCreatedSlackService::class)->buildMessagePayload($project);

        $this->assertSame('Project Created For Quote', $payload['username']);
        $this->assertSame(
            "Project #P-1015 is created\nName: Warehouse Refresh\n<https://app.saverack.com/admin/clients/projects/42|View Project>",
            $payload['text']
        );
    }

    public function test_build_message_payload_falls_back_name_to_pid(): void
    {
        $project = new Project(['pid' => 'P-1015', 'name' => '']);
        $project->id = 42;

        $payload = app(ProjectCreatedSlackService::class)->buildMessagePayload($project);

        $this->assertStringContainsString("Name: P-1015\n", $payload['text']);
    }

    public function test_notify_posts_to_account_and_projects_channels(): void
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
                $this->assertSame('Project Created For Quote', $username);
                $this->assertStringContainsString('is created', (string) $text);
                $this->assertStringContainsString('Name:', (string) $text);
                $this->assertStringContainsString('View Project', (string) $text);

                return ['method' => 'bot', 'channel' => $channel, 'ts' => '1.0'];
            });

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->twice()->andReturnNull();

        $project = new Project(['pid' => 'P-1015', 'name' => 'Demo Project']);
        $project->id = 42;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectCreatedSlackService::class)->notify($project);

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
                'Project Created For Quote',
                $this->anything()
            )
            ->willReturn(['method' => 'bot', 'channel' => '#projects', 'ts' => '1.0']);

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('info')->once()->andReturnNull();

        $project = new Project(['pid' => 'P-1015', 'name' => 'Demo Project']);
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
        $slack->method('normalizeChannelName')->willReturn('#projects');
        $slack->method('post')->willThrowException(new \RuntimeException('slack down'));

        $this->app->instance(SlackDeliveryService::class, $slack);

        Log::shouldReceive('warning')->atLeast()->once()->andReturnNull();

        $project = new Project(['pid' => 'P-1015', 'name' => 'Demo Project']);
        $project->id = 42;
        $project->setRelation('clientAccount', new ClientAccount([
            'company_name' => 'Demo Co',
            'in_house_slack' => 'demo-co',
        ]));

        app(ProjectCreatedSlackService::class)->notify($project);
        $this->assertTrue(true);
    }
}
