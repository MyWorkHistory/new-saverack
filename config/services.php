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
    ],

    'whatsapp' => [
        'endpoint' => env('WHATSAPP_ENDPOINT', 'https://api.periskope.app/v1/message/send'),
        'token' => env('WHATSAPP_TOKEN'),
        'phone' => env('WHATSAPP_NUMBER'),
        'chat_id' => env('WHATSAPP_GROUP_ID'),
    ],

];
