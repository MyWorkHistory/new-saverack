<?php

namespace Tests\Unit;

use App\Services\InventoryRestockBetaService;
use App\Support\Inventory\RestockBetaCsvParser;
use Tests\TestCase;

class InventoryRestockBetaTransferApplyTest extends TestCase
{
    private function service(): InventoryRestockBetaService
    {
        return new InventoryRestockBetaService(new RestockBetaCsvParser);
    }

    public function test_adjust_location_list_decrements_and_removes_zero_qty(): void
    {
        $adjusted = $this->service()->adjustLocationListQuantity(
            'S-50-0 (QTY: 72), S-10-0 (QTY: 5)',
            'S-50-0',
            -72
        );

        $this->assertTrue($adjusted['matched']);
        $this->assertSame(0, $adjusted['quantity']);
        $this->assertSame('S-10-0 (QTY: 5)', $adjusted['text']);
    }

    public function test_adjust_location_list_increments_pick_using_known_before_qty(): void
    {
        $adjusted = $this->service()->adjustLocationListQuantity(
            'S-36-0',
            'S-36-0',
            72,
            1
        );

        $this->assertTrue($adjusted['matched']);
        $this->assertSame(73, $adjusted['quantity']);
        $this->assertSame('S-36-0 (QTY: 73)', $adjusted['text']);
    }
}
