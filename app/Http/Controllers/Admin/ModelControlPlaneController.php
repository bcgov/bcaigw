<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationEnvironment;
use App\Enums\ControlPlaneStatus;
use App\Enums\ModelCapability;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplicationModelGrantRequest;
use App\Http\Requests\ModelPricingRequest;
use App\Http\Requests\ProviderAccountRequest;
use App\Http\Requests\PublicModelAliasRequest;
use App\Http\Requests\UpstreamTargetRequest;
use App\Models\Application;
use App\Models\ApplicationModelGrant;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\UpstreamTarget;
use App\Services\ModelControlPlaneService;
use App\Services\TargetHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ModelControlPlaneController extends Controller
{
    public function index(): Response
    {
        $providers = ProviderAccount::query()->latest()->get()->map(fn (ProviderAccount $provider) => [
            'public_id' => $provider->public_id,
            'name' => $provider->name,
            'type' => $provider->type->value,
            'environment' => $provider->environment->value,
            'region' => $provider->region,
            'secret_reference' => $provider->secret_reference,
            'configuration' => $provider->configuration ?? [],
            'has_encrypted_fallback' => filled($provider->sensitive_configuration),
            'status' => $provider->status->value,
            'configuration_version' => $provider->configuration_version,
        ]);
        $targets = UpstreamTarget::query()->with('provider')->latest()->get()
            ->map(fn (UpstreamTarget $target) => [
                'public_id' => $target->public_id,
                'provider_public_id' => $target->provider->public_id,
                'provider_name' => $target->provider->name,
                'name' => $target->name,
                'environment' => $target->environment->value,
                'region' => $target->region,
                'base_url' => $target->base_url,
                'provider_model_identifier' => $target->provider_model_identifier,
                'capabilities' => $target->capabilities,
                'context_window' => $target->context_window,
                'max_input_tokens' => $target->max_input_tokens,
                'max_output_tokens' => $target->max_output_tokens,
                'health_status' => $target->health_status->value,
                'status' => $target->status->value,
                'timeout_seconds' => $target->timeout_seconds,
                'connection_settings' => $target->connection_settings,
                'configuration_version' => $target->configuration_version,
                'last_health_checked_at' => $target->last_health_checked_at,
            ]);
        $aliases = PublicModelAlias::query()
            ->with(['activeTarget', 'pricingVersions' => fn ($query) => $query->latest('effective_at')])
            ->latest()
            ->get()
            ->map(fn (PublicModelAlias $alias) => [
                'public_id' => $alias->public_id,
                'model_id' => $alias->model_id,
                'display_name' => $alias->display_name,
                'description' => $alias->description,
                'capabilities' => $alias->capabilities,
                'target_public_id' => $alias->activeTarget?->public_id,
                'target_name' => $alias->activeTarget?->name,
                'status' => $alias->status->value,
                'configuration_version' => $alias->configuration_version,
                'pricing_versions' => $alias->pricingVersions,
            ]);
        $grants = ApplicationModelGrant::query()->with(['application', 'alias'])->latest()->get()
            ->map(fn (ApplicationModelGrant $grant) => [
                'public_id' => $grant->public_id,
                'application_public_id' => $grant->application->public_id,
                'application_name' => $grant->application->name,
                'model_alias_public_id' => $grant->alias->public_id,
                'model_id' => $grant->alias->model_id,
                'capabilities' => $grant->capabilities,
                'enabled' => $grant->enabled,
                'configuration_version' => $grant->configuration_version,
            ]);

        return Inertia::render('Portal/ModelControl/Index', [
            'providers' => $providers,
            'targets' => $targets,
            'aliases' => $aliases,
            'grants' => $grants,
            'applications' => Application::query()
                ->select(['public_id', 'name', 'status'])
                ->orderBy('name')
                ->get(),
            'options' => [
                'providerTypes' => array_column(ProviderType::cases(), 'value'),
                'environments' => array_column(ApplicationEnvironment::cases(), 'value'),
                'statuses' => array_column(ControlPlaneStatus::cases(), 'value'),
                'capabilities' => array_column(ModelCapability::cases(), 'value'),
            ],
        ]);
    }

    public function storeProvider(
        ProviderAccountRequest $request,
        ModelControlPlaneService $controlPlane,
    ): RedirectResponse {
        $controlPlane->createProvider($this->providerData($request), $request->user(), $request);

        return back();
    }

    public function updateProvider(
        ProviderAccountRequest $request,
        ProviderAccount $providerAccount,
        ModelControlPlaneService $controlPlane,
    ): RedirectResponse {
        $data = $this->providerData($request);
        $version = $data['configuration_version'];
        unset($data['configuration_version']);
        $controlPlane->updateProvider($providerAccount, $data, $version, $request->user(), $request);

        return back();
    }

    public function storeTarget(
        UpstreamTargetRequest $request,
        ModelControlPlaneService $controlPlane,
    ): RedirectResponse {
        $controlPlane->createTarget($this->targetData($request), $request->user(), $request);

        return back();
    }

    public function updateTarget(
        UpstreamTargetRequest $request,
        UpstreamTarget $upstreamTarget,
        ModelControlPlaneService $controlPlane,
    ): RedirectResponse {
        $data = $this->targetData($request);
        $version = $data['configuration_version'];
        unset($data['configuration_version']);
        $controlPlane->updateTarget($upstreamTarget, $data, $version, $request->user(), $request);

        return back();
    }

    public function checkTarget(
        Request $request,
        UpstreamTarget $upstreamTarget,
        TargetHealthService $health,
    ): RedirectResponse {
        $this->authorize('access-admin');
        $health->check($upstreamTarget, $request->user(), $request);

        return back();
    }

    public function storeAlias(
        PublicModelAliasRequest $request,
        ModelControlPlaneService $controlPlane,
    ): RedirectResponse {
        $controlPlane->createAlias($request->validated(), $request->user(), $request);

        return back();
    }

    public function updateAlias(
        PublicModelAliasRequest $request,
        PublicModelAlias $publicModelAlias,
        ModelControlPlaneService $controlPlane,
    ): RedirectResponse {
        $data = $request->validated();
        $version = $data['configuration_version'];
        unset($data['configuration_version']);
        $controlPlane->updateAlias($publicModelAlias, $data, $version, $request->user(), $request);

        return back();
    }

    public function storePricing(
        ModelPricingRequest $request,
        PublicModelAlias $publicModelAlias,
        ModelControlPlaneService $controlPlane,
    ): RedirectResponse {
        $controlPlane->createPricing($publicModelAlias, $request->validated(), $request->user(), $request);

        return back();
    }

    public function upsertGrant(
        ApplicationModelGrantRequest $request,
        ModelControlPlaneService $controlPlane,
    ): RedirectResponse {
        $controlPlane->upsertGrant($request->validated(), $request->user(), $request);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function providerData(ProviderAccountRequest $request): array
    {
        $data = $request->safe()->except([
            'configuration_json',
            'sensitive_configuration_json',
        ]);
        $data['configuration'] = json_decode(
            $request->validated('configuration_json') ?: '{}',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (filled($request->validated('sensitive_configuration_json'))) {
            $data['sensitive_configuration'] = json_decode(
                $request->validated('sensitive_configuration_json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function targetData(UpstreamTargetRequest $request): array
    {
        $data = $request->safe()->except(['verify_tls', 'max_connections']);
        $data['connection_settings'] = [
            'verify_tls' => true,
            'max_connections' => $request->integer('max_connections'),
        ];

        return $data;
    }
}
