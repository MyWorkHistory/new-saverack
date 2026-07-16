<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dev invoice “send” notification (temporary)
    |--------------------------------------------------------------------------
    |
    | While outbound billing email is still in development, “Send invoice”
    | delivers a simple notification to this address only. Replace with real
    | client/recipient logic when ready.
    |
    */
    'invoice_send_dev_email' => env('BILLING_INVOICE_SEND_DEV_EMAIL', 'chaowang318915@gmail.com'),

    'whatsapp' => [
        'endpoint' => env('BILLING_WHATSAPP_ENDPOINT', env('WHATSAPP_ENDPOINT', 'https://api.periskope.app/v1/message/send')),
        'api_token' => env('BILLING_WHATSAPP_API_TOKEN', env('WHATSAPP_TOKEN')),
        'timeout_seconds' => (int) env('BILLING_WHATSAPP_TIMEOUT_SECONDS', 20),
    ],

    'slack' => [
        'accounting_channel' => env('BILLING_SLACK_ACCOUNTING_CHANNEL') ?: '#accounting',
        'alerts_channel' => env('SLACK_ALERTS_CHANNEL') ?: '#alerts',
        /** Bot User OAuth Token (xoxb-…) for chat.postMessage. */
        'bot_token' => env('SLACK_BOT_USER_OAUTH_TOKEN') ?: env('SLACK_BOT_TOKEN'),
        /**
         * Incoming Webhook (legacy Save Net uses SLACK_WEBHOOK_URL + channel name in payload).
         * BILLING_SLACK_INCOMING_WEBHOOK_URL overrides SLACK_WEBHOOK_URL when set.
         */
        'webhook_url' => env('BILLING_SLACK_INCOMING_WEBHOOK_URL') ?: env('SLACK_WEBHOOK_URL'),
        /**
         * Public HTTPS base URL Slack can fetch for status icons (e.g. https://app.saverack.com).
         * Defaults to FRONTEND_URL, then APP_URL.
         */
        'public_asset_base_url' => env('SLACK_PUBLIC_ASSET_BASE_URL')
            ?: env('FRONTEND_URL')
            ?: env('APP_URL'),
        /** Optional full URLs if icons are hosted on a CDN (overrides built-in routes). */
        'status_icon_live_url' => env('SLACK_STATUS_ICON_LIVE_URL'),
        'status_icon_paused_url' => env('SLACK_STATUS_ICON_PAUSED_URL'),
        'status_icon_live_thumb_url' => env('SLACK_STATUS_ICON_LIVE_THUMB_URL'),
        'status_icon_paused_thumb_url' => env('SLACK_STATUS_ICON_PAUSED_THUMB_URL'),
    ],

];

