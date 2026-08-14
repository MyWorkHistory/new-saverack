<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\ShipHeroInventoryProductIndex;
use App\Services\ShipHeroInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipHeroSkuAccountLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_lookup_for_sku_is_scoped_to_client_account(): void
    {
        $leverify = ClientAccount::query()->create([
            'company_name' => 'Leverify LLC',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'cust-leverify',
        ]);
        $other = ClientAccount::query()->create([
            'company_name' => 'Other Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'shiphero_customer_account_id' => 'cust-other',
        ]);

        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $other->id,
            'shiphero_customer_account_id' => 'cust-other',
            'sku' => 'DUP-SKU',
            'sku_search' => 'dup-sku',
            'synced_at' => now(),
        ]);
        // Newer sync on other account would previously win a SKU-only lookup.
        ShipHeroInventoryProductIndex::query()->create([
            'client_account_id' => $leverify->id,
            'shiphero_customer_account_id' => 'cust-leverify',
            'sku' => 'DUP-SKU',
            'sku_search' => 'dup-sku',
            'synced_at' => now()->subDay(),
        ]);

        /** @var ShipHeroInventoryService $service */
        $service = app(ShipHeroInventoryService::class);

        $this->assertSame(
            'cust-leverify',
            $service->lookupShipHeroCustomerAccountIdForSkuOnAccount('DUP-SKU', (int) $leverify->id)
        );
        $this->assertSame(
            'cust-other',
            $service->lookupShipHeroCustomerAccountIdForSkuOnAccount('DUP-SKU', (int) $other->id)
        );
        $this->assertSame(
            'cust-leverify',
            $service->lookupShipHeroCustomerAccountIdForSku('DUP-SKU', (int) $leverify->id)
        );
    }
}
