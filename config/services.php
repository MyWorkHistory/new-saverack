<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'shiphero' => [
        'api_url' => env('SHIPHERO_API_URL', 'https://public-api.shiphero.com/graphql'),
        'auth_url' => env('SHIPHERO_AUTH_URL', 'https://public-api.shiphero.com/auth'),
        'refresh_token' => env('SHIPHERO_REFRESH_TOKEN'),
        'customer_account_id' => env('SHIPHERO_CUSTOMER_ACCOUNT_ID'),
        /** Public https origin for /storage/... URLs passed to order_add_attachment (ShipHero fetches this). */
        'attachment_public_base_url' => env('SHIPHERO_ATTACHMENT_PUBLIC_BASE_URL'),
        /** Optional warehouse id for admin restock report; defaults to first ShipHero warehouse. */
        'restock_warehouse_id' => env('SHIPHERO_RESTOCK_WAREHOUSE_ID'),
        /** ShipHero warehouse_products page size (keep under per-operation credit limit). */
        'restock_page_size' => (int) env('SHIPHERO_RESTOCK_PAGE_SIZE', 20),
        /** Max location rows per warehouse product in restock scan. */
        'restock_location_limit' => (int) env('SHIPHERO_RESTOCK_LOCATION_LIMIT', 25),
        /** Minutes before a running snapshot is marked failed (orphaned queue job). */
        'restock_stale_minutes' => (int) env('SHIPHERO_RESTOCK_STALE_MINUTES', 45),
        /** Pages scanned per refresh job chunk (keeps each job under queue retry_after). */
        'restock_chunk_pages' => (int) env('SHIPHERO_RESTOCK_CHUNK_PAGES', 15),
        /** Stop each UI scan once this many new pickable-qty matches are found (Load More fetches the next batch). */
        'restock_match_batch_size' => (int) env('SHIPHERO_RESTOCK_MATCH_BATCH_SIZE', 20),
        /** Safety cap on ShipHero pages per refresh / load-more request. */
        'restock_scan_safety_max_pages' => (int) env('SHIPHERO_RESTOCK_SCAN_SAFETY_MAX_PAGES', 200),
        /** Fail a running refresh when no DB progress for this many minutes. */
        'restock_stall_minutes' => (int) env('SHIPHERO_RESTOCK_STALL_MINUTES', 10),
        /** Skip full warehouse location catalog (saves ShipHero credits; uses product locations only). */
        'restock_skip_location_catalog' => filter_var(
            env('SHIPHERO_RESTOCK_SKIP_LOCATION_CATALOG', false),
            FILTER_VALIDATE_BOOLEAN
        ),
        'restock_max_pickable_qty' => (int) env('SHIPHERO_RESTOCK_MAX_PICKABLE_QTY', 2),
        /** after_response (default, no queue worker) or queue (requires queue:work / Horizon). */
        'restock_dispatch_mode' => env('SHIPHERO_RESTOCK_DISPATCH_MODE', 'after_response'),
        /** Max restock rows returned in a single full=1 API response (avoids origin OOM / 502). */
        'restock_api_max_rows' => (int) env('SHIPHERO_RESTOCK_API_MAX_ROWS', 5000),
        /** GraphQL mutation for customer account update (from shiphero:probe-customer-mutations). */
        'customer_account_update_mutation' => env('SHIPHERO_CUSTOMER_ACCOUNT_UPDATE_MUTATION'),
        'customer_account_update_input_type' => env('SHIPHERO_CUSTOMER_ACCOUNT_UPDATE_INPUT_TYPE'),
        /** Boolean input field: hide client orders from ShipHero app when CRM status is not active. */
        'customer_account_hide_orders_field' => env('SHIPHERO_CUSTOMER_ACCOUNT_HIDE_ORDERS_FIELD'),
        'customer_account_id_field' => env('SHIPHERO_CUSTOMER_ACCOUNT_ID_FIELD', 'customer_account_id'),
    ],

    'whatsapp' => [
        'endpoint' => env('WHATSAPP_ENDPOINT', 'https://api.periskope.app/v1/message/send'),
        'token' => env('WHATSAPP_TOKEN'),
        'phone' => env('WHATSAPP_NUMBER'),
        'chat_id' => env('WHATSAPP_GROUP_ID'),
    ],

];
