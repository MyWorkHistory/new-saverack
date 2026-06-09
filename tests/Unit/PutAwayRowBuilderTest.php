<?php

namespace Tests\Unit;

use App\Support\PutAwayRowBuilder;
use PHPUnit\Framework\TestCase;

class PutAwayRowBuilderTest extends TestCase
{
    public function test_build_row_splits_receiving_pickable_and_non_pickable(): void
    {
        $row = PutAwayRowBuilder::buildRow(
            'SKU-1',
            'Product One',
            '810084756300',
            null,
            [
                ['location_name' => 'Receiving', 'quantity' => 10, 'pickable' => false],
                ['location_name' => 'A-01', 'quantity' => 5, 'pickable' => true],
                ['location_name' => 'OS-1', 'quantity' => 25, 'pickable' => false],
            ],
            40,
            2
        );

        $this->assertSame('SKU-1', $row['sku']);
        $this->assertSame('810084756300', $row['barcode']);
        $this->assertSame(10, $row['receiving_qty']);
        $this->assertSame(5, $row['pickable_qty']);
        $this->assertSame(35, $row['non_pickable_qty']);
        $this->assertSame(40, $row['on_hand']);
        $this->assertSame(2, $row['backorder']);
    }

    public function test_receiving_qty_is_case_insensitive(): void
    {
        $row = PutAwayRowBuilder::buildRow(
            'SKU-2',
            'Product',
            null,
            null,
            [
                ['location_name' => 'receiving', 'quantity' => 3, 'pickable' => false],
            ],
            3,
            0
        );

        $this->assertSame(3, $row['receiving_qty']);
    }

    public function test_locations_from_product_detail_flattens_warehouses(): void
    {
        $locations = PutAwayRowBuilder::locationsFromProductDetail([
            'warehouses' => [
                [
                    'locations' => [
                        ['location_name' => 'Receiving', 'quantity' => 1],
                    ],
                ],
                [
                    'locations' => [
                        ['location_name' => 'A-01', 'quantity' => 2],
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $locations);
    }
}
