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

];
