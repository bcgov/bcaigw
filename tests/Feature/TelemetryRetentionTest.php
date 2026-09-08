<?php

namespace Tests\Feature;

use App\Contracts\CanonicalGatewayRequest;
use App\Contracts\ContentKeyProvider;
use App\Contracts\HostResolver;
use App\Contracts\MetricsStore;
use App\Contracts\UpstreamModelAdapter;
use App\DTO\AdapterResult;
use App\DTO\RoutingDecision;
use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\ContentState;
use App\Enums\ControlPlaneStatus;
use App\Enums\PortalRole;
use App\Enums\ProviderType;
use App\Enums\TelemetryDeletionMode;
use App\Enums\TelemetryDeletionScope;
use App\Exceptions\GatewayException;
use App\Models\Application;
use App\Models\ApplicationModelGrant;
use App\Models\GatewayCallAttempt;
use App\Models\GatewayCallContent;
use App\Models\GatewayUsageRollup;
use App\Models\MachineAccessToken;
use App\Models\MachineCredential;
use App\Models\ModelPricingVersion;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\QuotaLedgerEntry;
use App\Models\SecurityAuditEvent;
use App\Models\TelemetryContentDeletion;
use App\Models\UpstreamTarget;
use App\Models\User;
use App\Services\AdapterRegistry;
use App\Services\CallContentRetentionService;
use App\Services\InMemoryMetricsStore;
use App\Services\TelemetryRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelemetryRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAE=';

    private const KEY_B = 'AgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgI=';

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

        config([
            'telemetry.keys.keyring' => json_encode(['k1' => self::KEY_A]),
            'telemetry.keys.active' => 'k1',
        ]);
    }

    public function test_content_is_retained_encrypted_and_never_stored_in_plaintext(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('the upstream said something confidential');

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'my secret prompt about salmon']],
        ])->assertOk();

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->assertTrue($attempt->content_retention_enabled);
        $this->assertSame(ContentState::Stored, $attempt->content_state);

        $content = GatewayCallContent::query()->firstOrFail();
        $this->assertSame('k1', $content->key_id);
        $this->assertSame('aes-256-gcm', $content->algorithm);
        $this->assertGreaterThan(0, $content->request_bytes);

        // No column of any telemetry table may contain the plaintext.
        foreach (['gateway_call_contents', 'gateway_call_attempts'] as $table) {
            $serialized = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('my secret prompt about salmon', (string) $serialized);
            $this->assertStringNotContainsString('confidential', (string) $serialized);
        }

        // Serialising the model must not leak ciphertext or key material either.
        $this->assertArrayNotHasKey('request_ciphertext', $content->toArray());
        $this->assertArrayNotHasKey('wrapped_data_key', $content->toArray());
    }

    public function test_disabled_retention_persists_no_content_bytes_anywhere(): void
    {
        $setup = $this->provision();
        $setup['application']->forceFill(['prompt_response_retention_enabled' => false])->save();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('upstream answer');

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'do not keep this prompt']],
        ])->assertOk();

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->assertFalse($attempt->content_retention_enabled);
        $this->assertSame(ContentState::NotRetained, $attempt->content_state);
        $this->assertSame(0, GatewayCallContent::query()->count());

        foreach (['gateway_call_attempts', 'security_audit_events', 'quota_ledger_entries'] as $table) {
            $serialized = (string) json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('do not keep this prompt', $serialized);
            $this->assertStringNotContainsString('upstream answer', $serialized);
        }
    }

    public function test_retention_toggle_applies_prospectively_only(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('first answer');

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'first prompt']],
        ])->assertOk();
        $this->assertSame(1, GatewayCallContent::query()->count());

        $setup['application']->forceFill(['prompt_response_retention_enabled' => false])->save();
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'second prompt']],
        ])->assertOk();

        // The earlier capture survives; the later call captures nothing.
        $this->assertSame(1, GatewayCallContent::query()->count());
        $this->assertSame(
            [ContentState::Stored, ContentState::NotRetained],
            GatewayCallAttempt::query()->orderBy('id')->get()->map->content_state->all(),
        );

        // Re-enabling never resurrects content for the call made while disabled.
        $setup['application']->forceFill(['prompt_response_retention_enabled' => true])->save();
        $second = GatewayCallAttempt::query()->orderByDesc('id')->firstOrFail();
        $this->assertNull(GatewayCallContent::query()->where('gateway_call_attempt_id', $second->id)->first());
    }

    public function test_reveal_requires_reason_authorization_and_is_audited(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('revealed response');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'revealed prompt']],
        ])->assertOk();
        $attempt = GatewayCallAttempt::query()->firstOrFail();

        $owner = $setup['owner'];

        // Content is absent from the detail payload until explicitly revealed.
        $this->actingAs($owner)->get("/portal/calls/{$attempt->request_id}")
            ->assertOk()
            ->assertDontSee('revealed prompt');

        $this->actingAs($owner)
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'x'])
            ->assertStatus(422);

        $response = $this->actingAs($owner)
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'incident review 42'])
            ->assertOk();
        $this->assertStringContainsString('revealed prompt', $response->json('content.request'));
        $this->assertStringContainsString('revealed response', $response->json('content.response'));

        $audit = SecurityAuditEvent::query()
            ->where('event_type', AuditEventType::TelemetryContentRevealed)
            ->firstOrFail();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame('incident review 42', $audit->context['reason']);
        $this->assertStringNotContainsString('revealed prompt', (string) json_encode($audit->context));
    }

    public function test_cross_tenant_access_to_history_detail_and_reveal_is_denied(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('tenant answer');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'tenant prompt']],
        ])->assertOk();
        $attempt = GatewayCallAttempt::query()->firstOrFail();

        $stranger = User::factory()->create(['portal_role' => PortalRole::ApplicationOwner]);

        $this->actingAs($stranger)->get('/portal/calls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('calls.data', []));

        $this->actingAs($stranger)->get("/portal/calls/{$attempt->request_id}")->assertForbidden();
        $this->actingAs($stranger)
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'curiosity only'])
            ->assertForbidden();

        // A non-owner member may read metadata but may not reveal content.
        $member = User::factory()->create(['portal_role' => PortalRole::ApplicationOwner]);
        $setup['application']->users()->attach($member->id, [
            'role' => 'member',
            'created_by' => $setup['owner']->id,
        ]);
        $this->actingAs($member)->get("/portal/calls/{$attempt->request_id}")->assertOk();
        $this->actingAs($member)
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'member curiosity'])
            ->assertForbidden();
    }

    public function test_tampered_ciphertext_fails_authentication_and_is_audited(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('authentic response');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'authentic prompt']],
        ])->assertOk();

        $content = GatewayCallContent::query()->firstOrFail();
        $raw = base64_decode((string) $content->request_ciphertext, true);
        $raw[0] = $raw[0] === 'A' ? 'B' : 'A';
        $content->forceFill(['request_ciphertext' => base64_encode($raw)])->save();

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->actingAs($setup['owner'])
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'tamper check run'])
            ->assertStatus(409);

        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::TelemetryContentRevealed->value,
            'outcome' => 'failed',
        ]);
    }

    public function test_ciphertext_cannot_be_relocated_between_records(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('answer one');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'prompt one']],
        ])->assertOk();
        $adapter->result = $this->completion('answer two');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'prompt two']],
        ])->assertOk();

        $contents = GatewayCallContent::query()->orderBy('id')->get();
        $second = $contents[1];
        // Copy the first record's sealed payload onto the second record.
        $second->forceFill([
            'request_iv' => $contents[0]->request_iv,
            'request_tag' => $contents[0]->request_tag,
            'request_ciphertext' => $contents[0]->request_ciphertext,
        ])->save();

        $attempt = GatewayCallAttempt::query()->findOrFail($second->gateway_call_attempt_id);
        $this->actingAs($setup['owner'])
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'relocation check'])
            ->assertStatus(409);
    }

    public function test_key_rotation_rewraps_records_and_keeps_content_readable(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('rotation response');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'rotation prompt']],
        ])->assertOk();

        config([
            'telemetry.keys.keyring' => json_encode(['k1' => self::KEY_A, 'k2' => self::KEY_B]),
            'telemetry.keys.active' => 'k2',
        ]);
        $this->app->forgetInstance(ContentKeyProvider::class);

        $rotated = app(CallContentRetentionService::class)->rotate(100);
        $this->assertSame(1, $rotated);
        $this->assertSame('k2', GatewayCallContent::query()->firstOrFail()->key_id);

        // Rotation is idempotent: a second pass rewraps nothing.
        $this->assertSame(0, app(CallContentRetentionService::class)->rotate(100));

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->actingAs($setup['owner'])
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'post rotation read'])
            ->assertOk()
            ->assertJsonPath('content.response', fn (?string $value): bool => str_contains((string) $value, 'rotation response'));
    }

    public function test_oversized_content_is_truncated_and_marked(): void
    {
        config(['telemetry.content.max_request_bytes' => 512, 'telemetry.content.max_response_bytes' => 256]);
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion(str_repeat('z', 5000));

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => str_repeat('y', 4000)]],
        ])->assertOk();

        $content = GatewayCallContent::query()->firstOrFail();
        $this->assertTrue((bool) $content->request_truncated);
        $this->assertTrue((bool) $content->response_truncated);
        $this->assertLessThanOrEqual(512, $content->request_bytes);
        $this->assertLessThanOrEqual(256, $content->response_bytes);

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $revealed = $this->actingAs($setup['owner'])
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'truncation check'])
            ->assertOk();
        $this->assertStringEndsWith('[bcaigw:truncated]', $revealed->json('content.request'));
        $this->assertTrue($revealed->json('content.request_truncated'));
    }

    public function test_application_ceiling_can_only_lower_the_global_limit(): void
    {
        $setup = $this->provision();
        $setup['application']->forceFill(['retention_max_content_bytes' => 100])->save();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion(str_repeat('q', 4000));

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => str_repeat('p', 4000)]],
        ])->assertOk();

        $content = GatewayCallContent::query()->firstOrFail();
        $this->assertLessThanOrEqual(100, $content->request_bytes);
        $this->assertLessThanOrEqual(100, $content->response_bytes);
    }

    public function test_streaming_captures_bounded_chunks_ttft_and_finalizes_content(): void
    {
        $setup = $this->provision(capabilities: ['chat', 'streaming']);
        $adapter = $this->useAdapter();
        $adapter->events = [
            ['id' => 'upstream-corr-1', 'choices' => [['delta' => ['content' => 'Hel']]]],
            ['id' => 'upstream-corr-1', 'choices' => [['delta' => ['content' => 'lo!']]]],
            [
                'id' => 'upstream-corr-1',
                'choices' => [['delta' => [], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
            ],
        ];

        $response = $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'streamed prompt']],
        ]);
        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('data: [DONE]', $body);

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->assertTrue((bool) $attempt->streaming);
        $this->assertNotNull($attempt->time_to_first_token_ms);
        $this->assertSame(ContentState::Stored, $attempt->content_state);
        $this->assertSame('upstream-corr-1', $attempt->upstream_correlation_id);

        $content = GatewayCallContent::query()->firstOrFail();
        $this->assertGreaterThan(0, $content->stream_chunk_count);

        $revealed = $this->actingAs($setup['owner'])
            ->postJson("/portal/calls/{$attempt->request_id}/reveal", ['reason' => 'stream transcript'])
            ->assertOk();
        $this->assertStringContainsString('Hel', $revealed->json('content.response'));
    }

    public function test_stream_chunk_capture_is_bounded_by_configuration(): void
    {
        config(['telemetry.content.max_stream_chunks' => 2]);
        $setup = $this->provision(capabilities: ['chat', 'streaming']);
        $adapter = $this->useAdapter();
        $adapter->events = array_map(
            static fn (int $index): array => ['choices' => [['delta' => ['content' => "chunk{$index}"]]]],
            range(1, 12),
        );

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'bounded stream']],
        ])->streamedContent();

        $content = GatewayCallContent::query()->firstOrFail();
        $this->assertTrue((bool) $content->response_truncated);
        // stream_chunk_count records every observed upstream event; only the
        // configured ceiling is actually captured into the encrypted payload.
        $this->assertSame(12, $content->stream_chunk_count);

        $revealed = app(CallContentRetentionService::class)->reveal(
            $content->attempt,
            $setup['application'],
            User::factory()->create(['portal_role' => PortalRole::Administrator]),
            request(),
            'verifying stream capture bounds',
        );
        $lines = array_values(array_filter(explode("\n", (string) $revealed['response'])));
        $this->assertCount(3, $lines, 'two captured chunks plus the truncation marker');
        $this->assertSame(config('telemetry.content.truncation_marker'), end($lines));
        $this->assertStringContainsString('chunk1', $lines[0]);
        $this->assertStringContainsString('chunk2', $lines[1]);
    }

    public function test_failed_call_records_sanitized_error_category_without_upstream_detail(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->failure = new GatewayException(
            'api_error',
            'upstream_error',
            502,
            'Upstream provider request failed.',
        );

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'failing prompt']],
        ])->assertStatus(502);

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->assertSame('upstream', $attempt->error_category);
        $this->assertSame(502, $attempt->http_status);
        $this->assertNotNull($attempt->total_latency_ms);
    }

    public function test_client_metadata_is_allowlisted_and_bounded(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('metadata answer');

        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'metadata prompt']],
            'metadata' => [
                'case_id' => 'ABC-123',
                'bad key!' => 'dropped',
                'long' => str_repeat('m', 400),
            ],
        ])->assertOk();

        $metadata = GatewayCallAttempt::query()->firstOrFail()->client_metadata;
        $this->assertSame('ABC-123', $metadata['case_id']);
        $this->assertArrayNotHasKey('bad key!', $metadata);
        $this->assertLessThanOrEqual(200, strlen($metadata['long']));
    }

    public function test_export_escapes_formula_injection_and_is_audited(): void
    {
        $setup = $this->provision(modelId: '=cmd|calc');
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('export answer');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => '=cmd|calc',
            'messages' => [['role' => 'user', 'content' => 'export prompt']],
        ])->assertOk();

        $csv = $this->actingAs($setup['owner'])->get('/portal/calls/export');
        $csv->assertOk();
        $body = $csv->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $body);
        $this->assertStringNotContainsString(',=cmd|calc', $body);
        $this->assertStringNotContainsString('export prompt', $body);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::TelemetryExported->value,
        ]);
    }

    public function test_administrator_deletion_shreds_content_preserves_ledger_and_is_idempotent(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('deletable answer');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'deletable prompt']],
        ])->assertOk();

        $ledgerBefore = QuotaLedgerEntry::query()->count();
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);

        $this->actingAs($administrator)->post('/portal/admin/telemetry/deletions', [
            'scope' => 'application',
            'mode' => 'content',
            'application' => $setup['application']->public_id,
            'reason' => 'Privacy request from the ministry.',
            'confirmation' => 'DELETE',
        ])->assertRedirect();

        $this->assertSame(0, GatewayCallContent::query()->count());
        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->assertSame(ContentState::Deleted, $attempt->content_state);
        $this->assertNotNull($attempt->content_deleted_at);
        $this->assertNotNull($attempt->telemetry_content_deletion_id);
        // Accounting evidence survives.
        $this->assertSame($ledgerBefore, QuotaLedgerEntry::query()->count());
        $this->assertNotNull($attempt->total_tokens);
        $this->assertNotNull($attempt->cost_microunits);

        $deletion = TelemetryContentDeletion::query()->firstOrFail();
        $this->assertSame(1, $deletion->matched_attempts);
        $this->assertSame(1, $deletion->content_records_destroyed);
        $this->assertSame(0, $deletion->details_redacted);

        // Re-running is safe: nothing left to destroy, evidence still recorded.
        $this->actingAs($administrator)->post('/portal/admin/telemetry/deletions', [
            'scope' => 'application',
            'mode' => 'content',
            'application' => $setup['application']->public_id,
            'reason' => 'Privacy request from the ministry (repeat).',
            'confirmation' => 'DELETE',
        ])->assertRedirect();
        $this->assertSame(2, TelemetryContentDeletion::query()->count());
        $this->assertSame(
            0,
            (int) TelemetryContentDeletion::query()->orderByDesc('id')->firstOrFail()->content_records_destroyed,
        );
    }

    public function test_detail_deletion_redacts_columns_but_keeps_accounting_tombstone(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('redacted answer');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'redacted prompt']],
            'metadata' => ['case_id' => 'CASE-9'],
        ])->assertOk();

        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $this->actingAs($administrator)->post('/portal/admin/telemetry/deletions', [
            'scope' => 'application',
            'mode' => 'content_and_details',
            'application' => $setup['application']->public_id,
            'reason' => 'Detailed record purge requested.',
            'confirmation' => 'DELETE',
        ])->assertRedirect();

        $attempt = GatewayCallAttempt::query()->firstOrFail();
        $this->assertNull($attempt->client_metadata);
        $this->assertNull($attempt->requested_model);
        $this->assertNull($attempt->total_latency_ms);
        $this->assertNotNull($attempt->details_redacted_at);
        // Aggregate accounting dimensions remain intact.
        $this->assertNotNull($attempt->application_id);
        $this->assertNotNull($attempt->started_at);
        $this->assertNotNull($attempt->total_tokens);
        $this->assertNotNull($attempt->cost_microunits);
    }

    public function test_deletion_requires_administrator_and_confirmation(): void
    {
        $setup = $this->provision();

        $this->actingAs($setup['owner'])->post('/portal/admin/telemetry/deletions', [
            'scope' => 'application',
            'mode' => 'content',
            'application' => $setup['application']->public_id,
            'reason' => 'Owner should not be able to do this.',
            'confirmation' => 'DELETE',
        ])->assertForbidden();

        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $this->actingAs($administrator)->post('/portal/admin/telemetry/deletions', [
            'scope' => 'application',
            'mode' => 'content',
            'application' => $setup['application']->public_id,
            'reason' => 'Missing the confirmation phrase.',
            'confirmation' => 'yes please',
        ])->assertSessionHasErrors('confirmation');

        $this->assertSame(0, TelemetryContentDeletion::query()->count());
    }

    public function test_deletion_records_are_append_only(): void
    {
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $deletion = TelemetryContentDeletion::create([
            'scope' => TelemetryDeletionScope::Range,
            'mode' => TelemetryDeletionMode::Content,
            'reason' => 'append only check',
            'actor_user_id' => $administrator->id,
            'matched_attempts' => 0,
            'content_records_destroyed' => 0,
            'details_redacted' => 0,
        ]);

        $this->expectException(\LogicException::class);
        $deletion->forceFill(['reason' => 'rewritten'])->save();
    }

    public function test_metrics_endpoint_is_token_gated_and_excludes_content_and_ids(): void
    {
        config(['telemetry.metrics.scrape_token' => 'scrape-me']);
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('metrics answer');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'metrics prompt']],
        ])->assertOk();

        $this->get('/metrics')->assertForbidden();

        $body = $this->withToken('scrape-me')->get('/metrics')->assertOk()->getContent();
        $this->assertStringContainsString('bcaigw_gateway_requests_total', $body);
        $this->assertStringContainsString('outcome="succeeded"', $body);
        $this->assertStringNotContainsString('metrics prompt', $body);
        $this->assertStringNotContainsString((string) $setup['application']->public_id, $body);
        $this->assertStringNotContainsString(
            (string) GatewayCallAttempt::query()->firstOrFail()->request_id,
            $body,
        );
    }

    public function test_metric_series_are_capped_and_high_cardinality_labels_are_redacted(): void
    {
        config(['telemetry.metrics.max_series' => 3]);
        $store = new InMemoryMetricsStore;
        $this->app->instance(MetricsStore::class, $store);

        for ($i = 0; $i < 20; $i++) {
            $store->increment('gateway_requests_total', ['model' => "model-{$i}"]);
        }
        $this->assertLessThanOrEqual(3, count($store->all()));

        $store->flush();
        $store->increment('gateway_requests_total', ['model' => (string) Str::ulid()]);
        $this->assertStringContainsString('redacted', array_key_first($store->all()));
    }

    public function test_rollups_aggregate_by_day_and_power_the_summary(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('rollup answer');
        foreach (range(1, 3) as $ignored) {
            $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
                'model' => 'bcgov/telemetry-model',
                'messages' => [['role' => 'user', 'content' => 'rollup prompt']],
            ])->assertOk();
        }

        $rows = app(TelemetryRollupService::class)->rebuild();
        $this->assertSame(1, $rows);

        $rollup = GatewayUsageRollup::query()->firstOrFail();
        $this->assertSame(3, (int) $rollup->requests);
        $this->assertSame(0, (int) $rollup->failures);
        $this->assertSame(
            (int) GatewayCallAttempt::query()->sum('total_tokens'),
            (int) $rollup->total_tokens,
        );

        // Rebuilding is idempotent rather than additive.
        app(TelemetryRollupService::class)->rebuild();
        $this->assertSame(1, GatewayUsageRollup::query()->count());

        $this->actingAs($setup['owner'])->get('/portal/calls')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.totals.requests', 3));
    }

    public function test_filters_restrict_results_without_leaking_other_tenants(): void
    {
        $setup = $this->provision();
        $adapter = $this->useAdapter();
        $adapter->result = $this->completion('filtered answer');
        $this->withToken($setup['token'])->postJson('/v1/chat/completions', [
            'model' => 'bcgov/telemetry-model',
            'messages' => [['role' => 'user', 'content' => 'filtered prompt']],
        ])->assertOk();

        $other = Application::factory()->create([
            'status' => ApplicationStatus::Active,
            'configuration_ready' => true,
            'created_by' => User::factory()->create()->id,
        ]);

        // Filtering by an application the actor cannot see returns nothing
        // rather than another tenant's rows.
        $this->actingAs($setup['owner'])
            ->get('/portal/calls?application='.$other->public_id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('calls.data', []));

        $this->actingAs($setup['owner'])
            ->get('/portal/calls?status=failed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('calls.data', []));
    }

    private function completion(string $text): AdapterResult
    {
        return new AdapterResult([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'bcgov/telemetry-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function provision(string $modelId = 'bcgov/telemetry-model', array $capabilities = ['chat']): array
    {
        $owner = User::factory()->create(['portal_role' => PortalRole::ApplicationOwner]);
        $application = Application::factory()->create([
            'status' => ApplicationStatus::Active,
            'configuration_ready' => true,
            'prompt_response_retention_enabled' => true,
            'created_by' => $owner->id,
        ]);
        $application->users()->attach($owner->id, ['role' => 'owner', 'created_by' => $owner->id]);

        $credential = MachineCredential::factory()->create([
            'application_id' => $application->id,
            'created_by' => $owner->id,
            'abilities' => ['gateway.invoke'],
        ]);
        $plainToken = 'bcaigw_at_'.bin2hex(random_bytes(32));
        MachineAccessToken::create([
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
            'created_by' => $owner->id,
        ]);
        $target = UpstreamTarget::factory()->create([
            'provider_account_id' => $provider->id,
            'capabilities' => $capabilities,
            'status' => ControlPlaneStatus::Active,
            'created_by' => $owner->id,
        ]);
        $alias = PublicModelAlias::factory()->create([
            'model_id' => $modelId,
            'active_target_id' => $target->id,
            'capabilities' => $capabilities,
            'status' => ControlPlaneStatus::Active,
            'created_by' => $owner->id,
        ]);
        ModelPricingVersion::factory()->create([
            'public_model_alias_id' => $alias->id,
            'created_by' => $owner->id,
        ]);
        ApplicationModelGrant::factory()->create([
            'application_id' => $application->id,
            'public_model_alias_id' => $alias->id,
            'capabilities' => $capabilities,
            'enabled' => true,
            'granted_by' => $owner->id,
        ]);

        return [
            'owner' => $owner,
            'application' => $application,
            'credential' => $credential,
            'token' => $plainToken,
            'alias' => $alias,
        ];
    }

    private function useAdapter(): TelemetryFakeAdapter
    {
        $adapter = new TelemetryFakeAdapter;
        $this->app->instance(AdapterRegistry::class, new AdapterRegistry([$adapter]));

        return $adapter;
    }
}

final class TelemetryFakeAdapter implements UpstreamModelAdapter
{
    public ?AdapterResult $result = null;

    /** @var list<array<string, mixed>> */
    public array $events = [];

    public ?\Throwable $failure = null;

    public function providerType(): ProviderType
    {
        return ProviderType::Vllm;
    }

    public function invoke(RoutingDecision $decision, CanonicalGatewayRequest $request): AdapterResult
    {
        if ($this->failure !== null) {
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
        foreach ($this->events as $event) {
            yield $event;
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
