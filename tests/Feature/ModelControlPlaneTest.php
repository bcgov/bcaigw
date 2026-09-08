<?php

namespace Tests\Feature;

use App\Auth\MachinePrincipal;
use App\Contracts\HostResolver;
use App\Enums\ApplicationMemberRole;
use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\ControlPlaneStatus;
use App\Enums\PortalRole;
use App\Enums\RoutingFailure;
use App\Enums\TargetHealthStatus;
use App\Exceptions\ModelRoutingException;
use App\Models\Application;
use App\Models\ApplicationModelGrant;
use App\Models\MachineAccessToken;
use App\Models\MachineCredential;
use App\Models\ModelAliasTargetVersion;
use App\Models\ModelPricingVersion;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\SecurityAuditEvent;
use App\Models\UpstreamTarget;
use App\Models\User;
use App\Services\ModelRoutingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class ModelControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function addresses(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
    }

    public function test_provider_fallback_is_encrypted_hidden_and_redacted_from_audit(): void
    {
        config(['model-control.allow_encrypted_secret_fallback' => true]);
        $administrator = $this->administrator();
        $secret = 'provider-secret-value';

        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.providers.store'), [
                ...$this->providerData(),
                'secret_reference' => null,
                'sensitive_configuration_json' => json_encode(['api_key' => $secret]),
            ])
            ->assertRedirect();

        $provider = ProviderAccount::query()->firstOrFail();
        $raw = DB::table('provider_accounts')
            ->where('id', $provider->id)
            ->value('sensitive_configuration');

        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, $provider->sensitive_configuration['api_key']);
        $this->assertArrayNotHasKey('sensitive_configuration', $provider->toArray());
        $audit = SecurityAuditEvent::query()
            ->where('event_type', AuditEventType::ProviderConfigured)
            ->firstOrFail();
        $this->assertStringNotContainsString($secret, json_encode($audit->context));
    }

    public function test_encrypted_fallback_requires_explicit_enablement_and_no_secret_reference(): void
    {
        $administrator = $this->administrator();
        $payload = [
            ...$this->providerData(),
            'sensitive_configuration_json' => '{"api_key":"secret"}',
        ];

        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.providers.store'), $payload)
            ->assertSessionHasErrors('sensitive_configuration_json');

        config(['model-control.allow_encrypted_secret_fallback' => true]);
        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.providers.store'), [
                ...$payload,
                'secret_reference' => 'platform/provider-secret',
            ])
            ->assertSessionHasErrors('sensitive_configuration_json');
    }

    public function test_sensitive_keys_are_rejected_from_plain_provider_configuration(): void
    {
        $this->actingAs($this->administrator())
            ->post(route('portal.admin.model-control.providers.store'), [
                ...$this->providerData(),
                'configuration_json' => '{"api_key":{"value":"plaintext"}}',
            ])
            ->assertSessionHasErrors('configuration_json');

        $this->assertDatabaseCount('provider_accounts', 0);
    }

    public function test_alias_has_exactly_one_active_target_and_mapping_is_versioned_transactionally(): void
    {
        $administrator = $this->administrator();
        $first = UpstreamTarget::factory()->create();
        $second = UpstreamTarget::factory()->create();

        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.aliases.store'), [
                ...$this->aliasData(),
                'target_public_id' => null,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('target_public_id');

        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.aliases.store'), [
                ...$this->aliasData(),
                'target_public_id' => $first->public_id,
            ])
            ->assertRedirect();
        $alias = PublicModelAlias::query()->firstOrFail();
        $this->assertSame($first->id, $alias->active_target_id);
        $this->assertDatabaseCount('model_alias_target_versions', 1);

        $this->actingAs($administrator)
            ->patch(route('portal.admin.model-control.aliases.update', $alias), [
                ...$this->aliasData(),
                'target_public_id' => $second->public_id,
                'configuration_version' => 1,
            ])
            ->assertRedirect();

        $alias->refresh();
        $this->assertSame($second->id, $alias->active_target_id);
        $this->assertSame(2, $alias->configuration_version);
        $this->assertDatabaseCount('model_alias_target_versions', 2);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::ModelAliasMapped->value,
        ]);
    }

    public function test_alias_and_grant_capabilities_cannot_exceed_target(): void
    {
        $administrator = $this->administrator();
        $target = UpstreamTarget::factory()->create(['capabilities' => ['chat']]);

        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.aliases.store'), [
                ...$this->aliasData(),
                'capabilities' => ['chat', 'embeddings'],
                'target_public_id' => $target->public_id,
            ])
            ->assertSessionHasErrors('capabilities');

        $alias = PublicModelAlias::factory()->create([
            'active_target_id' => $target->id,
            'capabilities' => ['chat'],
        ]);
        $application = Application::factory()->create(['status' => ApplicationStatus::Active]);
        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.grants.upsert'), [
                'application_public_id' => $application->public_id,
                'model_alias_public_id' => $alias->public_id,
                'capabilities' => ['embeddings'],
                'enabled' => true,
                'configuration_version' => 0,
            ])
            ->assertSessionHasErrors('capabilities');
    }

    public function test_referenced_target_cannot_retire_or_drop_mapped_capabilities(): void
    {
        $administrator = $this->administrator();
        $target = UpstreamTarget::factory()->create(['capabilities' => ['chat', 'streaming']]);
        PublicModelAlias::factory()->create([
            'active_target_id' => $target->id,
            'capabilities' => ['chat', 'streaming'],
        ]);

        $this->actingAs($administrator)
            ->patch(
                route('portal.admin.model-control.targets.update', $target),
                $this->targetData($target, status: 'retired'),
            )
            ->assertSessionHasErrors('status');
        $this->actingAs($administrator)
            ->patch(
                route('portal.admin.model-control.targets.update', $target),
                $this->targetData($target, status: 'disabled'),
            )
            ->assertSessionHasErrors('status');
        $this->actingAs($administrator)
            ->patch(
                route('portal.admin.model-control.targets.update', $target),
                $this->targetData($target, capabilities: ['chat']),
            )
            ->assertSessionHasErrors('capabilities');
    }

    public function test_stale_configuration_update_is_rejected(): void
    {
        $administrator = $this->administrator();
        $provider = ProviderAccount::factory()->create(['configuration_version' => 2]);

        $this->actingAs($administrator)
            ->patch(route('portal.admin.model-control.providers.update', $provider), [
                ...$this->providerData(),
                'configuration_version' => 1,
            ])
            ->assertSessionHasErrors('configuration_version');

        $this->assertSame(2, $provider->refresh()->configuration_version);
    }

    public function test_provider_cannot_disable_active_routes_or_change_environment_with_targets(): void
    {
        $administrator = $this->administrator();
        $target = UpstreamTarget::factory()->create();
        PublicModelAlias::factory()->create(['active_target_id' => $target->id]);

        $this->actingAs($administrator)
            ->patch(route('portal.admin.model-control.providers.update', $target->provider), [
                ...$this->providerData(),
                'status' => 'disabled',
                'configuration_version' => 1,
            ])
            ->assertSessionHasErrors('status');
        $this->actingAs($administrator)
            ->patch(route('portal.admin.model-control.providers.update', $target->provider), [
                ...$this->providerData(),
                'environment' => 'production',
                'configuration_version' => 1,
            ])
            ->assertSessionHasErrors('environment');
    }

    public function test_only_active_applications_receive_enabled_grants_and_updates_are_versioned(): void
    {
        $administrator = $this->administrator();
        $alias = $this->routableAlias();
        $draft = Application::factory()->create(['status' => ApplicationStatus::Draft]);
        $active = Application::factory()->create(['status' => ApplicationStatus::Active]);
        $payload = [
            'model_alias_public_id' => $alias->public_id,
            'capabilities' => ['chat'],
            'enabled' => true,
            'configuration_version' => 0,
        ];

        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.grants.upsert'), [
                ...$payload,
                'application_public_id' => $draft->public_id,
            ])
            ->assertSessionHasErrors('application_public_id');
        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.grants.upsert'), [
                ...$payload,
                'application_public_id' => $active->public_id,
            ])
            ->assertRedirect();

        $grant = ApplicationModelGrant::query()->firstOrFail();
        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.grants.upsert'), [
                ...$payload,
                'application_public_id' => $active->public_id,
                'enabled' => false,
                'configuration_version' => 1,
            ])
            ->assertRedirect();
        $this->assertFalse($grant->refresh()->enabled);
        $this->assertSame(2, $grant->configuration_version);
    }

    public function test_owner_sees_granted_alias_but_not_provider_internals_and_cannot_administer(): void
    {
        $owner = User::factory()->create();
        $application = Application::factory()->create([
            'created_by' => $owner->id,
            'status' => ApplicationStatus::Active,
        ]);
        $application->users()->attach($owner, [
            'role' => ApplicationMemberRole::Owner->value,
            'created_by' => $owner->id,
        ]);
        $alias = $this->routableAlias();
        ApplicationModelGrant::factory()->create([
            'application_id' => $application->id,
            'public_model_alias_id' => $alias->id,
            'granted_by' => $this->administrator()->id,
        ]);

        $this->actingAs($owner)
            ->get(route('portal.applications.show', $application))
            ->assertOk()
            ->assertSee($alias->display_name)
            ->assertDontSee('models.example.com')
            ->assertDontSee('platform/model-provider');
        $this->actingAs($owner)
            ->get(route('portal.admin.model-control.index'))
            ->assertForbidden();
    }

    public function test_pricing_versions_are_effective_dated_and_immutable(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $alias = $this->routableAlias(withPricing: false);
        $older = ModelPricingVersion::factory()->create([
            'public_model_alias_id' => $alias->id,
            'effective_at' => now()->subDay(),
            'input_cost_per_million_tokens' => '1.00000000',
        ]);
        ModelPricingVersion::factory()->create([
            'public_model_alias_id' => $alias->id,
            'effective_at' => now()->addDay(),
            'input_cost_per_million_tokens' => '9.00000000',
        ]);
        [$principal] = $this->principalWithGrant($alias);

        $decision = app(ModelRoutingResolver::class)->resolve($principal, $alias->model_id, 'chat');
        $this->assertSame($older->public_id, $decision->pricing->versionPublicId);
        $this->assertSame('1.00000000', $decision->pricing->inputCostPerMillionTokens);

        $this->expectException(LogicException::class);
        $older->forceFill(['currency' => 'USD'])->save();
    }

    public function test_new_pricing_cannot_retroactively_precede_existing_version(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $administrator = $this->administrator();
        $alias = $this->routableAlias(withPricing: false);
        ModelPricingVersion::factory()->create([
            'public_model_alias_id' => $alias->id,
            'effective_at' => now()->addDay(),
        ]);

        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.pricing.store', $alias), [
                'effective_at' => now()->toIso8601String(),
                'currency' => 'CAD',
                'input_cost_per_million_tokens' => 1,
                'output_cost_per_million_tokens' => 2,
                'cached_input_cost_per_million_tokens' => 0.5,
            ])
            ->assertSessionHasErrors('effective_at');
    }

    public function test_resolver_returns_typed_secret_free_decision_and_reflects_mapping_immediately(): void
    {
        $administrator = $this->administrator();
        $provider = ProviderAccount::factory()->create([
            'secret_reference' => 'platform/provider',
            'sensitive_configuration' => ['api_key' => 'must-not-leak'],
        ]);
        $first = UpstreamTarget::factory()->create(['provider_account_id' => $provider->id]);
        $second = UpstreamTarget::factory()->create(['provider_account_id' => $provider->id]);
        $alias = $this->routableAlias(target: $first, withPricing: true);
        [$principal] = $this->principalWithGrant($alias);
        $resolver = app(ModelRoutingResolver::class);

        $firstDecision = $resolver->resolve($principal, $alias->model_id, 'chat');
        $this->assertSame($first->public_id, $firstDecision->targetPublicId);
        $this->assertSame(['8.8.8.8'], $firstDecision->resolvedAddresses);
        $this->assertStringNotContainsString('must-not-leak', serialize($firstDecision));

        ModelAliasTargetVersion::create([
            'public_model_alias_id' => $alias->id,
            'upstream_target_id' => $first->id,
            'configuration_version' => 1,
            'effective_at' => now()->subMinute(),
            'changed_by' => $administrator->id,
        ]);
        $this->actingAs($administrator)
            ->patch(route('portal.admin.model-control.aliases.update', $alias), [
                ...$this->aliasData(),
                'model_id' => $alias->model_id,
                'target_public_id' => $second->public_id,
                'configuration_version' => 1,
            ])
            ->assertRedirect();

        $secondDecision = $resolver->resolve($principal, $alias->model_id, 'chat');
        $this->assertSame($second->public_id, $secondDecision->targetPublicId);
    }

    public function test_resolver_returns_explicit_domain_failures(): void
    {
        $alias = $this->routableAlias();
        [$principal, $grant] = $this->principalWithGrant($alias);
        $grant->forceFill(['enabled' => false])->save();

        try {
            app(ModelRoutingResolver::class)->resolve($principal, $alias->model_id, 'chat');
            $this->fail('Expected routing failure.');
        } catch (ModelRoutingException $exception) {
            $this->assertSame(RoutingFailure::ModelNotGranted, $exception->failure);
        }
    }

    public function test_safe_health_check_updates_status_without_response_body_or_secrets(): void
    {
        Http::fake(['https://models.example.com/*' => Http::response('sensitive body', 401)]);
        $administrator = $this->administrator();
        $target = UpstreamTarget::factory()->create([
            'base_url' => 'https://models.example.com/v1',
        ]);

        $this->actingAs($administrator)
            ->post(route('portal.admin.model-control.targets.health', $target))
            ->assertRedirect();

        $this->assertSame(TargetHealthStatus::Healthy, $target->refresh()->health_status);
        $audit = SecurityAuditEvent::query()
            ->where('event_type', AuditEventType::TargetHealthChecked)
            ->firstOrFail();
        $this->assertStringNotContainsString('sensitive body', json_encode($audit->context));
    }

    private function administrator(): User
    {
        return User::factory()->create(['portal_role' => PortalRole::Administrator]);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerData(): array
    {
        return [
            'name' => 'Azure Canada',
            'type' => 'azure_ai_foundry',
            'environment' => 'development',
            'region' => 'canadacentral',
            'secret_reference' => null,
            'configuration_json' => '{"tenant_id":"00000000-0000-4000-8000-000000000001"}',
            'sensitive_configuration_json' => null,
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aliasData(): array
    {
        return [
            'model_id' => 'bcgov/gpt-4o',
            'display_name' => 'BC Gov GPT-4o',
            'description' => 'Approved general-purpose model',
            'capabilities' => ['chat'],
            'status' => 'active',
        ];
    }

    /**
     * @param  list<string>|null  $capabilities
     * @return array<string, mixed>
     */
    private function targetData(
        UpstreamTarget $target,
        ?array $capabilities = null,
        string $status = 'active',
    ): array {
        return [
            'provider_public_id' => $target->provider->public_id,
            'name' => $target->name,
            'environment' => $target->environment->value,
            'region' => $target->region,
            'base_url' => $target->base_url,
            'provider_model_identifier' => $target->provider_model_identifier,
            'capabilities' => $capabilities ?? $target->capabilities,
            'context_window' => $target->context_window,
            'max_input_tokens' => $target->max_input_tokens,
            'max_output_tokens' => $target->max_output_tokens,
            'status' => $status,
            'timeout_seconds' => $target->timeout_seconds,
            'verify_tls' => true,
            'max_connections' => 20,
            'configuration_version' => $target->configuration_version,
        ];
    }

    private function routableAlias(
        ?UpstreamTarget $target = null,
        bool $withPricing = true,
    ): PublicModelAlias {
        $target ??= UpstreamTarget::factory()->create();
        $alias = PublicModelAlias::factory()->create([
            'active_target_id' => $target->id,
            'capabilities' => ['chat'],
            'status' => ControlPlaneStatus::Active,
        ]);
        $alias->setRelation('activeTarget', $target);
        if ($withPricing) {
            ModelPricingVersion::factory()->create([
                'public_model_alias_id' => $alias->id,
                'effective_at' => now()->subMinute(),
            ]);
        }

        return $alias;
    }

    /**
     * @return array{MachinePrincipal, ApplicationModelGrant}
     */
    private function principalWithGrant(PublicModelAlias $alias): array
    {
        $application = Application::factory()->create([
            'status' => ApplicationStatus::Active,
            'configuration_ready' => true,
        ]);
        $grant = ApplicationModelGrant::factory()->create([
            'application_id' => $application->id,
            'public_model_alias_id' => $alias->id,
            'capabilities' => ['chat'],
            'enabled' => true,
        ]);
        $principal = new MachinePrincipal(
            $application,
            new MachineCredential,
            new MachineAccessToken,
            ['gateway.invoke'],
        );

        return [$principal, $grant];
    }
}
