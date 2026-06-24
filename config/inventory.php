<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ShipHero inventory adjustment / transfer reasons
    |--------------------------------------------------------------------------
    |
    | Keep in sync with ShipHero warehouse inventory change reason options.
    |
    */
    'adjustment_reasons' => [
        'Account Setup',
        'Amazon Return',
        'Client-Requested Adjustments',
        'Cycle Counts / Physical Counts',
        'Damaged Inventory',
        'Expiration or Obsolescence',
        'Inbound Receiving Adjustments',
        'Inventory Reclassification',
        'Kitting / Bundling',
        'Lost or Missing Units',
        'Order Fulfilment',
        'Quality Control Holds',
        'Restock',
        'Returns Processing',
        'Shipped via Shipstation',
        'System Sync or Integration Corrections',
    ],

    'default_transfer_reason' => 'Restock',

    'default_put_away_reason' => 'Inbound Receiving Adjustments',

    'default_add_location_reason' => 'Account Setup',

];
