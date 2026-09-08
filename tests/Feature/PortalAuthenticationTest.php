<?php

namespace Tests\Feature;

use App\Auth\Keycloak\AuthorizationRequest;
use App\Auth\Keycloak\KeycloakAuthenticationException;
use App\Auth\Keycloak\KeycloakClient;
use App\Auth\Keycloak\KeycloakIdentity;
use App\Enums\AuditEventType;
use App\Enums\PortalRole;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\IdirUserProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_state_is_rejected_and_audited(): void
    {
        $this->bindKeycloakClient(identity: $this->idirIdentity());

        $this->withSession($this->oauthTransaction('expected-state'))
            ->get('/auth/idir/callback?code=unused&state=wrong-state')
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::LoginFailed->value,
            'outcome' => 'failed',
        ]);
        $this->assertSame(
            'invalid_state',
            SecurityAuditEvent::query()->latest('id')->firstOrFail()->context['reason'],
        );
    }

    public function test_nonce_failure_is_rejected_and_audited(): void
    {
        $this->bindKeycloakClient(exception: new KeycloakAuthenticationException('invalid_nonce'));

        $this->withSession($this->oauthTransaction())
            ->get('/auth/idir/callback?code=unused&state=valid-state')
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertSame(
            'invalid_nonce',
            SecurityAuditEvent::query()->latest('id')->firstOrFail()->context['reason'],
        );
    }

    public function test_provider_callback_error_is_handled_without_persisting_payload(): void
    {
        $this->bindKeycloakClient(identity: $this->idirIdentity());

        $this->withSession($this->oauthTransaction())
            ->get('/auth/idir/callback?error=access_denied&error_description=sensitive-provider-text&state=valid-state')
            ->assertRedirect(route('home'));

        $event = SecurityAuditEvent::query()->latest('id')->firstOrFail();

        $this->assertSame('provider_error', $event->context['reason']);
        $this->assertStringNotContainsString(
            'sensitive-provider-text',
            json_encode($event->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_non_idir_identity_is_rejected_without_email_domain_inference(): void
    {
        $identity = $this->idirIdentity(identityProvider: 'bceid');
        $this->bindKeycloakClient(identity: $identity);

        $this->withSession($this->oauthTransaction())
            ->get('/auth/idir/callback?code=unused&state=valid-state')
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(
            'non_idir_identity',
            SecurityAuditEvent::query()->latest('id')->firstOrFail()->context['reason'],
        );
    }

    /**
     * PDEX signs government staff in with kc_idp_hint=azureidir, so the
     * identity_provider claim comes back as `azureidir`. Rejecting it would
     * lock out every valid IDIR user on a PDEX-shaped CSS integration.
     */
    public function test_azureidir_identity_provider_is_accepted(): void
    {
        $this->bindKeycloakClient(identity: $this->idirIdentity(identityProvider: 'azureidir'));

        $this->withSession($this->oauthTransaction())
            ->get('/auth/idir/callback?code=unused&state=valid-state')
            ->assertRedirect(route('portal.dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_fsg_identity_provider_is_accepted(): void
    {
        config(['services.keycloak.identity_provider_values' => 'idir,azureidir,fsg']);
        $this->bindKeycloakClient(identity: $this->idirIdentity(identityProvider: 'fsg'));

        $this->withSession($this->oauthTransaction())
            ->get('/auth/idir/callback?code=unused&state=valid-state')
            ->assertRedirect(route('portal.dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * The allowlist is closed: a provider outside it is refused even though the
     * account is otherwise well formed and carries a gov.bc.ca email.
     */
    public function test_identity_provider_outside_the_allowlist_is_rejected(): void
    {
        config(['services.keycloak.identity_provider_values' => 'idir']);
        $this->bindKeycloakClient(identity: $this->idirIdentity(identityProvider: 'azureidir'));

        $this->withSession($this->oauthTransaction())
            ->get('/auth/idir/callback?code=unused&state=valid-state')
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(
            'non_idir_identity',
            SecurityAuditEvent::query()->latest('id')->firstOrFail()->context['reason'],
        );
    }

    /**
     * PDEX registers /auth/keycloak/callback with CSS. Both spellings must run
     * the identical controller action so neither registration is a second,
     * less-verified code path.
     */
    public function test_pdex_shaped_callback_path_performs_the_same_verification(): void
    {
        $this->bindKeycloakClient(identity: $this->idirIdentity());

        $this->withSession($this->oauthTransaction('expected-state'))
            ->get('/auth/keycloak/callback?code=unused&state=wrong-state')
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertSame(
            'invalid_state',
            SecurityAuditEvent::query()->latest('id')->firstOrFail()->context['reason'],
        );
    }

    public function test_pdex_shaped_login_path_starts_the_same_flow(): void
    {
        $this->bindKeycloakClient(identity: $this->idirIdentity());

        $this->get('/idir-login')->assertRedirect('https://login.example.gov.bc.ca/authorize');
    }

    public function test_valid_idir_user_is_provisioned_as_owner_and_session_is_regenerated(): void
    {
        $identity = $this->idirIdentity();
        $this->bindKeycloakClient(identity: $identity);
        $this->withSession(['session_marker' => 'before-login']);
        $previousSessionId = session()->getId();

        $this->withSession($this->oauthTransaction())
            ->get('/auth/idir/callback?code=unused&state=valid-state')
            ->assertRedirect(route('portal.dashboard'));

        $user = User::query()->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousSessionId, session()->getId());
        $this->assertSame(PortalRole::ApplicationOwner, $user->portal_role);
        $this->assertSame('JSMITH', $user->idir_username);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::LoginSucceeded->value,
            'actor_user_id' => $user->id,
            'subject_user_id' => $user->id,
        ]);
    }

    public function test_existing_profile_is_updated_without_changing_admin_role(): void
    {
        $identity = $this->idirIdentity();
        $user = User::factory()->create([
            'keycloak_subject' => $identity->subject,
            'idir_user_guid' => $identity->idirGuid,
            'portal_role' => PortalRole::Administrator,
            'name' => 'Old Name',
        ]);

        $updated = app(IdirUserProvisioner::class)->provision($identity);

        $this->assertTrue($user->is($updated));
        $this->assertSame('Jane Smith', $updated->name);
        $this->assertSame(PortalRole::Administrator, $updated->portal_role);
    }

    public function test_logout_invalidates_local_session_and_redirects_to_end_session(): void
    {
        $user = User::factory()->create();
        $this->bindKeycloakClient(identity: $this->idirIdentity());

        $this->actingAs($user)
            ->post('/portal/logout')
            ->assertRedirect('https://login.example.gov.bc.ca/end-session');

        $this->assertGuest();
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::Logout->value,
            'actor_user_id' => $user->id,
        ]);
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function oauthTransaction(string $state = 'valid-state'): array
    {
        return [
            'keycloak_oauth_transaction' => [
                'state_hash' => hash('sha256', $state),
                'nonce' => 'expected-nonce',
                'pkce_verifier' => str_repeat('v', 64),
                'initiated_at' => now()->getTimestamp(),
            ],
        ];
    }

    private function idirIdentity(string $identityProvider = 'idir'): KeycloakIdentity
    {
        return new KeycloakIdentity(
            subject: 'keycloak-subject-123',
            identityProvider: $identityProvider,
            idirGuid: '018f8c2d7bb87db7a4a79d18ef3f0123',
            idirUsername: 'jsmith',
            displayName: 'Jane Smith',
            firstName: 'Jane',
            lastName: 'Smith',
            email: 'jane.smith@gov.bc.ca',
        );
    }

    private function bindKeycloakClient(
        ?KeycloakIdentity $identity = null,
        ?KeycloakAuthenticationException $exception = null,
    ): void {
        $this->app->instance(KeycloakClient::class, new class($identity, $exception) implements KeycloakClient
        {
            public function __construct(
                private readonly ?KeycloakIdentity $identity,
                private readonly ?KeycloakAuthenticationException $exception,
            ) {}

            public function authorizationRequest(string $state, string $nonce): AuthorizationRequest
            {
                return new AuthorizationRequest('https://login.example.gov.bc.ca/authorize', str_repeat('v', 64));
            }

            public function identity(
                string $authorizationCode,
                string $pkceVerifier,
                string $nonce,
            ): KeycloakIdentity {
                if ($this->exception) {
                    throw $this->exception;
                }

                if (! $this->identity) {
                    throw new KeycloakAuthenticationException('missing_test_identity');
                }

                return $this->identity;
            }

            public function endSessionUrl(): string
            {
                return 'https://login.example.gov.bc.ca/end-session';
            }
        });
    }
}
