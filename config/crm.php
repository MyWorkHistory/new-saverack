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

    /** From address for password reset emails (must be verified in SES). */
    'password_reset_from_address' => env('CRM_PASSWORD_RESET_FROM_ADDRESS', 'info@saverack.com'),
    'password_reset_from_name' => env('CRM_PASSWORD_RESET_FROM_NAME', env('CRM_MAIL_FROM_NAME', 'Save Rack')),

    /** From address for new account welcome emails (must be verified in SES). */
    'account_welcome_from_address' => env('CRM_ACCOUNT_WELCOME_FROM_ADDRESS', 'info@saverack.com'),
    'account_welcome_from_name' => env('CRM_ACCOUNT_WELCOME_FROM_NAME', env('CRM_MAIL_FROM_NAME', 'Save Rack')),

    /** Notified when a new 3PL self-serve account is created. */
    'registration_notify_email' => env('REGISTRATION_NOTIFY_EMAIL') ?: env('ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Portal onboarding (welcome checklist)
    |--------------------------------------------------------------------------
    */
    'stripe_onboarding_price_id' => env('STRIPE_ONBOARDING_PRICE_ID'),
    'stripe_onboarding_deposit_amount_cents' => (int) env('STRIPE_ONBOARDING_DEPOSIT_AMOUNT_CENTS', 500),
    'stripe_onboarding_payment_link_url' => env('STRIPE_ONBOARDING_PAYMENT_LINK_URL', 'https://buy.stripe.com/8x2eVcbpz6PIdm0blw1Fe0C'),

    'portal_manual_payment_instructions' => [
        'company' => 'Save Rack LLC',
        'street' => '3135 Drane Field Rd #20',
        'city_state_zip' => 'Lakeland, FL 33811',
        'routing' => '063107513',
        'account' => '1157249176',
        'wire' => '121000248',
        'zelle' => 'audi@saverack.com',
        'apple_pay' => '727-255-4885',
    ],

];
