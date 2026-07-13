<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyPortalUserLinkResolver;
use Tests\TestCase;

final class LegacyPortalUserLinkResolverTest extends TestCase
{
    public function test_linked_customer_ids_merge_map_and_users_column(): void
    {
        $map = [
            64 => [118],
            296 => [200, 201],
        ];

        $row = (object) [
            'id' => 296,
            'customers' => '201, 202',
        ];

        $ids = LegacyPortalUserLinkResolver::linkedCustomerIdsForUser(296, $map, $row);

        $this->assertSame([118], LegacyPortalUserLinkResolver::linkedCustomerIdsForUser(64, $map, (object) ['id' => 64]));
        $this->assertEqualsCanonicalizing([200, 201, 202], $ids);
    }
}
