<?php

namespace App\Services;

use App\Contracts\BedrockRuntimeGateway;
use App\Contracts\CanonicalGatewayRequest;
use App\Contracts\UpstreamModelAdapter;
use App\DTO\AdapterResult;
use App\DTO\RoutingDecision;
use App\Enums\ProviderType;

final readonly class AwsBedrockAdapter implements UpstreamModelAdapter
{
    public function __construct(private BedrockRuntimeGateway $gateway) {}

    public function providerType(): ProviderType
    {
        return ProviderType::AwsBedrock;
    }

    public function invoke(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
    ): AdapterResult {
        return $this->gateway->invoke($decision, $request);
    }

    public function stream(
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
    ): iterable {
        return $this->gateway->stream($decision, $request);
    }
}
