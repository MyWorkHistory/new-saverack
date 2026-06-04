<?php

namespace Tests\Unit;

use App\Services\ShipHeroClient;
use App\Services\ShipHeroCustomerAccountService;
use Tests\TestCase;

final class ShipHeroCustomerAccountProbeTest extends TestCase
{
    public function test_graphql_error_indicates_missing_mutation_field(): void
    {
        $client = $this->createMock(ShipHeroClient::class);
        $service = new ShipHeroCustomerAccountService($client);

        $method = new \ReflectionMethod($service, 'graphQlErrorIndicatesMissingField');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $service,
            'Cannot query field "customer_account_update" on type "Mutation".',
            'customer_account_update'
        ));
        $this->assertFalse($method->invoke(
            $service,
            'Field "customer_account_update" argument "data" of type "UpdateCustomerAccountInput!" is required, but it was not provided.',
            'customer_account_update'
        ));
    }

    public function test_hide_orders_mutation_candidates_is_non_empty(): void
    {
        $service = new ShipHeroCustomerAccountService($this->createMock(ShipHeroClient::class));

        $this->assertContains('customer_account_update', $service->hideOrdersMutationCandidates());
        $this->assertContains('hide_orders_from_app', $service->hideOrdersFieldCandidates());
    }
}
