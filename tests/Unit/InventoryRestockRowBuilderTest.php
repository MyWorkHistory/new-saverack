<?php

namespace Tests\Unit;

use App\Support\InventoryRestockRowBuilder;
use PHPUnit\Framework\TestCase;

class InventoryRestockRowBuilderTest extends TestCase
{
    public function test_includes_sku_when_pick_qty_low_and_backstock_present(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-1', 'Product One', 'https://img.test/a.jpg', [
            ['location_name' => 'A-01', 'quantity' => 1, 'pickable' => true],
            ['location_name' => 'OS-1', 'quantity' => 10, 'pickable' => false],
            ['location_name' => 'OS-2', 'quantity' => 25, 'pickable' => false],
        ]);

        $this->assertNotNull($row);
        $this->assertSame('SKU-1', $row['sku']);
        $this->assertSame('A-01', $row['pick_location']);
        $this->assertSame(1, $row['pick_qty']);
        $this->assertSame(35, $row['backstock_qty']);
        $this->assertSame('OS-1 (10)', $row['backstock_location']);
    }

    public function test_excludes_when_pick_qty_above_threshold(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-2', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 3, 'pickable' => true],
            ['location_name' => 'OS-1', 'quantity' => 5, 'pickable' => false],
        ]);

        $this->assertNull($row);
    }

    public function test_excludes_when_no_backstock(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-3', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 1, 'pickable' => true],
        ]);

        $this->assertNull($row);
    }

    public function test_includes_when_pick_qty_zero_and_backstock_present(): void
    {
        $row = InventoryRestockRowBuilder::buildRow('SKU-4', 'Product', null, [
            ['location_name' => 'A-01', 'quantity' => 1, 'pickable' => null],
            ['location_name' => 'OS-1', 'quantity' => 4, 'pickable' => false],
        ]);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['pick_qty']);
        $this->assertSame(4, $row['backstock_qty']);
    }
}
