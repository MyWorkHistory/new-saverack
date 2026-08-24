<?php

namespace Tests\Unit;

use App\Models\AdminBroadcastEmail;
use App\Models\User;
use App\Policies\AdminBroadcastEmailPolicy;
use App\Services\AdminBroadcastEmailService;
use Tests\TestCase;

final class AdminBroadcastEmailServiceTest extends TestCase
{
    public function test_signature_html_for_info_and_audi(): void
    {
        $service = app(AdminBroadcastEmailService::class);

        $info = $service->signatureHtml('info@saverack.com');
        $this->assertStringContainsString('<strong>Save Rack Fulfillment</strong>', $info);
        $this->assertStringContainsString('Client Updates &amp; Notifications', $info);

        $audi = $service->signatureHtml('audi@saverack.com');
        $this->assertStringContainsString('<strong>Save Rack Fulfillment</strong>', $audi);
        $this->assertStringContainsString('Audi K | Managing Partner', $audi);
        $this->assertStringContainsString('audi@saverack.com', $audi);
    }

    public function test_policy_denies_non_admin_allows_administrator(): void
    {
        $policy = new AdminBroadcastEmailPolicy();
        $broadcast = new AdminBroadcastEmail();

        $adminUser = $this->getMockBuilder(User::class)
            ->onlyMethods(['isAdministrator', 'isCrmOwner'])
            ->getMock();
        $adminUser->method('isAdministrator')->willReturn(true);
        $adminUser->method('isCrmOwner')->willReturn(false);

        $staffUser = $this->getMockBuilder(User::class)
            ->onlyMethods(['isAdministrator', 'isCrmOwner'])
            ->getMock();
        $staffUser->method('isAdministrator')->willReturn(false);
        $staffUser->method('isCrmOwner')->willReturn(false);

        $this->assertTrue($policy->viewAny($adminUser));
        $this->assertTrue($policy->create($adminUser));
        $this->assertTrue($policy->delete($adminUser, $broadcast));

        $this->assertFalse($policy->viewAny($staffUser));
        $this->assertFalse($policy->create($staffUser));
        $this->assertFalse($policy->delete($staffUser, $broadcast));
    }

    public function test_from_options_include_info_and_audi(): void
    {
        $options = app(AdminBroadcastEmailService::class)->fromOptions();
        $addresses = array_column($options, 'address');

        $this->assertContains('info@saverack.com', $addresses);
        $this->assertContains('audi@saverack.com', $addresses);
    }
}
