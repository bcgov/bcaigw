<?php

return [
    'issuer' => env('MACHINE_AUTH_ISSUER', env('APP_URL')),
    'access_token_ttl_seconds' => (int) env('MACHINE_AUTH_TOKEN_TTL', 300),
    'token_rate_limit_per_minute' => (int) env('MACHINE_AUTH_TOKEN_RATE_LIMIT', 30),
    'last_used_update_interval_seconds' => (int) env('MACHINE_AUTH_LAST_USED_INTERVAL', 60),
    'default_rotation_overlap_seconds' => (int) env('MACHINE_AUTH_ROTATION_OVERLAP', 300),
    'max_rotation_overlap_seconds' => (int) env('MACHINE_AUTH_MAX_ROTATION_OVERLAP', 3600),
    'client_cache_buffer_seconds' => (int) env('MACHINE_AUTH_CLIENT_CACHE_BUFFER', 60),
    'argon2id' => [
        'memory_cost' => (int) env('MACHINE_AUTH_ARGON_MEMORY', 65_536),
        'time_cost' => (int) env('MACHINE_AUTH_ARGON_TIME', 4),
        'threads' => (int) env('MACHINE_AUTH_ARGON_THREADS', 1),
    ],
    'abilities' => [
        'gateway.invoke' => 'Invoke configured model gateway routes',
        'gateway.status' => 'Read application-scoped gateway status',
    ],
];
