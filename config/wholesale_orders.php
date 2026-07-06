<?php

return [
    'statuses' => [
        'draft' => 'Draft',
        'pending' => 'Pending',
        'in_progress' => 'Ready to Ship',
        'completed' => 'Completed',
        'shipped' => 'Shipped',
    ],

    'order_types' => [
        'amazon' => 'Amazon',
        'tiktok' => 'TikTok',
        'walmart' => 'Walmart',
        'b2b' => 'B2B',
        'other' => 'Other',
    ],

    'line_statuses' => [
        'pending' => 'Pending',
        'ship_as_is' => 'Ship As Is',
        'barcode_ready' => 'Barcode Ready',
    ],

    'sku_barcode_labels' => [
        'apply_new' => 'Apply New Barcode Labels',
        'none' => 'No Barcode Labels',
    ],

    'individual_sku_packaging' => [
        'none' => 'No Additional Packaging',
        'poly_bag' => 'Poly Bag Each Item',
        'bubble_mailer' => 'Bubble Mailer Each Item',
        'box' => 'Box Each Item',
        'bubble_wrap' => 'Bubble Wrap Each Item',
        'other' => 'Other (Specify)',
    ],

    'bundle_configuration' => [
        'not_bundled' => 'Not Bundled (Single SKU)',
        'bundle_together' => 'Bundle Individual SKUs Together',
    ],

    'shipping_method_requirement' => [
        'boxes' => 'Ship all in boxes',
        'pallet' => 'Ship all on pallet',
    ],
];
