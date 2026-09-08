<?php

namespace Tests\Feature;

use App\Enums\ApplicationMemberRole;
use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\PortalRole;
use App\Models\Application;
use App\Models\MachineAccessToken;
use App\Models\MachineCredential;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MachineAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_one_time_argon2id_hashed_credential_without_plaintext_persistence(): void
    {
        [$owner, $application] = $this->activeApplication();

        $response = $this->actingAs($owner)->post(
            route('portal.applications.credentials.store', $application),
            [
                'name' => 'NRSTS production',
                'abilities' => ['gateway.invoke', 'gateway.status'],
                'expires_at' => null,
            ],
        )->assertRedirect();

        $encryptedDisplay = $response->getSession()->get('machineCredentialEncrypted');
        $display = json_decode(Crypt::decryptString($encryptedDisplay), true, flags: JSON_THROW_ON_ERROR);
        $credential = MachineCredential::query()->firstOrFail();

        $this->assertStringStartsWith('bcaigw_client_', $display['client_id']);
        $this->assertStringStartsWith('bcaigw_secret_', $display['client_secret']);
        $this->assertStringStartsWith('$argon2id$', $credential->secret_hash);
        $this->assertTrue(password_verify($display['client_secret'], $credential->secret_hash));
        $this->assertStringNotContainsString($display['client_secret'], $credential->secret_hash);
        $this->assertStringNotContainsString($display['client_secret'], $encryptedDisplay);
        $this->assertArrayNotHasKey('secret_hash', $credential->toArray());
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::MachineCredentialCreated->value,
            'actor_user_id' => $owner->id,
        ]);

        $this
            ->get(route('portal.applications.show', $application))
            ->assertOk()
            ->assertSee('&quot;encryptHistory&quot;:true', false)
            ->assertSee($display['client_secret']);
        $this->get(route('portal.applications.show', $application))
            ->assertDontSee($display['client_secret']);
    }

    public function test_only_owner_or_administrator_can_manage_credentials_for_active_ready_application(): void
    {
        [$owner, $application] = $this->activeApplication();
        $member = User::factory()->create();
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $application->users()->attach($member, [
            'role' => ApplicationMemberRole::Member->value,
            'created_by' => $owner->id,
        ]);
        $data = ['name' => 'workload', 'abilities' => ['gateway.invoke']];

        $this->actingAs($member)
            ->post(route('portal.applications.credentials.store', $application), $data)
            ->assertForbidden();
        $this->actingAs($administrator)
            ->post(route('portal.applications.credentials.store', $application), $data)
            ->assertRedirect();

        $draft = Application::factory()->create([
            'created_by' => $owner->id,
            'status' => ApplicationStatus::Draft,
            'configuration_ready' => true,
        ]);
        $draft->users()->attach($owner, [
            'role' => ApplicationMemberRole::Owner->value,
            'created_by' => $owner->id,
        ]);
        $this->actingAs($owner)
            ->post(route('portal.applications.credentials.store', $draft), $data)
            ->assertSessionHasErrors('application');
    }

    public function test_token_endpoint_supports_form_and_basic_client_authentication(): void
    {
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);

        $form = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'api_directory_client_id' => $application->api_directory_client_id,
            'client_id' => $credential->client_identifier,
            'client_secret' => $secret,
            'scope' => 'gateway.invoke',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 300);

        $this->assertStringContainsString('no-store', $form->headers->get('Cache-Control'));
        $this->assertStringStartsWith('bcaigw_at_', $form->json('access_token'));

        $this->withHeader(
            'Authorization',
            'basic '.base64_encode(
                rawurlencode($credential->client_identifier).':'.rawurlencode($secret),
            ),
        )->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'api_directory_client_id' => $application->api_directory_client_id,
        ])->assertOk();

        $this->assertDatabaseCount('machine_access_tokens', 2);
    }

    public function test_invalid_client_responses_are_indistinguishable(): void
    {
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);
        $base = [
            'grant_type' => 'client_credentials',
            'api_directory_client_id' => $application->api_directory_client_id,
            'client_id' => $credential->client_identifier,
            'client_secret' => $secret,
        ];

        $unknownApplication = $this->post('/oauth/token', [
            ...$base,
            'api_directory_client_id' => 'unknown-application',
        ]);
        $unknownCredential = $this->post('/oauth/token', [
            ...$base,
            'client_id' => 'bcaigw_client_unknown',
        ]);
        $wrongSecret = $this->post('/oauth/token', [
            ...$base,
            'client_secret' => 'wrong-secret',
        ]);

        foreach ([$unknownApplication, $unknownCredential, $wrongSecret] as $response) {
            $response->assertUnauthorized()
                ->assertHeader('WWW-Authenticate', 'Basic realm="bcaigw-oauth"')
                ->assertExactJson([
                    'error' => 'invalid_client',
                    'error_description' => 'Client authentication failed.',
                ]);
        }
    }

    public function test_token_issuance_fails_closed_for_application_state_and_readiness_matrix(): void
    {
        foreach ([
            [ApplicationStatus::Draft, true],
            [ApplicationStatus::PendingApproval, true],
            [ApplicationStatus::Approved, true],
            [ApplicationStatus::Rejected, true],
            [ApplicationStatus::Suspended, true],
            [ApplicationStatus::Inactive, true],
            [ApplicationStatus::Active, false],
        ] as [$status, $ready]) {
            [$owner, $application] = $this->activeApplication();
            [$credential, $secret] = $this->createCredential($owner, $application);
            $application->forceFill([
                'status' => $status,
                'configuration_ready' => $ready,
                'status_version' => $application->status_version + 1,
            ])->save();

            $this->tokenRequest($application, $credential, $secret)->assertUnauthorized()
                ->assertJsonPath('error', 'invalid_client');
        }
    }

    public function test_expired_and_revoked_credentials_cannot_issue_tokens(): void
    {
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);

        $credential->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->tokenRequest($application, $credential, $secret)->assertUnauthorized();

        $credential->forceFill(['expires_at' => null, 'revoked_at' => now()])->save();
        $this->tokenRequest($application, $credential, $secret)->assertUnauthorized();
    }

    public function test_immediate_rotation_and_revocation_invalidate_existing_bearer_tokens(): void
    {
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);
        $oldToken = $this->tokenRequest($application, $credential, $secret)
            ->assertOk()
            ->json('access_token');

        $rotation = $this->actingAs($owner)->post(
            route('portal.applications.credentials.rotate', [$application, $credential]),
            ['overlap_seconds' => 0, 'name' => 'rotated'],
        )->assertRedirect();
        $newDisplay = json_decode(
            Crypt::decryptString($rotation->getSession()->get('machineCredentialEncrypted')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $successor = MachineCredential::query()
            ->where('client_identifier', $newDisplay['client_id'])
            ->firstOrFail();

        $this->withToken($oldToken)->getJson('/api/gateway/test')->assertUnauthorized();
        $this->tokenRequest($application, $credential, $secret)->assertUnauthorized();
        $this->tokenRequest($application, $successor, $newDisplay['client_secret'])->assertOk();

        $newToken = $this->tokenRequest($application, $successor, $newDisplay['client_secret'])
            ->json('access_token');
        $this->actingAs($owner)->delete(
            route('portal.applications.credentials.destroy', [$application, $successor]),
        )->assertRedirect();
        $this->withToken($newToken)->getJson('/api/gateway/test')->assertUnauthorized();
    }

    public function test_rotation_overlap_has_an_explicit_cutoff(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);
        $token = $this->tokenRequest($application, $credential, $secret)->json('access_token');

        $this->actingAs($owner)->post(
            route('portal.applications.credentials.rotate', [$application, $credential]),
            ['overlap_seconds' => 60],
        )->assertRedirect();

        $this->withToken($token)->getJson('/api/gateway/test')->assertOk();
        Carbon::setTestNow('2026-09-01 12:01:01');
        $this->withToken($token)->getJson('/api/gateway/test')->assertUnauthorized();
    }

    public function test_application_state_version_change_permanently_invalidates_existing_token(): void
    {
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);
        $token = $this->tokenRequest($application, $credential, $secret)->json('access_token');

        $this->withToken($token)->getJson('/api/gateway/test')
            ->assertOk()
            ->assertJsonPath('application.id', $application->public_id);

        $application->forceFill([
            'status' => ApplicationStatus::Suspended,
            'status_version' => 1,
        ])->save();
        $this->withToken($token)->getJson('/api/gateway/test')->assertUnauthorized();

        $application->forceFill([
            'status' => ApplicationStatus::Active,
            'status_version' => 2,
        ])->save();
        $this->withToken($token)->getJson('/api/gateway/test')->assertUnauthorized();
    }

    public function test_expired_token_and_deleted_application_fail_closed(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);
        $token = $this->tokenRequest($application, $credential, $secret)->json('access_token');

        Carbon::setTestNow('2026-09-01 12:05:01');
        $this->withToken($token)->getJson('/api/gateway/test')->assertUnauthorized();

        Carbon::setTestNow('2026-09-01 12:06:00');
        $freshToken = $this->tokenRequest($application, $credential, $secret)->json('access_token');
        $application->delete();
        $this->withToken($freshToken)->getJson('/api/gateway/test')->assertUnauthorized();
    }

    public function test_scope_is_restricted_at_issue_and_enforced_by_middleware(): void
    {
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential(
            $owner,
            $application,
            ['gateway.status'],
        );

        $this->tokenRequest($application, $credential, $secret, 'gateway.invoke')
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_scope');
        $token = $this->tokenRequest($application, $credential, $secret)
            ->assertOk()
            ->json('access_token');
        $this->withToken($token)->getJson('/api/gateway/test')
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::MachineScopeDenied->value,
        ]);
    }

    public function test_opaque_token_tampering_and_jwt_shaped_input_are_rejected(): void
    {
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);
        $token = $this->tokenRequest($application, $credential, $secret)->json('access_token');
        $tampered = substr($token, 0, -1).($token[-1] === 'A' ? 'B' : 'A');

        $this->withToken($tampered)->getJson('/api/gateway/test')->assertUnauthorized();
        $this->withToken('eyJhbGciOiJub25lIn0.eyJzdWIiOiJhZG1pbiJ9.')
            ->getJson('/api/gateway/test')
            ->assertUnauthorized();
    }

    public function test_successful_machine_request_updates_last_used_and_audits_without_secret_or_token(): void
    {
        [$owner, $application] = $this->activeApplication();
        [$credential, $secret] = $this->createCredential($owner, $application);
        $token = $this->tokenRequest($application, $credential, $secret)->json('access_token');

        MachineCredential::query()->whereKey($credential->id)->update(['last_used_at' => null]);
        $this->withToken($token)->getJson('/api/gateway/test')->assertOk();

        $this->assertNotNull($credential->refresh()->last_used_at);
        $this->assertNotSame(
            hash('sha256', '127.0.0.1'),
            $credential->last_used_ip_hash,
        );
        $this->assertNotNull(MachineAccessToken::query()->firstOrFail()->last_used_at);
        $auditJson = json_encode(
            SecurityAuditEvent::query()->pluck('context')->all(),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString($secret, $auditJson);
        $this->assertStringNotContainsString($token, $auditJson);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::MachineRequestAuthenticated->value,
        ]);
    }

    public function test_token_endpoint_is_rate_limited(): void
    {
        config(['machine-auth.token_rate_limit_per_minute' => 2]);
        $payload = [
            'grant_type' => 'client_credentials',
            'api_directory_client_id' => 'not-found',
            'client_id' => 'not-found',
            'client_secret' => 'not-found',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.45'])
            ->post('/oauth/token', $payload)->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.45'])
            ->post('/oauth/token', $payload)->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.45'])
            ->post('/oauth/token', $payload)->assertTooManyRequests();
    }

    public function test_oauth_metadata_describes_supported_client_credentials_flow(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('grant_types_supported.0', 'client_credentials')
            ->assertJsonPath('token_endpoint_auth_methods_supported.0', 'client_secret_basic');
    }

    /**
     * @return array{User, Application}
     */
    private function activeApplication(): array
    {
        $owner = User::factory()->create();
        $application = Application::factory()->create([
            'created_by' => $owner->id,
            'status' => ApplicationStatus::Active,
            'configuration_ready' => true,
            'rate_limit_per_minute' => 100,
            'token_budget_monthly' => 1_000_000,
            'cost_budget_monthly' => 100,
        ]);
        $application->users()->attach($owner, [
            'role' => ApplicationMemberRole::Owner->value,
            'created_by' => $owner->id,
        ]);

        return [$owner, $application];
    }

    /**
     * @param  list<string>  $abilities
     * @return array{MachineCredential, string}
     */
    private function createCredential(
        User $owner,
        Application $application,
        array $abilities = ['gateway.invoke'],
    ): array {
        $response = $this->actingAs($owner)->post(
            route('portal.applications.credentials.store', $application),
            ['name' => 'test workload', 'abilities' => $abilities],
        )->assertRedirect();
        $display = json_decode(
            Crypt::decryptString($response->getSession()->get('machineCredentialEncrypted')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return [
            MachineCredential::query()
                ->where('client_identifier', $display['client_id'])
                ->firstOrFail(),
            $display['client_secret'],
        ];
    }

    private function tokenRequest(
        Application $application,
        MachineCredential $credential,
        string $secret,
        string $scope = '',
    ): TestResponse {
        return $this->withoutHeader('Authorization')->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'api_directory_client_id' => $application->api_directory_client_id,
            'client_id' => $credential->client_identifier,
            'client_secret' => $secret,
            'scope' => $scope,
        ]);
    }
}
