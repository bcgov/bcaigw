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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Keycloak (BC Gov Common Hosted Single Sign-On)
    |--------------------------------------------------------------------------
    |
    | KEYCLOAK_SERVER_URL and the `fsg` identity-provider hint match FSG's IDIR
    | integration. The previous BCAIGW variable names remain fallbacks so
    | existing deployments can migrate without an abrupt configuration break.
    |
    */
    'keycloak' => [
        'base_url' => env('KEYCLOAK_SERVER_URL', env('KEYCLOAK_AUTH_SERVER_URL', env('KEYCLOAK_BASE_URL'))),
        'realm' => env('KEYCLOAK_REALM'),
        'client_id' => env('KEYCLOAK_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'redirect_uri' => env('KEYCLOAK_REDIRECT_URI', env('APP_URL').'/auth/keycloak/callback'),
        'post_logout_redirect_uri' => env('KEYCLOAK_POST_LOGOUT_REDIRECT_URI', env('APP_URL')),
        'version' => env('KEYCLOAK_VERSION', '26.0.0'),

        /*
         | FSG sends kc_idp_hint=fsg for government staff sign-in.
         */
        'idp_hint' => env('KEYCLOAK_IDP_HINT', 'fsg'),
        'identity_provider_claim' => env('KEYCLOAK_IDENTITY_PROVIDER_CLAIM', 'identity_provider'),

        /*
         | Accepted values of the identity_provider claim, comma separated.
         | Both aliases are accepted because the claim mirrors whichever IDIR
         | provider the realm actually used, and CSS realms differ. This stays
         | an explicit allowlist of trusted claim values - IDIR is never
         | inferred from an email domain.
         */
        'identity_provider_values' => env('KEYCLOAK_IDENTITY_PROVIDER_VALUES', 'idir,azureidir,fsg'),
        'idir_guid_claim' => env('KEYCLOAK_IDIR_GUID_CLAIM', 'idir_user_guid'),
        'idir_username_claim' => env('KEYCLOAK_IDIR_USERNAME_CLAIM', 'idir_username'),
        'transaction_ttl' => env('KEYCLOAK_TRANSACTION_TTL', 300),
        'jwks_cache_seconds' => env('KEYCLOAK_JWKS_CACHE_SECONDS', 3600),
        'http_timeout' => env('KEYCLOAK_HTTP_TIMEOUT', 5),
    ],

];
