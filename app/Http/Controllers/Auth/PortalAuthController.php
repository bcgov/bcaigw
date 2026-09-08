<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Keycloak\IdirIdentityPolicy;
use App\Auth\Keycloak\KeycloakAuthenticationException;
use App\Auth\Keycloak\KeycloakClient;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Http\Controllers\Controller;
use App\Services\IdirUserProvisioner;
use App\Services\SecurityAuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PortalAuthController extends Controller
{
    private const SESSION_KEY = 'keycloak_oauth_transaction';

    public function redirect(
        Request $request,
        KeycloakClient $keycloak,
        SecurityAuditRecorder $audit,
    ): RedirectResponse {
        if ($request->user()) {
            return redirect()->route('portal.dashboard');
        }

        $state = Str::random(80);
        $nonce = Str::random(80);

        try {
            $authorization = $keycloak->authorizationRequest($state, $nonce);
        } catch (KeycloakAuthenticationException $exception) {
            return $this->failed($request, $audit, $exception->reason);
        }

        $request->session()->put(self::SESSION_KEY, [
            'state_hash' => hash('sha256', $state),
            'nonce' => $nonce,
            'pkce_verifier' => $authorization->pkceVerifier,
            'initiated_at' => now()->getTimestamp(),
        ]);

        return redirect()->away($authorization->url);
    }

    public function callback(
        Request $request,
        KeycloakClient $keycloak,
        IdirIdentityPolicy $identityPolicy,
        IdirUserProvisioner $users,
        SecurityAuditRecorder $audit,
    ): RedirectResponse {
        $transaction = $request->session()->get(self::SESSION_KEY);

        if (! is_array($transaction)) {
            return $this->failed($request, $audit, 'missing_transaction');
        }

        $state = $request->query('state');

        if (! is_string($state)
            || ! is_string($transaction['state_hash'] ?? null)
            || ! hash_equals($transaction['state_hash'], hash('sha256', $state))) {
            return $this->failed($request, $audit, 'invalid_state');
        }

        $initiatedAt = $transaction['initiated_at'] ?? null;

        if (! is_int($initiatedAt)
            || now()->getTimestamp() - $initiatedAt > (int) config('services.keycloak.transaction_ttl')) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->failed($request, $audit, 'expired_transaction');
        }

        $request->session()->forget(self::SESSION_KEY);

        if ($request->filled('error')) {
            return $this->failed($request, $audit, 'provider_error');
        }

        $code = $request->query('code');
        $nonce = $transaction['nonce'] ?? null;
        $pkceVerifier = $transaction['pkce_verifier'] ?? null;

        if (! is_string($code) || $code === ''
            || ! is_string($nonce) || $nonce === ''
            || ! is_string($pkceVerifier) || $pkceVerifier === '') {
            return $this->failed($request, $audit, 'invalid_callback');
        }

        try {
            $identity = $keycloak->identity($code, $pkceVerifier, $nonce);
            $identityPolicy->assertAllowed($identity);
            $user = DB::transaction(function () use ($identity, $users, $request, $audit) {
                $user = $users->provision($identity);

                Auth::login($user);
                $request->session()->regenerate();

                $audit->record(
                    $request,
                    AuditEventType::LoginSucceeded,
                    AuditOutcome::Succeeded,
                    actor: $user,
                    subject: $user,
                    context: ['identity_provider' => 'idir'],
                );

                return $user;
            });
        } catch (KeycloakAuthenticationException $exception) {
            return $this->failed($request, $audit, $exception->reason);
        } catch (QueryException $exception) {
            if (Auth::check()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw $exception;
        }

        return redirect()->intended(route('portal.dashboard'));
    }

    public function logout(
        Request $request,
        KeycloakClient $keycloak,
        SecurityAuditRecorder $audit,
    ): RedirectResponse {
        $user = $request->user();

        $audit->record(
            $request,
            AuditEventType::Logout,
            AuditOutcome::Succeeded,
            actor: $user,
            subject: $user,
        );

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($keycloak->endSessionUrl());
    }

    private function failed(
        Request $request,
        SecurityAuditRecorder $audit,
        string $reason,
    ): RedirectResponse {
        $audit->record(
            $request,
            AuditEventType::LoginFailed,
            AuditOutcome::Failed,
            context: ['reason' => $reason],
        );

        return redirect()
            ->route('home')
            ->with('authError', 'IDIR sign-in could not be completed. Please try again.')
            // Read back only when APP_DEBUG is on; see HandleInertiaRequests.
            ->with('authErrorReason', $reason);
    }
}
