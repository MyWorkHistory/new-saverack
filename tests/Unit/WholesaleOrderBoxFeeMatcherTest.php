<?php

namespace Tests\Unit;

use App\Support\Billing\WholesaleOrderBoxFeeMatcher;
use Tests\TestCase;

class WholesaleOrderBoxFeeMatcherTest extends TestCase
{
    public function test_matches_packaging_fee_by_normalized_dimensions(): void
    {
        $options = [[
            'line_type' => 'fee_1',
            'client_account_fee_id' => 1,
            'display_name' => 'Box (12 X 10 X 8)',
            'default_unit_price_cents' => 450,
        ]];

        $matched = WholesaleOrderBoxFeeMatcher::findMatchingOption($options, 10, 12, 8);

        $this->assertNotNull($matched);
        $this->assertSame('Box (12 X 10 X 8)', $matched['display_name']);
        $this->assertSame(450, $matched['default_unit_price_cents']);
    }

    public function test_unmatched_dimensions_fallback_to_zero_price_label(): void
    {
        $row = WholesaleOrderBoxFeeMatcher::matchRow([
            'length' => 11,
            'width' => 9,
            'height' => 7,
            'quantity' => 3,
        ], null);

        $this->assertFalse($row['matched']);
        $this->assertSame('11 × 9 × 7 in', $row['display_name']);
        $this->assertSame(0, $row['default_unit_price_cents']);
        $this->assertSame(3, $row['quantity']);
    }

    public function test_aggregate_line_boxes_sums_quantities_by_size(): void
    {
        $lines = [
            [
                'boxes' => [
                    ['length' => 12, 'width' => 10, 'height' => 8, 'quantity' => 2],
                    ['length' => 10, 'width' => 12, 'height' => 8, 'quantity' => 3],
                ],
            ],
            [
                'boxes' => [
                    ['length' => 12, 'width' => 10, 'height' => 8, 'quantity' => 5],
                ],
            ],
        ];

        $rows = WholesaleOrderBoxFeeMatcher::aggregateLineBoxes($lines, null);

        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]['quantity']);
    }
}
