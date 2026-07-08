<?php

use App\Models\ClientAccountFee;
use App\Models\PricingFeeTemplate;

/**
 * Maps legacy customers_fees.service values to new CRM pricing templates.
 *
 * @return array<string, array{category: string, template_names: list<string>, line_code?: string}>
 */
return [
    'Fulfillment' => [
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'template_names' => ['Fulfillment (pick & pack 1 item)', 'First Pick'],
        'line_code' => ClientAccountFee::LINE_FIRST_PICK,
    ],
    'Additional Picks' => [
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'template_names' => ['Additional Items', 'Additional Picks'],
        'line_code' => ClientAccountFee::LINE_ADDITIONAL_PICKS,
    ],
    'Packing Slips' => [
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'template_names' => ['Packing Slips'],
    ],
    'Inserts' => [
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'template_names' => ['Inserts'],
    ],
    'Assembly' => [
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'template_names' => ['Assembly or Kitting'],
    ],
    'Assembly or Kitting' => [
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'template_names' => ['Assembly or Kitting'],
    ],
    'Labeling' => [
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'template_names' => ['Labeling'],
    ],
    'Returns' => [
        'category' => PricingFeeTemplate::CATEGORY_RETURNS,
        'template_names' => ['Returns Processing'],
        'line_code' => ClientAccountFee::LINE_RETURNS_PROCESSING,
    ],
    'Returns Additional Items' => [
        'category' => PricingFeeTemplate::CATEGORY_RETURNS,
        'template_names' => ['Returns Additional Items'],
        'line_code' => ClientAccountFee::LINE_RETURNS_ADDITIONAL_ITEMS,
    ],
    'Returns Assembly' => [
        'category' => PricingFeeTemplate::CATEGORY_RETURNS,
        'template_names' => ['Returns Assembly'],
        'line_code' => ClientAccountFee::LINE_RETURNS_ASSEMBLY,
    ],
    'Returns Re-Packaging' => [
        'category' => PricingFeeTemplate::CATEGORY_RETURNS,
        'template_names' => ['Returns Re-Packaging'],
        'line_code' => ClientAccountFee::LINE_RETURNS_REPACKAGING,
    ],
    'Returns Disposal' => [
        'category' => PricingFeeTemplate::CATEGORY_RETURNS,
        'template_names' => ['Returns Disposal'],
        'line_code' => ClientAccountFee::LINE_RETURNS_DISPOSAL,
    ],
    'Disposal' => [
        'category' => PricingFeeTemplate::CATEGORY_RETURNS,
        'template_names' => ['Returns Disposal'],
        'line_code' => ClientAccountFee::LINE_RETURNS_DISPOSAL,
    ],
    'Non-Compliant Return' => [
        'category' => PricingFeeTemplate::CATEGORY_RETURNS,
        'template_names' => ['Non-Compliant Return'],
        'line_code' => ClientAccountFee::LINE_RETURNS_NON_COMPLIANT,
    ],
    'Custom Work' => [
        'category' => PricingFeeTemplate::CATEGORY_CUSTOM_WORK,
        'template_names' => ['Custom Work (hourly)', 'Custom Hourly Work'],
        'line_code' => 'hourly',
    ],
    'Custom Hourly Work' => [
        'category' => PricingFeeTemplate::CATEGORY_CUSTOM_WORK,
        'template_names' => ['Custom Work (hourly)', 'Custom Hourly Work'],
        'line_code' => 'hourly',
    ],
    'Receiving (Per Box)' => [
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'template_names' => ['Receiving (Per Box)'],
        'line_code' => 'per_box',
    ],
    'Receiving (Per Pallet)' => [
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'template_names' => ['Receiving (Per Pallet)'],
        'line_code' => 'per_pallet',
    ],
    'Receiving (Per Item)' => [
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'template_names' => ['Receiving (Per Item)'],
        'line_code' => 'per_item',
    ],
    'Receiving' => [
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'template_names' => ['Receiving (Per Box)', 'Receiving (Per Item)'],
    ],
    'Wholesale Fulfillment' => [
        'category' => PricingFeeTemplate::CATEGORY_WHOLESALE,
        'template_names' => ['Wholesale Fulfillment'],
    ],
    'Amazon Prep' => [
        'category' => PricingFeeTemplate::CATEGORY_AMAZON,
        'template_names' => ['Amazon Prep'],
    ],
];
