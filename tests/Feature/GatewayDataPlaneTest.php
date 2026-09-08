<?php

namespace Tests\Feature;

use App\Contracts\CanonicalGatewayRequest;
use App\Contracts\HostResolver;
use App\Contracts\UpstreamModelAdapter;
use App\DTO\AdapterResult;
use App\DTO\RoutingDecision;
use App\Enums\ApplicationStatus;
use App\Enums\CallAttemptStatus;
use App\Enums\ControlPlaneStatus;
use App\Enums\ProviderType;
use App\Enums\QuotaLedgerEvent;
use App\Exceptions\GatewayException;
use App\Models\Application;
use App\Models\ApplicationModelGrant;
use App\Models\GatewayCallAttempt;
use App\Models\MachineAccessToken;
use App\Models\MachineCredential;
use App\Models\ModelPricingVersion;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\QuotaLedgerEntry;
use App\Models\UpstreamTarget;
use App\Models\User;
use App\Services\AdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

class GatewayDataPlaneTest extends TestCase
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

    public function test_unauthenticated_and_insufficient_scope_errors_are_openai_shaped_with_request_ids(): void
    {
        $this->getJson('/v1/models')
            ->assertUnauthorized()
            ->assertHeader('x-request-id')
            ->assertJsonPath('error.type', 'authentication_error')
            ->assertJsonPath('error.code', 'invalid_api_key');

        $setup = $this->provision(['chat']);
        $setup['accessToken']->forceFill(['abilities' => ['gateway.status']])->save();
        $this->withToken($setup['token'])->getJson('/v1/models')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    public function test_models_lists_only_active_aliases_granted_to_application_without_internals(): void
    {
        $setup = $this->provision(['chat']);
        $other = $this->provision(['chat'], modelId: 'bcgov/other-model');

        $response = $this->withToken($setup['token'])->getJson('/v1/models')
            ->assertOk()
            ->assertJsonPath('object', 'list')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'bcgov/test-model');

        $encoded = $response->getContent();
        $this->assertStringNotContainsString($setup['target']->base_url, $encoded);
        $this->assertStringNotContainsString($setup['target']->provider_model_identifier, $encoded);
        $this->assertStringNotContainsString($setup['provider']->public_id, $encoded);
        $this->assertNotSame($other['application']->id, $setup['application']->id);
    }

    public function test_chat_completion_validates_contract_and_records_selected_versions_and_usage(): void
    {
        $setup = $this->provision(['chat']);
        $adapter = new FakeGatewayAdapter;
        $adapter->result = new AdapterResult([
            'id' => 'chatcmpl_test',
            'object' => 'chat.completion',
            'model' => 'bcgov/test-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
        ], 5, 2, 'upstream-123');
        $this->useAdapter($adapter);

        $response = $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 20,
        ])->assertOk()
            ->assertHeader('x-request-id')
            ->assertJsonPath('model', 'bcgov/test-model')
            ->assertJsonPath('usage.total_tokens', 7);

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->assertSame($response->headers->get('x-request-id'), $attempt->request_id);
        $this->assertSame(CallAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(5, $attempt->prompt_tokens);
        $this->assertSame(2, $attempt->completion_tokens);
        $this->assertSame(1, $attempt->alias_configuration_version);
        $this->assertSame($setup['pricing']->id, $attempt->model_pricing_version_id);
        $this->assertSame('upstream-123', $attempt->upstream_correlation_id);
        $this->assertInstanceOf(CanonicalGatewayRequest::class, $adapter->lastRequest);
    }

    public function test_unsupported_parameters_and_model_limits_are_rejected_before_upstream_call(): void
    {
        $setup = $this->provision(['chat']);
        $adapter = new FakeGatewayAdapter;
        $this->useAdapter($adapter);

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'frequency_penalty' => 1,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'unsupported_parameter')
            ->assertJsonPath('error.param', 'frequency_penalty');

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => $setup['target']->max_output_tokens + 1,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $this->assertSame(0, $adapter->calls);
        $this->assertDatabaseCount('gateway_call_attempts', 0);
    }

    public function test_response_and_embedding_contracts_are_dispatched_canonically(): void
    {
        $setup = $this->provision(['responses', 'embeddings']);
        $adapter = new FakeGatewayAdapter;
        $this->useAdapter($adapter);

        $adapter->result = new AdapterResult([
            'id' => 'resp_test',
            'object' => 'response',
            'model' => 'bcgov/test-model',
            'status' => 'completed',
            'output' => [],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 1, 'total_tokens' => 3],
        ], 2, 1);
        $this->withToken($setup['token'])->postJson('/v1/responses', [
            'model' => 'bcgov/test-model',
            'input' => 'Hello',
            'instructions' => 'Be concise.',
        ])->assertOk()->assertJsonPath('object', 'response');

        $adapter->result = new AdapterResult([
            'object' => 'list',
            'model' => 'bcgov/test-model',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]]],
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
        ], 1);
        $this->withToken($setup['token'])->postJson('/v1/embeddings', [
            'model' => 'bcgov/test-model',
            'input' => ['Hello'],
            'dimensions' => 2,
        ])->assertOk()->assertJsonPath('object', 'list');

        $this->assertSame(2, $adapter->calls);
        $this->assertDatabaseCount('gateway_call_attempts', 2);
    }

    public function test_streaming_frames_events_done_and_records_success(): void
    {
        $setup = $this->provision(['chat', 'streaming']);
        $adapter = new FakeGatewayAdapter;
        $adapter->events = [[
            'id' => 'chatcmpl_stream',
            'object' => 'chat.completion.chunk',
            'model' => 'bcgov/test-model',
            'choices' => [['index' => 0, 'delta' => ['content' => 'Hi'], 'finish_reason' => null]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 1, 'total_tokens' => 5],
        ]];
        $this->useAdapter($adapter);

        $response = $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'stream' => true,
        ])->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no');
        $content = $response->streamedContent();

        $this->assertStringContainsString('data: {"id":"chatcmpl_stream"', $content);
        $this->assertStringEndsWith("data: [DONE]\n\n", $content);
        $this->assertSame(
            CallAttemptStatus::Succeeded,
            GatewayCallAttempt::query()->firstOrFail()->status,
        );
        $this->assertSame(5, GatewayCallAttempt::query()->firstOrFail()->total_tokens);
        $quota = QuotaLedgerEntry::query()
            ->where('event_type', QuotaLedgerEvent::Reconciled->value)
            ->firstOrFail();
        $this->assertSame(5, $quota->actual_input_tokens + $quota->actual_output_tokens);
        $this->assertFalse($quota->usage_missing);
    }

    public function test_partial_stream_failure_is_sanitized_framed_and_recorded(): void
    {
        $setup = $this->provision(['chat', 'streaming']);
        $adapter = new FakeGatewayAdapter;
        $adapter->events = [['type' => 'content.delta', 'delta' => 'partial']];
        $adapter->streamFailure = new GatewayException(
            'server_error',
            'upstream_connection_lost',
            502,
            'The selected model provider stream ended unexpectedly.',
        );
        $this->useAdapter($adapter);

        $response = $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'stream' => true,
        ]);
        $content = $response->streamedContent();

        $this->assertStringContainsString('upstream_connection_lost', $content);
        $this->assertStringNotContainsString('models.example.com', $content);
        $this->assertStringEndsWith("data: [DONE]\n\n", $content);
        $this->assertSame(
            CallAttemptStatus::Partial,
            GatewayCallAttempt::query()->firstOrFail()->status,
        );
        $this->assertTrue(
            QuotaLedgerEntry::query()
                ->where('event_type', QuotaLedgerEvent::Reconciled->value)
                ->firstOrFail()
                ->usage_missing,
        );
    }

    public function test_streaming_requires_grant_and_target_capability(): void
    {
        $setup = $this->provision(['chat']);
        $this->useAdapter(new FakeGatewayAdapter);

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'stream' => true,
        ])->assertBadRequest()
            ->assertJsonPath('error.code', 'streaming_not_supported');
    }

    public function test_content_parts_are_bounded_and_require_safe_shapes(): void
    {
        $setup = $this->provision(['chat']);
        $adapter = new FakeGatewayAdapter;
        $this->useAdapter($adapter);

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'image_url', 'image_url' => ['url' => 'http://127.0.0.1/image']]],
            ]],
        ])->assertUnprocessable()
            ->assertJsonPath('error.param', 'messages.0.content');

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'Hello', 'provider_option' => 'secret']],
            ]],
        ])->assertUnprocessable();

        $this->assertSame(0, $adapter->calls);
    }

    public function test_idempotency_is_bounded_non_streaming_only_and_duplicate_safe(): void
    {
        $setup = $this->provision(['chat', 'streaming']);
        $adapter = new FakeGatewayAdapter;
        $this->useAdapter($adapter);
        $payload = [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ];

        $this->withToken($setup['token'])
            ->withHeader('Idempotency-Key', 'request-123')
            ->postJson('/v1/chat/completions', $payload)
            ->assertOk();
        $this->withToken($setup['token'])
            ->withHeader('Idempotency-Key', 'request-123')
            ->postJson('/v1/chat/completions', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->withToken($setup['token'])
            ->withHeader('Idempotency-Key', 'stream-key')
            ->postJson('/v1/chat/completions', [...$payload, 'stream' => true])
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'idempotency_not_supported');

        $this->assertSame(1, $adapter->calls);
        $this->assertSame(
            1,
            QuotaLedgerEntry::query()
                ->where('event_type', QuotaLedgerEvent::Reserved->value)
                ->count(),
        );
    }

    public function test_grant_and_stale_application_state_fail_closed(): void
    {
        $setup = $this->provision(['chat']);
        $this->useAdapter(new FakeGatewayAdapter);
        $payload = [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ];

        $setup['grant']->forceFill(['enabled' => false])->save();
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', $payload)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'model_not_found');

        $setup['application']->forceFill([
            'status' => ApplicationStatus::Suspended,
            'status_version' => 1,
        ])->save();
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', $payload)
            ->assertUnauthorized();
    }

    public function test_upstream_error_is_sanitized_and_attempt_failed(): void
    {
        $setup = $this->provision(['chat']);
        $adapter = new FakeGatewayAdapter;
        $adapter->failure = new GatewayException(
            'server_error',
            'upstream_error',
            502,
            'The selected model provider rejected the request.',
        );
        $this->useAdapter($adapter);

        $response = $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertStatus(502)
            ->assertJsonPath('error.code', 'upstream_error');

        $this->assertStringNotContainsString($setup['target']->base_url, $response->getContent());
        $this->assertSame(
            CallAttemptStatus::Failed,
            GatewayCallAttempt::query()->firstOrFail()->status,
        );
    }

    public function test_unexpected_upstream_failure_is_sanitized_and_attempt_failed(): void
    {
        $setup = $this->provision(['chat']);
        $adapter = new FakeGatewayAdapter;
        $adapter->failure = new \RuntimeException('secret provider detail');
        $this->useAdapter($adapter);

        $response = $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertInternalServerError()
            ->assertJsonPath('error.code', 'internal_error');

        $this->assertStringNotContainsString('secret provider detail', $response->getContent());
        $this->assertSame(
            CallAttemptStatus::Failed,
            GatewayCallAttempt::query()->firstOrFail()->status,
        );
    }

    public function test_concurrent_streams_are_bounded_per_application(): void
    {
        config(['gateway-data.max_concurrent_requests_per_application' => 1]);
        $setup = $this->provision(['chat', 'streaming']);
        $this->useAdapter(new FakeGatewayAdapter);
        $payload = [
            'model' => 'bcgov/test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'stream' => true,
        ];

        $first = $this->withToken($setup['token'])->postJson('/v1/chat/completions', $payload);
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'concurrency_limit_exceeded');
        $first->streamedContent();

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', $payload)
            ->assertOk()
            ->streamedContent();
    }

    /**
     * @param  list<string>  $capabilities
     * @return array<string, mixed>
     */
    private function provision(array $capabilities, string $modelId = 'bcgov/test-model'): array
    {
        $user = User::factory()->create();
        $application = Application::factory()->create([
            'status' => ApplicationStatus::Active,
            'configuration_ready' => true,
            'created_by' => $user->id,
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
            'capabilities' => $capabilities,
            'status' => ControlPlaneStatus::Active,
            'created_by' => $user->id,
        ]);
        $alias = PublicModelAlias::factory()->create([
            'model_id' => $modelId,
            'active_target_id' => $target->id,
            'capabilities' => $capabilities,
            'status' => ControlPlaneStatus::Active,
            'created_by' => $user->id,
        ]);
        $pricing = ModelPricingVersion::factory()->create([
            'public_model_alias_id' => $alias->id,
            'created_by' => $user->id,
        ]);
        $grant = ApplicationModelGrant::factory()->create([
            'application_id' => $application->id,
            'public_model_alias_id' => $alias->id,
            'capabilities' => $capabilities,
            'enabled' => true,
            'granted_by' => $user->id,
        ]);

        return compact(
            'application',
            'credential',
            'accessToken',
            'provider',
            'target',
            'alias',
            'pricing',
            'grant',
        ) + ['token' => $plainToken];
    }

    private function useAdapter(FakeGatewayAdapter $adapter): void
    {
        $this->app->instance(AdapterRegistry::class, new AdapterRegistry([$adapter]));
    }
}

final class FakeGatewayAdapter implements UpstreamModelAdapter
{
    public int $calls = 0;

    public ?CanonicalGatewayRequest $lastRequest = null;

    public ?AdapterResult $result = null;

    /** @var list<array<string, mixed>> */
    public array $events = [];

    public ?Throwable $failure = null;

    public ?Throwable $streamFailure = null;

    public function providerType(): ProviderType
    {
        return ProviderType::Vllm;
    }

    public function invoke(RoutingDecision $decision, CanonicalGatewayRequest $request): AdapterResult
    {
        $this->calls++;
        $this->lastRequest = $request;
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->result ?? new AdapterResult([
            'id' => 'result',
            'object' => 'chat.completion',
            'model' => $decision->modelId,
            'choices' => [],
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
        ]);
    }

    public function stream(RoutingDecision $decision, CanonicalGatewayRequest $request): iterable
    {
        $this->calls++;
        $this->lastRequest = $request;
        foreach ($this->events as $event) {
            yield $event;
        }
        if ($this->streamFailure) {
            throw $this->streamFailure;
        }
    }
}
