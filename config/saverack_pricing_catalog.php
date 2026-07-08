<?php

use App\Models\PricingFeeTemplate;

/**
 * Save Rack standard pricing catalog (from fulfillment fee sheets).
 * Imported by: php artisan crm:import-saverack-pricing
 *
 * Each row: name, category, amount, description (optional), aliases (optional legacy template names).
 *
 * @return list<array{name: string, category: string, amount: float|string, description?: string, aliases?: list<string>}>
 */
return [
    // Fulfillment
    [
        'name' => 'Fulfillment (pick & pack 1 item)',
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'amount' => 1.95,
        'description' => 'Per order. Pick and pack the first item.',
        'aliases' => ['First Pick'],
    ],
    [
        'name' => 'Additional Items',
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'amount' => 0.25,
        'description' => 'Per item. Additional items within the same order.',
        'aliases' => ['Additional Picks'],
    ],
    [
        'name' => 'Packing Slips',
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'amount' => 0.00,
        'description' => 'Per order. Order receipt / packing slip.',
    ],
    [
        'name' => 'Inserts',
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'amount' => 0.10,
        'description' => 'Per order. Paper-based products and promotional items.',
    ],
    [
        'name' => 'Assembly or Kitting',
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'amount' => 0.25,
        'description' => 'Per item. Custom assembly work of items.',
    ],
    [
        'name' => 'Labeling',
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'amount' => 0.20,
        'description' => 'Per item. Custom labels applied to items if needed.',
    ],
    [
        'name' => 'Over 20 lb Surcharge',
        'category' => PricingFeeTemplate::CATEGORY_FULFILLMENT,
        'amount' => 1.00,
        'description' => 'Per order. Additional fulfillment fee when any item in the order is over 20 lbs.',
    ],

    // Returns
    [
        'name' => 'Returns Processing',
        'category' => PricingFeeTemplate::CATEGORY_RETURNS,
        'amount' => 2.00,
        'description' => 'Per order. Log in WMS, restock or dispose of return.',
    ],

    // Custom work
    [
        'name' => 'Custom Work (hourly)',
        'category' => PricingFeeTemplate::CATEGORY_CUSTOM_WORK,
        'amount' => 45.00,
        'description' => 'Per hour. Services outside the normal scope of work.',
        'aliases' => ['Custom Hourly Work'],
    ],

    // Storage
    [
        'name' => 'Storage by Cubic Foot (monthly estimate)',
        'category' => PricingFeeTemplate::CATEGORY_STORAGE,
        'amount' => 0.96,
        'description' => 'Monthly estimate at a constant inventory level. Actual billing uses the daily rate.',
    ],
    [
        'name' => 'Storage by Cubic Foot (daily actual)',
        'category' => PricingFeeTemplate::CATEGORY_STORAGE,
        'amount' => 0.032,
        'description' => 'Daily rate per cubic foot (3.2 cents). Billed weekly based on daily inventory levels.',
    ],

    // Receiving
    [
        'name' => 'Receiving (Per Box)',
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'amount' => 2.50,
        'description' => 'Per box. Standard inbound box receiving fee.',
    ],
    [
        'name' => 'Receiving (Per Pallet)',
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'amount' => 20.00,
        'description' => 'Per pallet. Standard inbound pallet receiving fee.',
    ],
    [
        'name' => 'Receiving (Per Item)',
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'amount' => 0.01,
        'description' => 'Per item. Applies to all inbound deliveries in addition to box/pallet/container fees.',
    ],
    [
        'name' => 'Receiving (Per Container 20 ft)',
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'amount' => 250.00,
        'description' => 'Per 20 ft container. Standard container receiving fee.',
    ],
    [
        'name' => 'Receiving (Per Container 40 ft)',
        'category' => PricingFeeTemplate::CATEGORY_RECEIVING,
        'amount' => 450.00,
        'description' => 'Per 40 ft container. Standard container receiving fee.',
    ],

    // Wholesale
    [
        'name' => 'Wholesale Fulfillment',
        'category' => PricingFeeTemplate::CATEGORY_WHOLESALE,
        'amount' => 2.50,
        'description' => 'Per unit. Wholesale order fulfillment.',
    ],
    [
        'name' => 'Master Carton',
        'category' => PricingFeeTemplate::CATEGORY_WHOLESALE,
        'amount' => 0.50,
        'description' => 'Per master carton. Manufacturer-sealed carton with predetermined quantity.',
    ],
    [
        'name' => 'Per Item (if Master Carton not used)',
        'category' => PricingFeeTemplate::CATEGORY_WHOLESALE,
        'amount' => 0.25,
        'description' => 'Per item when products must be manually counted or picked instead of master carton.',
    ],
    [
        'name' => 'Pallet Prep',
        'category' => PricingFeeTemplate::CATEGORY_WHOLESALE,
        'amount' => 8.00,
        'description' => 'Per pallet. Pallet included, shrink wrapped, and labeled.',
    ],
    [
        'name' => 'LTL Pickup',
        'category' => PricingFeeTemplate::CATEGORY_WHOLESALE,
        'amount' => 5.00,
        'description' => 'Per shipment. Scheduling and BOL paperwork for LTL pickup.',
    ],
    [
        'name' => 'Barcode Labeling',
        'category' => PricingFeeTemplate::CATEGORY_WHOLESALE,
        'amount' => 0.55,
        'description' => 'Per label. Barcode labeling for wholesale orders.',
    ],

    // Amazon
    [
        'name' => 'Amazon Prep',
        'category' => PricingFeeTemplate::CATEGORY_AMAZON,
        'amount' => 2.50,
        'description' => 'Per unit. Amazon FBA prep (similar to wholesale prep).',
    ],
    [
        'name' => 'Amazon Master Carton',
        'category' => PricingFeeTemplate::CATEGORY_AMAZON,
        'amount' => 0.50,
        'description' => 'Per master carton. Manufacturer-sealed carton for Amazon FBA.',
    ],
    [
        'name' => 'Amazon Per Item (if Master Carton not used)',
        'category' => PricingFeeTemplate::CATEGORY_AMAZON,
        'amount' => 0.25,
        'description' => 'Per item when master carton is not used for Amazon FBA prep.',
    ],
    [
        'name' => 'Amazon Pallet Prep',
        'category' => PricingFeeTemplate::CATEGORY_AMAZON,
        'amount' => 8.00,
        'description' => 'Per pallet. Pallet included, shrink wrapped, and labeled for Amazon.',
    ],
    [
        'name' => 'Amazon LTL Pickup',
        'category' => PricingFeeTemplate::CATEGORY_AMAZON,
        'amount' => 5.00,
        'description' => 'Per shipment. LTL scheduling and BOL paperwork for Amazon.',
    ],
    [
        'name' => 'FNSKU Barcode Labeling',
        'category' => PricingFeeTemplate::CATEGORY_AMAZON,
        'amount' => 0.55,
        'description' => 'Per label. FNSKU barcode labeling; labels printed in-house.',
    ],
];
