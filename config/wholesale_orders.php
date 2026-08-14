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

    'cover_existing_barcodes' => [
        'yes' => 'Yes',
        'no' => 'No',
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
        'yes' => 'Yes',
        'no' => 'No',
        // Legacy values still readable for older orders
        'not_bundled' => 'No',
        'bundle_together' => 'Yes',
    ],

    'shipping_method_requirement' => [
        'original_master_carton' => 'Ship in original master carton',
        'save_rack_determines' => 'Save Rack determines best shipping boxes and sizes',
        'custom' => 'Custom Shipping Packaging',
    ],

    'master_cartons' => [
        'yes' => 'Yes',
        'no' => 'No',
        'other' => 'Other (specify in comments)',
    ],

    'shipping_labels_provider' => [
        'client_provides' => 'Client Provides Shipping Labels',
        'save_rack_provides' => 'Save Rack Provides Shipping Labels',
    ],
];
