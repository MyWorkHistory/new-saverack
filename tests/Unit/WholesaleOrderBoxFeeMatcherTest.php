<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;
use App\Support\Billing\WholesaleOrderBoxFeeMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WholesaleOrderBoxFeeMatcherTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_parses_x_and_multiplication_sign_labels(): void
    {
        $this->assertSame(
            [6.0, 9.0, 12.0],
            WholesaleOrderBoxFeeMatcher::parseDimensionsFromLabel('12 x 9 x 6')
        );
        $this->assertSame(
            [6.0, 9.0, 12.0],
            WholesaleOrderBoxFeeMatcher::parseDimensionsFromLabel('12×9×6')
        );
        $this->assertSame(
            [6.0, 9.0, 12.0],
            WholesaleOrderBoxFeeMatcher::parseDimensionsFromLabel('Box (12 X 9 X 6)')
        );

        $matched = WholesaleOrderBoxFeeMatcher::findMatchingOption([[
            'line_type' => 'fee_1',
            'client_account_fee_id' => 1,
            'display_name' => '12 x 9 x 6',
            'default_unit_price_cents' => 85,
        ]], 12, 9, 6);

        $this->assertNotNull($matched);
        $this->assertSame(85, $matched['default_unit_price_cents']);
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

    public function test_falls_back_to_generic_box_fee_price_when_label_has_no_dimensions(): void
    {
        $options = [
            [
                'line_type' => 'fee_9',
                'client_account_fee_id' => 9,
                'display_name' => 'Box',
                'default_unit_price_cents' => 85,
            ],
            [
                'line_type' => 'fee_10',
                'client_account_fee_id' => 10,
                'display_name' => 'Mailer',
                'default_unit_price_cents' => 40,
            ],
        ];

        $generic = WholesaleOrderBoxFeeMatcher::findGenericBoxOption($options);
        $this->assertNotNull($generic);
        $this->assertSame(9, $generic['client_account_fee_id']);
        $this->assertSame(85, $generic['default_unit_price_cents']);

        $optionsWithSized = array_merge($options, [[
            'line_type' => 'fee_11',
            'client_account_fee_id' => 11,
            'display_name' => 'Box (12 X 9 X 6)',
            'default_unit_price_cents' => 125,
        ]]);
        $sized = WholesaleOrderBoxFeeMatcher::findMatchingOption($optionsWithSized, 12, 9, 6);
        $this->assertNotNull($sized);
        $this->assertSame(11, $sized['client_account_fee_id']);
    }

    public function test_match_row_uses_account_fees_tab_box_amount(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Box Fee Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'box-fee@example.test',
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_PACKAGING,
            'line_code' => 'box_price',
            'label' => 'Box',
            'amount' => '0.8500',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        $row = WholesaleOrderBoxFeeMatcher::matchRow([
            'length' => 12,
            'width' => 9,
            'height' => 6,
            'quantity' => 2,
        ], $account->fresh());

        $this->assertTrue($row['matched']);
        $this->assertSame('12 × 9 × 6 in', $row['display_name']);
        $this->assertSame(85, $row['default_unit_price_cents']);
        $this->assertSame(0.85, $row['unit_price']);
        $this->assertNotNull($row['client_account_fee_id']);
    }

    public function test_match_row_uses_sized_account_fee_label(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Sized Box Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'sized-box@example.test',
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_PACKAGING,
            'line_code' => 'legacy_packaging_12x9x6',
            'label' => '12 x 9 x 6',
            'amount' => '0.8500',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        $row = WholesaleOrderBoxFeeMatcher::matchRow([
            'length' => 12,
            'width' => 9,
            'height' => 6,
            'quantity' => 1,
        ], $account->fresh());

        $this->assertTrue($row['matched']);
        $this->assertSame('12 x 9 x 6', $row['display_name']);
        $this->assertSame(0.85, $row['unit_price']);
    }

    public function test_match_row_uses_fulfillment_sized_box_fee(): void
    {
        $account = ClientAccount::query()->create([
            'company_name' => 'Fulfillment Box Co',
            'status' => ClientAccount::STATUS_ACTIVE,
            'email' => 'fulfillment-box@example.test',
        ]);
        ClientAccountFee::query()->create([
            'client_account_id' => $account->id,
            'fee_group' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
            'line_code' => 'box_12x9x6',
            'label' => 'BOX 12x9x6',
            'amount' => '0.8500',
            'currency' => 'USD',
            'sort_order' => 1,
        ]);

        $row = WholesaleOrderBoxFeeMatcher::matchRow([
            'length' => 12,
            'width' => 9,
            'height' => 6,
            'quantity' => 1,
        ], $account->fresh());

        $this->assertTrue($row['matched']);
        $this->assertSame('BOX 12x9x6', $row['display_name']);
        $this->assertSame(0.85, $row['unit_price']);
        $this->assertNotNull($row['client_account_fee_id']);
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
        $this->assertSame(3, $rows[0]['quantity']);
    }
}
