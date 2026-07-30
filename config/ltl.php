<?php

return [
    'slack_channel' => env('LTL_SLACK_CHANNEL', '#ltl-freight'),

    'facility' => [
        'company_name' => 'Save Rack',
        'line1' => config('returns.return_warehouse_address.line1', '3135 Drane Field Rd #21'),
        'line2' => config('returns.return_warehouse_address.line2', 'Lakeland, FL 33811'),
        'city' => 'Lakeland',
        'state' => 'FL',
        'zip' => '33811',
        'phone' => '',
    ],

    'directions' => [
        'ship_to_save_rack' => 'Ship To Save Rack',
        'ship_from_save_rack' => 'Ship From Save Rack',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'pending' => 'Pending',
        'quoted' => 'Quoted',
        'scheduled' => 'Scheduled',
        'in_transit' => 'In-Transit',
    ],

    'load_requirements' => [
        'dock' => 'Dock',
        'liftgate' => 'Liftgate Needed',
        'custom' => 'Custom',
    ],

    'pickup_types' => [
        'business' => 'Business',
        'residential' => 'Residential',
    ],

    'services' => [
        'standard_ltl' => 'Standard LTL',
    ],

    'time_modes' => [
        'asap' => 'As Soon As Possible',
        'specific' => 'Specific Date & Time',
    ],
];
