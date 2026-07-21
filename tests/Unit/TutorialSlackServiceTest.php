<?php

namespace Tests\Unit;

use App\Models\Tutorial;
use App\Services\SlackDeliveryService;
use App\Services\TutorialSlackService;
use Tests\TestCase;

class TutorialSlackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'crm.frontend_url' => 'https://app.saverack.com',
            'billing.slack.faq_channel' => '#faq',
        ]);
    }

    public function test_build_message_payload_matches_expected_copy(): void
    {
        $tutorial = new Tutorial([
            'title' => 'New Client Account Creating & Onboarding',
            'category' => Tutorial::CATEGORY_ACCOUNTS,
        ]);
        $tutorial->id = 5;

        $slack = $this->createMock(SlackDeliveryService::class);
        $service = new TutorialSlackService($slack);

        $this->assertSame([
            'text' => "New Client Account Creating & Onboarding\n"
                ."Category: Accounts\n"
                .'<https://app.saverack.com/admin/resources/tutorials/5|View Tutorial>',
            'username' => 'New Tutorial',
        ], $service->buildMessagePayload($tutorial));
    }

    public function test_send_posts_to_faq_with_new_tutorial_identity(): void
    {
        $tutorial = new Tutorial([
            'title' => 'New Client Account Creating & Onboarding',
            'category' => Tutorial::CATEGORY_ACCOUNTS,
        ]);
        $tutorial->id = 5;

        $slack = $this->createMock(SlackDeliveryService::class);
        $slack->method('hasBotToken')->willReturn(true);
        $slack->expects($this->once())
            ->method('post')
            ->with(
                '#faq',
                $this->callback(function ($text) {
                    return str_contains($text, 'New Client Account Creating & Onboarding')
                        && str_contains($text, 'Category: Accounts')
                        && str_contains($text, '|View Tutorial>');
                }),
                'New Tutorial',
                [
                    'customize_identity' => true,
                    'prefer_bot' => true,
                ]
            )
            ->willReturn(['method' => 'bot', 'channel' => '#faq', 'ts' => '1.0']);

        $service = new TutorialSlackService($slack);
        $result = $service->send($tutorial);

        $this->assertSame('#faq', $result['channel']);
    }
}
