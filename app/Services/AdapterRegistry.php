<?php

namespace App\Services;

use App\Contracts\UpstreamModelAdapter;
use App\Enums\ProviderType;
use App\Exceptions\GatewayException;

final readonly class AdapterRegistry
{
    /**
     * @param  list<UpstreamModelAdapter>  $adapters
     */
    public function __construct(private array $adapters) {}

    public function for(ProviderType $type): UpstreamModelAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->providerType() === $type) {
                return $adapter;
            }
        }

        throw new GatewayException(
            'server_error',
            'provider_adapter_unavailable',
            503,
            'The selected model provider is temporarily unavailable.',
        );
    }
}
