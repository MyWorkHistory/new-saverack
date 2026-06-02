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
        'accounting_channel' => env('BILLING_SLACK_ACCOUNTING_CHANNEL') ?: '#accounting-support',
        /** Bot User OAuth Token (xoxb-…) for chat.postMessage. */
        'bot_token' => env('SLACK_BOT_USER_OAUTH_TOKEN') ?: env('SLACK_BOT_TOKEN'),
        /**
         * Optional Incoming Webhook URL for #accounting-support (https://hooks.slack.com/services/…).
         * When set, invoice review uses this instead of the bot token.
         */
        'webhook_url' => env('BILLING_SLACK_INCOMING_WEBHOOK_URL'),
    ],

];
