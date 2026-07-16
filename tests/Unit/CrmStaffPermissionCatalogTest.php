<?php

namespace Tests\Unit;

use App\Support\CrmStaffPermissionCatalog;
use PHPUnit\Framework\TestCase;

class CrmStaffPermissionCatalogTest extends TestCase
{
    public function test_matrix_includes_receiving_subpages(): void
    {
        $keys = CrmStaffPermissionCatalog::matrixEditableKeys();

        $this->assertContains('receiving_asn.view', $keys);
        $this->assertContains('receiving_asn.update', $keys);
        $this->assertContains('receiving_put_away.view', $keys);
        $this->assertContains('receiving_put_away.update', $keys);
        $this->assertNotContains('receiving.view', $keys);
        $this->assertNotContains('receiving.update', $keys);
    }

    public function test_matrix_includes_orders_and_billing_subpages(): void
    {
        $keys = CrmStaffPermissionCatalog::matrixEditableKeys();

        $this->assertContains('orders_search.view', $keys);
        $this->assertContains('orders_wholesale.update', $keys);
        $this->assertContains('billing_asn_bills.view', $keys);
        $this->assertContains('inventory_restock.view', $keys);
        $this->assertContains('returns_bins.update', $keys);
        $this->assertContains('resources_tutorials.view', $keys);
        $this->assertNotContains('orders.view', $keys);
        $this->assertNotContains('billing.view', $keys);
    }

    public function test_legacy_expand_and_grants(): void
    {
        $expanded = CrmStaffPermissionCatalog::expandLegacyKey('receiving.view');
        $this->assertContains('receiving_asn.view', $expanded);
        $this->assertContains('receiving_put_away.view', $expanded);

        $this->assertTrue(CrmStaffPermissionCatalog::grants(
            ['receiving_asn.view'],
            'receiving.view'
        ));
        $this->assertTrue(CrmStaffPermissionCatalog::grants(
            ['receiving.view'],
            'receiving_asn.view'
        ));
        $this->assertFalse(CrmStaffPermissionCatalog::grants(
            ['receiving_asn.view'],
            'receiving_put_away.view'
        ));
    }
}
