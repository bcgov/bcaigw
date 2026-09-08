<?php

namespace App\Http\Middleware;

use App\Services\KeycloakProviderFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'idir_username' => $user->idir_username,
                    'portal_role' => $user->portal_role?->value,
                ] : null,
            ],
            /*
             | Whether IDIR sign-in can actually start. Without this the portal
             | offers a button that redirects straight back with a generic
             | failure, which is indistinguishable from a rejected login. The
             | boolean itself is not sensitive - it reveals only that setup is
             | incomplete, never any configured value.
             |
             | Resolved lazily so an unauthenticated page render does not pay
             | for it on requests that never read it.
             */
            'idir' => [
                'configured' => fn (): bool => app(KeycloakProviderFactory::class)->isConfigured(),
            ],
            'flash' => [
                'authError' => fn () => $request->session()->get('authError'),
                /*
                 | The machine-readable failure reason (invalid_nonce,
                 | non_idir_identity, configuration_error, ...) is exactly what
                 | an attacker probing the callback wants, so it is never sent
                 | to a browser outside local debugging. The full reason is
                 | always recorded in the security audit trail regardless.
                 */
                'authErrorReason' => fn () => config('app.debug')
                    ? $request->session()->get('authErrorReason')
                    : null,
                'machineCredential' => function () use ($request): ?array {
                    $encrypted = $request->session()->get('machineCredentialEncrypted');

                    return is_string($encrypted)
                        ? json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR)
                        : null;
                },
            ],
        ];
    }
}
