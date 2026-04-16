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
        'endpoint' => env('BILLING_WHATSAPP_ENDPOINT'),
        'api_token' => env('BILLING_WHATSAPP_API_TOKEN'),
        'timeout_seconds' => (int) env('BILLING_WHATSAPP_TIMEOUT_SECONDS', 20),
    ],

];
