<?php

$tokenEndpoint = env('APP_API_TOKEN_ENDPOINT');
$issuerDefault = $tokenEndpoint
    ? preg_replace('#/protocol/openid-connect/token/?$#', '', (string) $tokenEndpoint)
    : null;
$jwksDefault = $issuerDefault ? $issuerDefault.'/protocol/openid-connect/certs' : null;

return [
    // Requested models are sourced dynamically from active public model aliases (admin-enabled).
    'capabilities' => [
        'chat' => 'Chat completion',
        'structured_output' => 'Structured output',
        'tool_use' => 'Tool use',
        'embeddings' => 'Embeddings',
        'image_generation' => 'Image generation',
        'rerank' => 'Reranking',
    ],
    'environments' => [
        'development' => 'Development',
        'test' => 'Test',
        'production' => 'Production',
    ],
    'classifications' => [
        'public' => 'Public',
        'protected_a' => 'Protected A',
        'protected_b' => 'Protected B',
        'protected_c' => 'Protected C',
    ],

    // Credentials an application uses to obtain and present a bearer token when calling the gateway.
    'api_auth' => [
        'token_endpoint' => $tokenEndpoint,
        'audience' => env('APP_API_AUDIENCE'),
        'base_url' => env('APP_URL'),
        'issuer' => env('APP_API_ISSUER', $issuerDefault),
        'jwks_uri' => env('APP_API_JWKS_URI', $jwksDefault),
        'jwks_cache_seconds' => (int) env('APP_API_JWKS_CACHE', 3600),
        'leeway_seconds' => (int) env('APP_API_JWKS_LEEWAY', 60),
    ],

    // Maps a gateway operation to the model capability it requires.
    'operation_capabilities' => [
        'chat.completions' => 'chat',
        'embeddings' => 'embeddings',
        'responses' => 'structured_output',
        'images.generations' => 'image_generation',
        'rerank' => 'rerank',
    ],
];
