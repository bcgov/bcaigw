<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Keycloak / BC Gov Common Hosted SSO
    |--------------------------------------------------------------------------
    |
    | Configuration for the IDIR sign-in flow. Variable names mirror the ones
    | used across the BC Gov / NRSTS stack so the same .env works unchanged.
    | The gateway only integrates with IDIR, so kc_idp_hint defaults to "idir".
    |
    */

    'base_url' => env('KEYCLOAK_SERVER_URL', env('KEYCLOAK_AUTH_SERVER_URL', env('KEYCLOAK_BASE_URL'))),

    'realm' => env('KEYCLOAK_REALM', 'standard'),

    'client_id' => env('KEYCLOAK_CLIENT_ID'),

    'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),

    'redirect_uri' => env('KEYCLOAK_REDIRECT_URI', env('APP_URL').'/applogin'),

    'post_logout_redirect_uri' => env('KEYCLOAK_POST_LOGOUT_REDIRECT_URI', env('APP_URL').'/login'),

    'version' => env('KEYCLOAK_VERSION', '26.0.0'),

    'idp_hint' => env('KEYCLOAK_IDP_HINT', 'idir'),

    'login_url' => env('KEYCLOAK_LOGIN_URL', '/applogin'),

];
