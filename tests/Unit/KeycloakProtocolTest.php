<?php

namespace Tests\Unit;

use App\Auth\Keycloak\KeycloakAuthenticationException;
use App\Auth\Keycloak\KeycloakClient;
use App\Auth\Keycloak\KeycloakIdTokenVerifier;
use Tests\TestCase;

class KeycloakProtocolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.keycloak.base_url' => 'https://login.example.gov.bc.ca',
            'services.keycloak.realm' => 'standard',
            'services.keycloak.client_id' => 'bcaigw',
            'services.keycloak.client_secret' => 'test-secret',
            'services.keycloak.redirect_uri' => 'https://app.example.gov.bc.ca/auth/idir/callback',
            'services.keycloak.post_logout_redirect_uri' => 'https://app.example.gov.bc.ca',
            'services.keycloak.version' => '26.0.0',
            'services.keycloak.idp_hint' => 'fsg',
        ]);
    }

    public function test_authorization_request_uses_s256_pkce_state_and_nonce(): void
    {
        $request = app(KeycloakClient::class)->authorizationRequest('trusted-state', 'trusted-nonce');
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('trusted-state', $query['state']);
        $this->assertSame('trusted-nonce', $query['nonce']);
        $this->assertSame('fsg', $query['kc_idp_hint']);
        $this->assertSame(64, strlen($request->pkceVerifier));
        $this->assertNotSame($request->pkceVerifier, $query['code_challenge']);
    }

    /**
     * The shipped fallbacks must match PDEX so a CSS integration registered for
     * either application works without per-app overrides.
     *
     * The environment variables are cleared first: reading them through would
     * assert whatever the current container happens to export rather than the
     * default the repository actually ships.
     */
    public function test_shipped_defaults_match_the_fsg_integration(): void
    {
        $keys = [
            'KEYCLOAK_IDP_HINT',
            'KEYCLOAK_IDENTITY_PROVIDER_VALUES',
            'KEYCLOAK_IDENTITY_PROVIDER_CLAIM',
            'KEYCLOAK_IDIR_GUID_CLAIM',
            'KEYCLOAK_IDIR_USERNAME_CLAIM',
        ];

        $defaults = $this->withoutEnv($keys, fn (): array => (require base_path('config/services.php'))['keycloak']);

        $this->assertSame('fsg', $defaults['idp_hint']);
        $this->assertSame('idir,azureidir,fsg', $defaults['identity_provider_values']);
        $this->assertSame('identity_provider', $defaults['identity_provider_claim']);
        $this->assertSame('idir_user_guid', $defaults['idir_guid_claim']);
        $this->assertSame('idir_username', $defaults['idir_username_claim']);
    }

    /**
    * FSG's KEYCLOAK_SERVER_URL must win, with the older BCAIGW variable names
    * still honoured when they are the only ones set.
     */
    public function test_auth_server_url_falls_back_to_the_legacy_variable(): void
    {
        $read = fn (?string $fsg, ?string $authServer, ?string $legacy): ?string => $this->withoutEnv(
            ['KEYCLOAK_SERVER_URL', 'KEYCLOAK_AUTH_SERVER_URL', 'KEYCLOAK_BASE_URL'],
            function () use ($fsg, $authServer, $legacy): ?string {
                $this->putEnv('KEYCLOAK_SERVER_URL', $fsg);
                $this->putEnv('KEYCLOAK_AUTH_SERVER_URL', $authServer);
                $this->putEnv('KEYCLOAK_BASE_URL', $legacy);

                return (require base_path('config/services.php'))['keycloak']['base_url'];
            }
        );

        $this->assertSame('https://fsg.example/auth', $read('https://fsg.example/auth', 'https://auth.example/auth', 'https://legacy.example/auth'));
        $this->assertSame('https://auth.example/auth', $read(null, 'https://auth.example/auth', 'https://legacy.example/auth'));
        $this->assertSame('https://legacy.example/auth', $read(null, null, 'https://legacy.example/auth'));
        $this->assertNull($read(null, null, null));
    }

    /**
     * Runs $callback with the given environment variables removed, restoring
     * whatever was there before even if the callback throws.
     *
     * @param  list<string>  $keys
     */
    private function withoutEnv(array $keys, callable $callback): mixed
    {
        $previous = [];

        foreach ($keys as $key) {
            $value = getenv($key);
            $previous[$key] = $value === false ? null : $value;
            $this->putEnv($key, null);
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $key => $value) {
                $this->putEnv($key, $value);
            }
        }
    }

    private function putEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_id_token_claim_validation_rejects_wrong_nonce(): void
    {
        $verifier = app(KeycloakIdTokenVerifier::class);

        try {
            $verifier->assertExpectedClaims([
                'iss' => 'https://login.example.gov.bc.ca/realms/standard',
                'aud' => 'bcaigw',
                'sub' => 'subject',
                'nonce' => 'attacker-nonce',
            ], 'trusted-nonce');
            $this->fail('Nonce validation should have failed.');
        } catch (KeycloakAuthenticationException $exception) {
            $this->assertSame('invalid_nonce', $exception->reason);
        }
    }
}
