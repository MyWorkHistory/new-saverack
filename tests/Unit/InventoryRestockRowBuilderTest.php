<?php

namespace Tests\Unit;

use App\Support\InventoryRestockRowBuilder;
use PHPUnit\Framework\TestCase;

class InventoryRestockRowBuilderTest extends TestCase
{
    public function test_includes_sku_when_pick_qty_low_and_backstock_present(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-1', 'Product One', null, [
            ['location_name' => 'A-01', 'quantity' => 1, 'pickable' => true],
            ['location_name' => 'OS-1', 'quantity' => 10, 'pickable' => false],
            ['location_name' => 'OS-2', 'quantity' => 25, 'pickable' => false],
        ], 2);

        $this->assertNotNull($row);
        $this->assertSame('SKU-1', $row['sku']);
        $this->assertSame('A-01', $row['pick_location']);
        $this->assertSame(1, $row['pick_qty']);
        $this->assertSame(35, $row['backstock_qty']);
        $this->assertSame('OS-1 (10)', $row['backstock_location']);
    }

    public function test_includes_sku_when_pick_qty_at_max_threshold(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-1', 'Product One', null, [
            ['location_name' => 'A-01', 'quantity' => 2, 'pickable' => true],
            ['location_name' => 'OS-1', 'quantity' => 5, 'pickable' => false],
        ], 2);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['pick_qty']);
    }

    public function test_includes_sku_when_pick_qty_zero_and_backstock_present(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-2', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 0, 'pickable' => true],
            ['location_name' => 'OS-1', 'quantity' => 50, 'pickable' => false],
        ], 2);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['pick_qty']);
        $this->assertSame('—', $row['pick_location']);
        $this->assertSame(50, $row['backstock_qty']);
    }

    public function test_excludes_when_pick_qty_above_threshold(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-3', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 3, 'pickable' => true],
            ['location_name' => 'OS-1', 'quantity' => 5, 'pickable' => false],
        ], 2);

        $this->assertNull($row);
    }

    public function test_excludes_when_no_non_pickable_inventory(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-4', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 1, 'pickable' => true],
        ], 2);

        $this->assertNull($row);
    }

    public function test_excludes_when_pickable_null_and_no_pickable_rows(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-5', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 1, 'pickable' => null],
            ['location_name' => 'OS-1', 'quantity' => 4, 'pickable' => false],
        ], 2);

        $this->assertNull($row);
    }

    public function test_excludes_when_no_locations(): void
    {
        $this->assertNull(InventoryRestockRowBuilder::buildRow('SKU-8', 'Product', null, [], 2));
    }

    public function test_sums_multiple_pickable_locations(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-6', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 1, 'pickable' => true],
            ['location_name' => 'A-02', 'quantity' => 1, 'pickable' => true],
            ['location_name' => 'OS-1', 'quantity' => 3, 'pickable' => false],
        ], 2);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['pick_qty']);
        $this->assertStringContainsString('A-01', $row['pick_location']);
        $this->assertStringContainsString('A-02', $row['pick_location']);
    }

    public function test_excludes_when_pickable_sum_exceeds_threshold(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-7', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 1, 'pickable' => true],
            ['location_name' => 'A-02', 'quantity' => 2, 'pickable' => true],
            ['location_name' => 'OS-1', 'quantity' => 3, 'pickable' => false],
        ], 2);

        $this->assertNull($row);
    }
}
