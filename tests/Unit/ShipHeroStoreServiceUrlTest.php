<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroStoreService;
use Mockery;
use Tests\TestCase;

final class ShipHeroStoreServiceUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function service(): ShipHeroStoreService
    {
        return new ShipHeroStoreService(Mockery::mock(ShipHeroClient::class));
    }

    public function test_build_settings_url_requires_type_and_shop(): void
    {
        $svc = $this->service();

        $this->assertNull($svc->buildSettingsUrl(null, '31888'));
        $this->assertNull($svc->buildSettingsUrl('shopify', null));
        $this->assertNull($svc->buildSettingsUrl('shopify', ''));
        $this->assertNull($svc->buildSettingsUrl('api', '31888'));
        $this->assertSame(
            'https://app.shiphero.com/dashboard/stores/settings?type=shopify&shop=31888',
            $svc->buildSettingsUrl('shopify', '31888')
        );
        $this->assertSame(
            'https://app.shiphero.com/dashboard/stores/settings?type=amazon&shop=99',
            $svc->buildSettingsUrl('Amazon', '99')
        );
    }

    public function test_store_dedupe_key_prefers_legacy_id(): void
    {
        $svc = $this->service();

        $this->assertSame(
            'legacy:32363',
            $svc->storeDedupeKey([
                'legacy_id' => '32363',
                'shiphero_id' => 'U3RvcmU6MTIz',
                'shop_name' => 'example.myshopify.com',
            ])
        );
        $this->assertSame(
            'id:U3RvcmU6NDU2',
            $svc->storeDedupeKey([
                'legacy_id' => '',
                'shiphero_id' => 'U3RvcmU6NDU2',
                'shop_name' => 'Second Shop',
            ])
        );
    }
}
