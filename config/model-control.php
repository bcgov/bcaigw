<?php

return [
    'allow_encrypted_secret_fallback' => (bool) env('MODEL_CONTROL_ALLOW_ENCRYPTED_FALLBACK', false),
    'provider_configuration_keys' => [
        'aws_bedrock' => ['account_id', 'role_arn'],
        'azure_ai_foundry' => ['tenant_id', 'subscription_id', 'resource_group', 'project_name', 'authentication'],
        'openai_vllm' => ['organization', 'description'],
    ],
    'endpoint' => [
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MODEL_CONTROL_ALLOWED_HOSTS', '')),
        ))),
        'allow_private_development_endpoints' => (bool) env(
            'MODEL_CONTROL_ALLOW_PRIVATE_DEV_ENDPOINTS',
            false,
        ),
        'allow_http_development_endpoints' => (bool) env(
            'MODEL_CONTROL_ALLOW_HTTP_DEV_ENDPOINTS',
            false,
        ),
    ],
];
