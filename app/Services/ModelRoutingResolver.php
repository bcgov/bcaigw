<?php

namespace App\Services;

use App\Auth\MachinePrincipal;
use App\DTO\PricingDecision;
use App\DTO\ProviderRoutingContext;
use App\DTO\RoutingDecision;
use App\Enums\ApplicationStatus;
use App\Enums\ControlPlaneStatus;
use App\Enums\RoutingFailure;
use App\Exceptions\ModelRoutingException;
use App\Models\Application;
use App\Models\ApplicationModelGrant;
use Illuminate\Validation\ValidationException;

final readonly class ModelRoutingResolver
{
    public function __construct(private EndpointSecurityPolicy $endpointPolicy) {}

    public function resolve(
        MachinePrincipal $principal,
        string $modelId,
        string $capability,
    ): RoutingDecision {
        $application = Application::query()->find($principal->application->id);
        if ($application === null
            || $application->status !== ApplicationStatus::Active
            || ! $application->configuration_ready) {
            throw new ModelRoutingException(RoutingFailure::ApplicationInactive);
        }

        $grant = ApplicationModelGrant::query()
            ->with(['alias.activeTarget.provider'])
            ->where('application_id', $application->id)
            ->where('enabled', true)
            ->whereHas('alias', fn ($query) => $query->where('model_id', $modelId))
            ->first();
        if ($grant === null) {
            throw new ModelRoutingException(RoutingFailure::ModelNotGranted);
        }

        $alias = $grant->alias;
        if ($alias->status !== ControlPlaneStatus::Active || $alias->activeTarget === null) {
            throw new ModelRoutingException(RoutingFailure::AliasUnavailable);
        }
        if (! in_array($capability, $grant->capabilities, true)
            || ! in_array($capability, $alias->capabilities, true)) {
            throw new ModelRoutingException(RoutingFailure::CapabilityNotGranted);
        }

        $target = $alias->activeTarget;
        if ($target->status !== ControlPlaneStatus::Active
            || ! in_array($capability, $target->capabilities, true)) {
            throw new ModelRoutingException(RoutingFailure::TargetUnavailable);
        }
        $provider = $target->provider;
        if ($provider->status !== ControlPlaneStatus::Active) {
            throw new ModelRoutingException(RoutingFailure::ProviderUnavailable);
        }
        try {
            $endpoint = $this->endpointPolicy->assertAllowed($target->base_url);
        } catch (ValidationException) {
            throw new ModelRoutingException(RoutingFailure::TargetUnavailable);
        }

        $pricing = $alias->pricingVersions()
            ->where('effective_at', '<=', now())
            ->latest('effective_at')
            ->first();
        if ($pricing === null) {
            throw new ModelRoutingException(RoutingFailure::PricingUnavailable);
        }

        return new RoutingDecision(
            applicationPublicId: $application->public_id,
            modelId: $alias->model_id,
            targetPublicId: $target->public_id,
            baseUrl: $target->base_url,
            endpointHost: $endpoint->host,
            endpointPort: $endpoint->port,
            resolvedAddresses: $endpoint->addresses,
            providerModelIdentifier: $target->provider_model_identifier,
            capabilities: $grant->capabilities,
            contextWindow: $target->context_window,
            maxInputTokens: $target->max_input_tokens,
            maxOutputTokens: $target->max_output_tokens,
            timeoutSeconds: $target->timeout_seconds,
            connectionSettings: $target->connection_settings ?? [],
            provider: new ProviderRoutingContext(
                publicId: $provider->public_id,
                type: $provider->type,
                environment: $provider->environment,
                region: $provider->region,
                secretReference: $provider->secret_reference,
                configuration: $provider->configuration ?? [],
            ),
            pricing: new PricingDecision(
                versionPublicId: $pricing->public_id,
                effectiveAt: $pricing->effective_at->toDateTimeImmutable(),
                currency: $pricing->currency,
                inputCostPerMillionTokens: $pricing->input_cost_per_million_tokens,
                outputCostPerMillionTokens: $pricing->output_cost_per_million_tokens,
                cachedInputCostPerMillionTokens: $pricing->cached_input_cost_per_million_tokens,
            ),
            aliasConfigurationVersion: $alias->configuration_version,
            targetConfigurationVersion: $target->configuration_version,
            grantConfigurationVersion: $grant->configuration_version,
        );
    }
}
