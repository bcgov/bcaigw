<?php

namespace Tests\Feature;

use App\Auth\MachinePrincipal;
use App\Contracts\CanonicalGatewayRequest;
use App\Contracts\HostResolver;
use App\Contracts\QuotaCounterStore;
use App\Contracts\UpstreamModelAdapter;
use App\DTO\AdapterResult;
use App\DTO\QuotaStoreResult;
use App\DTO\RoutingDecision;
use App\Enums\ApplicationMemberRole;
use App\Enums\ApplicationStatus;
use App\Enums\ControlPlaneStatus;
use App\Enums\GatewayOperation;
use App\Enums\PortalRole;
use App\Enums\ProviderType;
use App\Enums\QuotaLedgerEvent;
use App\Exceptions\GatewayException;
use App\Exceptions\QuotaStoreUnavailable;
use App\Models\Application;
use App\Models\ApplicationModelGrant;
use App\Models\MachineAccessToken;
use App\Models\MachineCredential;
use App\Models\ModelPricingVersion;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\QuotaLedgerEntry;
use App\Models\UpstreamTarget;
use App\Models\User;
use App\Services\AbandonedQuotaReconciler;
use App\Services\AdapterRegistry;
use App\Services\GatewayExecutionService;
use App\Services\QuotaUsageProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class QuotaBudgetTest extends TestCase
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

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_request_rate_limit_rejects_before_provider_with_retry_headers(): void
    {
        $setup = $this->provision(['rate_limit_per_minute' => 1]);
        $adapter = new QuotaFakeAdapter;
        $this->useAdapter($adapter);

        $this->callGateway($setup)->assertOk();
        $this->callGateway($setup)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('x-ratelimit-remaining-requests', '0')
            ->assertJsonPath('error.code', 'request_rate_limit');

        $this->assertSame(1, $adapter->calls);
        $this->assertSame(
            1,
            QuotaLedgerEntry::query()->where('event_type', QuotaLedgerEvent::Reserved)->count(),
        );
        $this->assertSame(
            1,
            QuotaLedgerEntry::query()->where('event_type', QuotaLedgerEvent::Rejected)->count(),
        );
    }

    public function test_token_rate_daily_budget_and_cost_budget_have_stable_codes(): void
    {
        $tokenRate = $this->provision(['token_rate_per_minute' => 1], 'bcgov/token-rate');
        $this->useAdapter(new QuotaFakeAdapter);
        $this->callGateway($tokenRate)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'token_rate_limit');

        $daily = $this->provision(['token_budget_daily' => 1], 'bcgov/daily-budget');
        $dailyResponse = $this->callGateway($daily)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'token_budget_exceeded');
        $this->assertGreaterThan(1, (int) $dailyResponse->headers->get('Retry-After'));

        $cost = $this->provision(['cost_budget_daily' => '0.01'], 'bcgov/cost-budget', [
            'input_cost_per_million_tokens' => '1000000.00000000',
            'output_cost_per_million_tokens' => '1000000.00000000',
        ]);
        $this->callGateway($cost)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'cost_budget_exceeded');
    }

    public function test_success_reconciles_to_exact_cached_usage_and_releases_unused_reservation(): void
    {
        $setup = $this->provision();
        $adapter = new QuotaFakeAdapter;
        $adapter->result = new AdapterResult(
            ['object' => 'chat.completion', 'choices' => [], 'usage' => []],
            10,
            2,
            cachedInputTokens: 4,
        );
        $this->useAdapter($adapter);

        $this->callGateway($setup)->assertOk();

        $entry = QuotaLedgerEntry::query()
            ->where('event_type', QuotaLedgerEvent::Reconciled)
            ->firstOrFail();
        $this->assertSame(10, $entry->actual_input_tokens);
        $this->assertSame(2, $entry->actual_output_tokens);
        $this->assertSame(4, $entry->actual_cached_input_tokens);
        $this->assertSame(49, $entry->actual_cost_microunits);
        $this->assertGreaterThan(0, $entry->released_tokens);
        $this->assertFalse($entry->usage_missing);
    }

    public function test_missing_usage_and_provider_failure_charge_conservative_reservation(): void
    {
        $missing = $this->provision(modelId: 'bcgov/missing-usage');
        $adapter = new QuotaFakeAdapter;
        $adapter->result = new AdapterResult(
            ['object' => 'chat.completion', 'choices' => [], 'usage' => []],
            usageReported: false,
        );
        $this->useAdapter($adapter);
        $this->callGateway($missing)->assertOk();
        $reconciled = QuotaLedgerEntry::query()
            ->where('event_type', QuotaLedgerEvent::Reconciled)
            ->firstOrFail();
        $this->assertTrue($reconciled->usage_missing);
        $this->assertSame(
            $reconciled->reserved_input_tokens + $reconciled->reserved_output_tokens,
            $reconciled->actual_input_tokens + $reconciled->actual_output_tokens,
        );

        $failed = $this->provision(modelId: 'bcgov/failed-usage');
        $adapter->failure = new GatewayException('server_error', 'upstream_error', 502, 'Provider failed.');
        $this->callGateway($failed)->assertStatus(502);
        $failureLedger = QuotaLedgerEntry::query()
            ->where('application_id', $failed['application']->id)
            ->where('event_type', QuotaLedgerEvent::Reconciled)
            ->firstOrFail();
        $this->assertTrue($failureLedger->usage_missing);
    }

    public function test_redis_failure_is_fail_closed_and_audited_before_provider(): void
    {
        $setup = $this->provision();
        $adapter = new QuotaFakeAdapter;
        $this->useAdapter($adapter);
        $this->app->instance(QuotaCounterStore::class, new class implements QuotaCounterStore
        {
            public function reserve(array $reservation): QuotaStoreResult
            {
                throw new QuotaStoreUnavailable;
            }

            public function reconcile(array $reconciliation): bool
            {
                throw new QuotaStoreUnavailable;
            }

            public function adjust(array $adjustment): void
            {
                throw new QuotaStoreUnavailable;
            }
        });

        $this->callGateway($setup)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'quota_accounting_unavailable');
        $this->assertSame(0, $adapter->calls);
        $this->assertDatabaseHas('quota_ledger_entries', [
            'application_id' => $setup['application']->id,
            'event_type' => QuotaLedgerEvent::AccountingFailed->value,
            'reason' => 'redis_unavailable',
        ]);
    }

    public function test_fixed_windows_roll_over_in_utc_and_unlimited_settings_remain_enabled(): void
    {
        CarbonImmutable::setTestNow('2026-12-31 23:59:50 UTC');
        $limited = $this->provision(['rate_limit_per_minute' => 1], 'bcgov/rollover');
        $adapter = new QuotaFakeAdapter;
        $this->useAdapter($adapter);
        $this->callGateway($limited)->assertOk();
        $this->callGateway($limited)->assertTooManyRequests();

        CarbonImmutable::setTestNow('2027-01-01 00:00:01 UTC');
        $this->callGateway($limited)->assertOk();

        $unlimited = $this->provision(modelId: 'bcgov/unlimited');
        $this->callGateway($unlimited)->assertOk();
        $this->callGateway($unlimited)->assertOk();
        $this->assertSame(4, $adapter->calls);
    }

    public function test_ledger_is_append_only_at_model_boundary(): void
    {
        $setup = $this->provision();
        $this->useAdapter(new QuotaFakeAdapter);
        $this->callGateway($setup)->assertOk();
        $entry = QuotaLedgerEntry::query()->firstOrFail();

        $this->expectException(\LogicException::class);
        $entry->update(['reason' => 'tampered']);
    }

    public function test_admin_adjustments_are_bounded_audited_and_visible_in_owner_projection(): void
    {
        $setup = $this->provision();
        $admin = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $owner = User::factory()->create(['portal_role' => PortalRole::ApplicationOwner]);
        $setup['application']->users()->attach($owner->id, [
            'role' => ApplicationMemberRole::Owner,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($admin)->post(
            "/portal/admin/applications/{$setup['application']->public_id}/quota-adjustments",
            [
                'token_adjustment' => 100,
                'cost_adjustment' => '0.250000',
                'reason' => 'Approved operational correction.',
            ],
        )->assertRedirect();
        $this->assertDatabaseHas('quota_ledger_entries', [
            'application_id' => $setup['application']->id,
            'event_type' => QuotaLedgerEvent::Adjustment->value,
            'adjustment_tokens' => 100,
            'adjustment_cost_microunits' => 250000,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => 'quota_budget.adjusted',
            'actor_user_id' => $admin->id,
        ]);
        $projection = app(QuotaUsageProjectionService::class)
            ->forApplication($setup['application']->refresh());
        $this->assertSame(100, $projection['daily']['tokens']);
        $this->assertSame('0.250000', $projection['monthly']['cost']);

        $this->actingAs($owner)->post(
            "/portal/admin/applications/{$setup['application']->public_id}/quota-adjustments",
            [
                'token_adjustment' => 100,
                'reason' => 'Unauthorized owner adjustment.',
            ],
        )->assertForbidden();
        $this->actingAs($owner)
            ->get("/portal/applications/{$setup['application']->public_id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('application.public_id', $setup['application']->public_id)
                ->where('usageProjection.daily.tokens', 100));
    }

    public function test_abandoned_reservations_are_conservatively_recovered_once(): void
    {
        config(['quota.reservation_timeout_seconds' => 1]);
        CarbonImmutable::setTestNow('2026-09-01 12:00:00 UTC');
        $setup = $this->provision();
        $this->useAdapter(new QuotaFakeAdapter);
        $request = Request::create('/v1/chat/completions', 'POST');
        $request->attributes->set('bcaigw.request_id', (string) Str::uuid());
        $principal = new MachinePrincipal(
            $setup['application'],
            $setup['credential'],
            $setup['accessToken'],
            ['gateway.invoke'],
        );
        app(GatewayExecutionService::class)->prepare(
            $request,
            $principal,
            GatewayOperation::ChatCompletions,
            [
                'model' => $setup['alias']->model_id,
                'messages' => [['role' => 'user', 'content' => 'abandoned']],
                'max_tokens' => 20,
            ],
        );
        CarbonImmutable::setTestNow('2026-09-01 12:00:02 UTC');

        $this->assertSame(1, app(AbandonedQuotaReconciler::class)->reconcile());
        $this->assertSame(0, app(AbandonedQuotaReconciler::class)->reconcile());
        $recovered = QuotaLedgerEntry::query()
            ->where('event_type', QuotaLedgerEvent::Recovered->value)
            ->firstOrFail();
        $this->assertTrue($recovered->usage_missing);
        $this->assertSame(
            $recovered->reserved_input_tokens + $recovered->reserved_output_tokens,
            $recovered->actual_input_tokens + $recovered->actual_output_tokens,
        );
    }

    private function callGateway(array $setup)
    {
        return $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => $setup['alias']->model_id,
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 20,
        ]);
    }

    private function useAdapter(QuotaFakeAdapter $adapter): void
    {
        $this->app->instance(AdapterRegistry::class, new AdapterRegistry([$adapter]));
    }

    /**
     * @param  array<string, mixed>  $budgets
     * @return array<string, mixed>
     */
    private function provision(
        array $budgets = [],
        string $modelId = 'bcgov/quota-model',
        array $pricingValues = [],
    ): array {
        $user = User::factory()->create();
        $application = Application::factory()->create([
            'status' => ApplicationStatus::Active,
            'configuration_ready' => true,
            'created_by' => $user->id,
            ...$budgets,
        ]);
        $credential = MachineCredential::factory()->create([
            'application_id' => $application->id,
            'created_by' => $user->id,
            'abilities' => ['gateway.invoke'],
        ]);
        $plainToken = 'bcaigw_at_'.bin2hex(random_bytes(32));
        $accessToken = MachineAccessToken::create([
            'application_id' => $application->id,
            'machine_credential_id' => $credential->id,
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => ['gateway.invoke'],
            'credential_version' => $credential->version,
            'application_status_version' => $application->status_version,
            'expires_at' => now()->addMinutes(10),
        ]);
        $provider = ProviderAccount::factory()->create([
            'type' => ProviderType::Vllm,
            'configuration' => [],
            'secret_reference' => null,
            'status' => ControlPlaneStatus::Active,
            'created_by' => $user->id,
        ]);
        $target = UpstreamTarget::factory()->create([
            'provider_account_id' => $provider->id,
            'capabilities' => ['chat'],
            'status' => ControlPlaneStatus::Active,
            'created_by' => $user->id,
        ]);
        $alias = PublicModelAlias::factory()->create([
            'model_id' => $modelId,
            'active_target_id' => $target->id,
            'capabilities' => ['chat'],
            'status' => ControlPlaneStatus::Active,
            'created_by' => $user->id,
        ]);
        $pricing = ModelPricingVersion::factory()->create([
            'public_model_alias_id' => $alias->id,
            'created_by' => $user->id,
            ...$pricingValues,
        ]);
        ApplicationModelGrant::factory()->create([
            'application_id' => $application->id,
            'public_model_alias_id' => $alias->id,
            'capabilities' => ['chat'],
            'enabled' => true,
            'granted_by' => $user->id,
        ]);

        return compact('application', 'alias', 'pricing', 'credential', 'accessToken')
            + ['token' => $plainToken];
    }
}

final class QuotaFakeAdapter implements UpstreamModelAdapter
{
    public int $calls = 0;

    public ?AdapterResult $result = null;

    public ?GatewayException $failure = null;

    public function providerType(): ProviderType
    {
        return ProviderType::Vllm;
    }

    public function invoke(RoutingDecision $decision, CanonicalGatewayRequest $request): AdapterResult
    {
        $this->calls++;
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->result ?? new AdapterResult([
            'id' => 'quota-result',
            'object' => 'chat.completion',
            'model' => $decision->modelId,
            'choices' => [],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], 1, 1);
    }

    public function stream(RoutingDecision $decision, CanonicalGatewayRequest $request): iterable
    {
        yield from [];
    }
}
