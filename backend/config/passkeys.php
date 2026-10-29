<?php

/*
 * Passkey sign-in for shoppers (laravel/passkeys). The website, not Laravel, is where the browser runs the
 * ceremony, so the relying party and origin are the website's (FRONTEND_URL). Passkeys only work on that
 * hostname: after a domain change shoppers sign in once by email code and add a passkey again.
 * Routes are registered in routes/api.php, next to email sign-in.
 */

$frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:4321'), '/');

return [

    'relying_party_id' => env('PASSKEYS_RP_ID', parse_url($frontend, PHP_URL_HOST)),

    'allowed_origins' => array_values(array_unique(array_filter([
        $frontend,
        ...explode(',', (string) env('PASSKEYS_EXTRA_ORIGINS', '')),
    ]))),

    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', env('APP_KEY')),

    'timeout' => 60000,

    // The website signs in with the session cookie (Sanctum SPA), the same guard as email codes.
    'guard' => 'web',

    'middleware' => ['web'],

    // Shoppers have no password to re-confirm; adding or removing a passkey needs a signed-in session.
    'management_middleware' => [],

    'throttle' => 'throttle:20,1',

    'redirect' => '/account',

];
