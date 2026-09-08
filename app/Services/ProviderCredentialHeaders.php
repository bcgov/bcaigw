<?php

namespace App\Services;

use App\Contracts\PlatformSecretResolver;
use App\DTO\ProviderRoutingContext;
use App\Enums\ProviderType;

final readonly class ProviderCredentialHeaders
{
    public function __construct(private PlatformSecretResolver $secrets) {}

    /**
     * @return array<string, string>
     */
    public function forProvider(ProviderRoutingContext $provider): array
    {
        if ($provider->type === ProviderType::AwsBedrock) {
            return [];
        }

        $secret = $this->secrets->resolve($provider->secretReference);
        if ($secret === null) {
            return [];
        }

        if ($provider->type === ProviderType::AzureAiFoundry
            && ($provider->configuration['authentication'] ?? 'api_key') === 'api_key') {
            return ['api-key' => $secret];
        }

        return ['Authorization' => 'Bearer '.$secret];
    }
}
