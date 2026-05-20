<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CRM owner email
    |--------------------------------------------------------------------------
    |
    | Only this user may access tickets (MVP). Defaults to ADMIN_EMAIL when unset.
    |
    */
    'owner_email' => env('CRM_OWNER_EMAIL') ?: env('ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | CRM SPA base URL (password reset links, staff registration links)
    |--------------------------------------------------------------------------
    */
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Auth / registration mail
    |--------------------------------------------------------------------------
    */
    'mail_from_address' => env('CRM_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'noreply@saverack.com')),
    'mail_from_name' => env('CRM_MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Save Rack')),

    /** Notified when a new 3PL self-serve account is created. */
    'registration_notify_email' => env('REGISTRATION_NOTIFY_EMAIL') ?: env('ADMIN_EMAIL'),

];
