<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Enums\ControlPlaneStatus;
use App\Models\Application;
use App\Models\ApplicationModelGrant;
use App\Models\ModelAliasTargetVersion;
use App\Models\ModelPricingVersion;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\UpstreamTarget;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ModelControlPlaneService
{
    public function __construct(
        private EndpointSecurityPolicy $endpointPolicy,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createProvider(array $data, User $actor, Request $request): ProviderAccount
    {
        $this->assertNonSensitiveConfiguration($data['type'], $data['configuration'] ?? []);
        $this->assertSensitiveConfigurationAllowed($data);
        if ($data['status'] === ControlPlaneStatus::Retired->value) {
            throw ValidationException::withMessages(['status' => 'Create the provider as active or disabled.']);
        }

        return DB::transaction(function () use ($data, $actor, $request): ProviderAccount {
            $provider = ProviderAccount::create([
                ...$data,
                'configuration_version' => 1,
                'created_by' => $actor->id,
            ]);
            $this->auditConfiguration(
                $request,
                AuditEventType::ProviderConfigured,
                $actor,
                'provider_public_id',
                $provider->public_id,
                $provider->configuration_version,
                [
                    'credential_reference_present' => filled($provider->secret_reference),
                    'encrypted_fallback_present' => filled($provider->sensitive_configuration),
                ],
            );

            return $provider;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProvider(
        ProviderAccount $provider,
        array $data,
        int $expectedVersion,
        User $actor,
        Request $request,
    ): ProviderAccount {
        $this->assertNonSensitiveConfiguration($data['type'], $data['configuration'] ?? []);
        $this->assertSensitiveConfigurationAllowed($data);

        return DB::transaction(function () use (
            $provider,
            $data,
            $expectedVersion,
            $actor,
            $request,
        ): ProviderAccount {
            $locked = ProviderAccount::query()->lockForUpdate()->findOrFail($provider->id);
            $this->assertVersion($locked->configuration_version, $expectedVersion);
            if ($locked->status === ControlPlaneStatus::Retired) {
                throw ValidationException::withMessages(['status' => 'A retired provider cannot be changed.']);
            }
            if (($data['environment'] ?? $locked->environment->value) !== $locked->environment->value
                && $locked->targets()->where('status', '!=', ControlPlaneStatus::Retired->value)->exists()) {
                throw ValidationException::withMessages([
                    'environment' => 'Provider environment cannot change while non-retired targets exist.',
                ]);
            }
            if (($data['type'] ?? $locked->type->value) !== $locked->type->value
                && $locked->targets()->exists()) {
                throw ValidationException::withMessages([
                    'type' => 'Provider type cannot change after targets have been created.',
                ]);
            }
            if (($data['status'] ?? null) !== ControlPlaneStatus::Active->value
                && $locked->targets()
                    ->where('status', ControlPlaneStatus::Active->value)
                    ->whereHas('aliases', fn ($query) => $query->where('status', ControlPlaneStatus::Active->value))
                    ->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Disable active aliases before disabling or retiring their provider.',
                ]);
            }
            if (filled($data['secret_reference'] ?? null)
                && ! array_key_exists('sensitive_configuration', $data)
                && filled($locked->sensitive_configuration)) {
                throw ValidationException::withMessages([
                    'sensitive_configuration_json' => 'Clear the encrypted fallback with an empty JSON object when setting a platform secret reference.',
                ]);
            }

            $locked->forceFill([
                ...$data,
                'configuration_version' => $locked->configuration_version + 1,
            ])->save();
            $this->auditConfiguration(
                $request,
                AuditEventType::ProviderConfigured,
                $actor,
                'provider_public_id',
                $locked->public_id,
                $locked->configuration_version,
                [
                    'credential_reference_present' => filled($locked->secret_reference),
                    'encrypted_fallback_present' => filled($locked->sensitive_configuration),
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTarget(array $data, User $actor, Request $request): UpstreamTarget
    {
        $this->endpointPolicy->assertAllowed($data['base_url']);
        if ($data['status'] === ControlPlaneStatus::Retired->value) {
            throw ValidationException::withMessages(['status' => 'Create the target as active or disabled.']);
        }

        return DB::transaction(function () use ($data, $actor, $request): UpstreamTarget {
            $provider = ProviderAccount::query()
                ->where('public_id', $data['provider_public_id'])
                ->lockForUpdate()
                ->firstOrFail();
            if ($provider->status === ControlPlaneStatus::Retired) {
                throw ValidationException::withMessages(['provider_public_id' => 'The provider is retired.']);
            }
            if ($provider->environment->value !== $data['environment']) {
                throw ValidationException::withMessages([
                    'environment' => 'The target environment must match its provider account.',
                ]);
            }

            unset($data['provider_public_id']);
            $target = UpstreamTarget::create([
                ...$data,
                'provider_account_id' => $provider->id,
                'configuration_version' => 1,
                'created_by' => $actor->id,
            ]);
            $this->auditConfiguration(
                $request,
                AuditEventType::TargetConfigured,
                $actor,
                'target_public_id',
                $target->public_id,
                $target->configuration_version,
            );

            return $target;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTarget(
        UpstreamTarget $target,
        array $data,
        int $expectedVersion,
        User $actor,
        Request $request,
    ): UpstreamTarget {
        $this->endpointPolicy->assertAllowed($data['base_url']);

        return DB::transaction(function () use (
            $target,
            $data,
            $expectedVersion,
            $actor,
            $request,
        ): UpstreamTarget {
            $locked = UpstreamTarget::query()->lockForUpdate()->findOrFail($target->id);
            $this->assertVersion($locked->configuration_version, $expectedVersion);
            if ($locked->status === ControlPlaneStatus::Retired) {
                throw ValidationException::withMessages(['status' => 'A retired target cannot be changed.']);
            }

            $provider = ProviderAccount::query()
                ->where('public_id', $data['provider_public_id'])
                ->firstOrFail();
            if ($provider->status === ControlPlaneStatus::Retired) {
                throw ValidationException::withMessages(['provider_public_id' => 'The provider is retired.']);
            }
            if ($provider->environment->value !== $data['environment']) {
                throw ValidationException::withMessages([
                    'environment' => 'The target environment must match its provider account.',
                ]);
            }
            if (($data['status'] ?? null) !== ControlPlaneStatus::Active->value
                && $locked->aliases()->where('status', ControlPlaneStatus::Active->value)->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Disable or remap active model aliases before disabling this target.',
                ]);
            }

            $requiredCapabilities = $locked->aliases()
                ->get(['capabilities'])
                ->flatMap(fn (PublicModelAlias $alias) => $alias->capabilities)
                ->unique()
                ->values()
                ->all();
            if (array_diff($requiredCapabilities, $data['capabilities']) !== []) {
                throw ValidationException::withMessages([
                    'capabilities' => 'Target capabilities cannot remove capabilities used by mapped aliases.',
                ]);
            }

            unset($data['provider_public_id']);
            $locked->forceFill([
                ...$data,
                'provider_account_id' => $provider->id,
                'configuration_version' => $locked->configuration_version + 1,
            ])->save();
            $this->auditConfiguration(
                $request,
                AuditEventType::TargetConfigured,
                $actor,
                'target_public_id',
                $locked->public_id,
                $locked->configuration_version,
            );

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAlias(array $data, User $actor, Request $request): PublicModelAlias
    {
        if ($data['status'] === ControlPlaneStatus::Retired->value) {
            throw ValidationException::withMessages(['status' => 'Create the alias as active or disabled.']);
        }

        return DB::transaction(function () use ($data, $actor, $request): PublicModelAlias {
            $target = $this->targetFromPublicId($data['target_public_id'] ?? null);
            $this->assertAliasConfiguration($data, $target);
            unset($data['target_public_id']);

            $alias = PublicModelAlias::create([
                ...$data,
                'active_target_id' => $target?->id,
                'configuration_version' => 1,
                'created_by' => $actor->id,
            ]);
            $this->appendMappingVersion($alias, $target, $actor);
            $this->auditConfiguration(
                $request,
                AuditEventType::ModelAliasConfigured,
                $actor,
                'model_alias_public_id',
                $alias->public_id,
                $alias->configuration_version,
            );

            return $alias;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAlias(
        PublicModelAlias $alias,
        array $data,
        int $expectedVersion,
        User $actor,
        Request $request,
    ): PublicModelAlias {
        return DB::transaction(function () use (
            $alias,
            $data,
            $expectedVersion,
            $actor,
            $request,
        ): PublicModelAlias {
            $locked = PublicModelAlias::query()->lockForUpdate()->findOrFail($alias->id);
            $this->assertVersion($locked->configuration_version, $expectedVersion);
            if ($locked->status === ControlPlaneStatus::Retired) {
                throw ValidationException::withMessages(['status' => 'A retired alias cannot be changed.']);
            }

            $target = $this->targetFromPublicId($data['target_public_id'] ?? null);
            $this->assertAliasConfiguration($data, $target);
            $requiredGrantCapabilities = $locked->grants()
                ->where('enabled', true)
                ->get(['capabilities'])
                ->flatMap(fn (ApplicationModelGrant $grant) => $grant->capabilities)
                ->unique()
                ->all();
            if (array_diff($requiredGrantCapabilities, $data['capabilities']) !== []) {
                throw ValidationException::withMessages([
                    'capabilities' => 'Alias capabilities cannot remove capabilities used by enabled grants.',
                ]);
            }
            $mappingChanged = $locked->active_target_id !== $target?->id;
            $oldTarget = $locked->activeTarget?->public_id;
            unset($data['target_public_id']);
            $locked->forceFill([
                ...$data,
                'active_target_id' => $target?->id,
                'configuration_version' => $locked->configuration_version + 1,
            ])->save();

            if ($mappingChanged) {
                $this->appendMappingVersion($locked, $target, $actor);
                $this->audit->record(
                    $request,
                    AuditEventType::ModelAliasMapped,
                    AuditOutcome::Succeeded,
                    actor: $actor,
                    context: [
                        'model_alias_public_id' => $locked->public_id,
                        'from_target_public_id' => $oldTarget,
                        'to_target_public_id' => $target?->public_id,
                        'configuration_version' => $locked->configuration_version,
                    ],
                );
            }
            $this->auditConfiguration(
                $request,
                AuditEventType::ModelAliasConfigured,
                $actor,
                'model_alias_public_id',
                $locked->public_id,
                $locked->configuration_version,
            );

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPricing(
        PublicModelAlias $alias,
        array $data,
        User $actor,
        Request $request,
    ): ModelPricingVersion {
        return DB::transaction(function () use ($alias, $data, $actor, $request): ModelPricingVersion {
            $locked = PublicModelAlias::query()->lockForUpdate()->findOrFail($alias->id);
            $latestEffectiveAt = $locked->pricingVersions()->max('effective_at');
            if ($latestEffectiveAt !== null
                && strtotime($data['effective_at']) <= strtotime((string) $latestEffectiveAt)) {
                throw ValidationException::withMessages([
                    'effective_at' => 'A pricing version must take effect after the latest existing version.',
                ]);
            }
            $pricing = ModelPricingVersion::create([
                ...$data,
                'currency' => strtoupper($data['currency']),
                'public_model_alias_id' => $locked->id,
                'created_by' => $actor->id,
            ]);
            $this->audit->record(
                $request,
                AuditEventType::ModelPricingCreated,
                AuditOutcome::Succeeded,
                actor: $actor,
                context: [
                    'model_alias_public_id' => $locked->public_id,
                    'pricing_public_id' => $pricing->public_id,
                    'effective_at' => $pricing->effective_at->toIso8601String(),
                    'currency' => $pricing->currency,
                ],
            );

            return $pricing;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertGrant(array $data, User $actor, Request $request): ApplicationModelGrant
    {
        return DB::transaction(function () use ($data, $actor, $request): ApplicationModelGrant {
            $application = Application::query()
                ->where('public_id', $data['application_public_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $alias = PublicModelAlias::query()
                ->with('activeTarget')
                ->where('public_id', $data['model_alias_public_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $enabled = (bool) $data['enabled'];
            if ($enabled && $application->status !== ApplicationStatus::Active) {
                throw ValidationException::withMessages([
                    'application_public_id' => 'Only active applications can receive enabled model grants.',
                ]);
            }
            if ($enabled && ($alias->status !== ControlPlaneStatus::Active
                || $alias->activeTarget?->status !== ControlPlaneStatus::Active)) {
                throw ValidationException::withMessages([
                    'model_alias_public_id' => 'Only an active, mapped model alias can be enabled.',
                ]);
            }
            if ($enabled && (array_diff($data['capabilities'], $alias->capabilities) !== []
                || array_diff($data['capabilities'], $alias->activeTarget?->capabilities ?? []) !== [])) {
                throw ValidationException::withMessages([
                    'capabilities' => 'Grant capabilities must be supported by the alias and target.',
                ]);
            }

            $grant = ApplicationModelGrant::query()
                ->where('application_id', $application->id)
                ->where('public_model_alias_id', $alias->id)
                ->lockForUpdate()
                ->first();
            if ($grant !== null) {
                $this->assertVersion(
                    $grant->configuration_version,
                    (int) $data['configuration_version'],
                );
                $grant->forceFill([
                    'capabilities' => $data['capabilities'],
                    'enabled' => $enabled,
                    'configuration_version' => $grant->configuration_version + 1,
                    'granted_by' => $actor->id,
                ])->save();
            } else {
                if ((int) $data['configuration_version'] !== 0) {
                    $this->assertVersion(0, (int) $data['configuration_version']);
                }
                $grant = ApplicationModelGrant::create([
                    'application_id' => $application->id,
                    'public_model_alias_id' => $alias->id,
                    'capabilities' => $data['capabilities'],
                    'enabled' => $enabled,
                    'configuration_version' => 1,
                    'granted_by' => $actor->id,
                ]);
            }

            $this->audit->record(
                $request,
                AuditEventType::ApplicationModelGrantChanged,
                AuditOutcome::Succeeded,
                actor: $actor,
                context: [
                    'application_public_id' => $application->public_id,
                    'model_alias_public_id' => $alias->public_id,
                    'grant_public_id' => $grant->public_id,
                    'enabled' => $grant->enabled,
                    'configuration_version' => $grant->configuration_version,
                ],
            );

            return $grant->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSensitiveConfigurationAllowed(array $data): void
    {
        if (! array_key_exists('sensitive_configuration', $data)
            || blank($data['sensitive_configuration'])) {
            return;
        }

        if (filled($data['secret_reference'] ?? null)) {
            throw ValidationException::withMessages([
                'sensitive_configuration_json' => 'Use the platform secret reference instead of duplicate fallback credentials.',
            ]);
        }
        if (! config('model-control.allow_encrypted_secret_fallback')) {
            throw ValidationException::withMessages([
                'sensitive_configuration_json' => 'Encrypted fallback credentials are disabled. Use workload identity or a platform secret reference.',
            ]);
        }
    }

    /**
     * @param  array<string, bool|int|string|null>  $configuration
     */
    private function assertNonSensitiveConfiguration(string $providerType, array $configuration): void
    {
        $allowedKeys = config("model-control.provider_configuration_keys.{$providerType}", []);
        foreach ($configuration as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true) || is_array($value)) {
                throw ValidationException::withMessages([
                    'configuration_json' => 'Provider configuration contains a key or value that is not explicitly non-sensitive for this provider type.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertAliasConfiguration(array $data, ?UpstreamTarget $target): void
    {
        if (($data['status'] ?? null) === ControlPlaneStatus::Active->value
            && ($target === null || $target->status !== ControlPlaneStatus::Active)) {
            throw ValidationException::withMessages([
                'target_public_id' => 'An active alias must map to exactly one active target.',
            ]);
        }
        if ($target !== null && array_diff($data['capabilities'], $target->capabilities) !== []) {
            throw ValidationException::withMessages([
                'capabilities' => 'Alias capabilities must be supported by the mapped target.',
            ]);
        }
    }

    private function targetFromPublicId(?string $publicId): ?UpstreamTarget
    {
        return filled($publicId)
            ? UpstreamTarget::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail()
            : null;
    }

    private function appendMappingVersion(
        PublicModelAlias $alias,
        ?UpstreamTarget $target,
        User $actor,
    ): void {
        ModelAliasTargetVersion::create([
            'public_model_alias_id' => $alias->id,
            'upstream_target_id' => $target?->id,
            'configuration_version' => $alias->configuration_version,
            'effective_at' => now(),
            'changed_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, bool|int|string|null>  $extra
     */
    private function auditConfiguration(
        Request $request,
        AuditEventType $eventType,
        User $actor,
        string $identifierKey,
        string $identifier,
        int $version,
        array $extra = [],
    ): void {
        $this->audit->record(
            $request,
            $eventType,
            AuditOutcome::Succeeded,
            actor: $actor,
            context: [
                $identifierKey => $identifier,
                'configuration_version' => $version,
                ...$extra,
            ],
        );
    }

    private function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages([
                'configuration_version' => 'The configuration changed since it was loaded. Refresh and try again.',
            ]);
        }
    }
}
