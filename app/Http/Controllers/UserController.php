<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Stevenmaguire\OAuth2\Client\Provider\Keycloak;

class UserController extends Controller
{
    /**
     * Send the authenticated user to their role-based module home.
     */
    public function home(): RedirectResponse
    {
        return $this->redirectToUserHome(Auth::user());
    }

    /**
     * Resolve the correct landing route for a user based on their role.
     *
     * Super Admin / Ministry Admin land in the Admin module; everyone else
     * (Ministry User / Ministry Guest) lands in the Portal module.
     */
    protected function redirectToUserHome(User $user): RedirectResponse
    {
        if ($user->hasRole(Role::SUPER_ADMIN) || $user->hasRole(Role::Ministry_ADMIN)) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('portal.dashboard');
    }

    /**
     * Show the login page (or redirect straight to home if already signed in).
     */
    public function login(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return $this->redirectToUserHome(Auth::user());
        }

        return Inertia::render('Auth/Login', [
            'appName' => config('app.name'),
        ]);
    }

    /**
     * Begin/complete the IDIR sign-in flow via Keycloak.
     */
    public function appLogin(Request $request): RedirectResponse
    {
        return $this->loginUser($request, $this->keycloakProvider(), 'idir');
    }

    /**
     * Build the Keycloak OAuth2 provider from configuration.
     */
    protected function keycloakProvider(): Keycloak
    {
        return new Keycloak([
            'authServerUrl' => config('keycloak.base_url'),
            'realm' => config('keycloak.realm'),
            'clientId' => config('keycloak.client_id'),
            'clientSecret' => config('keycloak.client_secret'),
            'redirectUri' => config('keycloak.redirect_uri'),
            'version' => config('keycloak.version'),
        ]);
    }

    /**
     * Drive the OAuth2 authorization-code exchange and sign the user in.
     */
    protected function loginUser(Request $request, Keycloak $provider, string $type): RedirectResponse
    {
        // Step 1: no authorization code yet -> send the user to Keycloak.
        if (! $request->has('code')) {
            $authUrl = $provider->getAuthorizationUrl([
                'scope' => 'openid profile email',
            ]);

            Session::put('oauth2state', $provider->getState());

            return redirect()->away($authUrl.'&kc_idp_hint='.config('keycloak.idp_hint'));
        }

        // Step 2: validate the returned state to guard against CSRF.
        $state = $request->input('state');
        if (! $state || $state !== Session::pull('oauth2state')) {
            return redirect()->route('login')->with('error', 'Invalid authentication state. Please try again.');
        }

        // Step 3: exchange the code for an access token and load the identity.
        $token = $provider->getAccessToken('authorization_code', [
            'code' => $request->input('code'),
        ]);

        $profile = $provider->getResourceOwner($token)->toArray();

        $idirGuid = $profile['idir_user_guid'] ?? null;
        if (! $idirGuid) {
            return redirect()->route('login')->with('error', 'IDIR account is required to sign in.');
        }

        $user = User::where('idir_user_guid', 'ilike', $idirGuid)->first();

        if ($user === null) {
            $user = $this->newUser($profile, $type);
        }

        if ($user->disabled) {
            return redirect()->route('login')->with('error', 'Your account is disabled.');
        }

        Auth::login($user, true);

        return $this->redirectToUserHome($user);
    }

    /**
     * Create a new user record from the IDIR resource owner profile.
     *
     * @param  array<string, mixed>  $profile
     */
    protected function newUser(array $profile, string $type): User
    {
        $user = User::create([
            'guid' => Str::uuid()->getHex()->toString(),
            'first_name' => $profile['given_name'] ?? null,
            'last_name' => $profile['family_name'] ?? null,
            'name' => $profile['name'] ?? ($profile['display_name'] ?? null),
            'email' => $profile['email'] ?? null,
            'disabled' => false,
            'idir_user_guid' => $profile['idir_user_guid'],
            'idir_username' => $profile['idir_username'] ?? ($profile['preferred_username'] ?? null),
        ]);

        $this->checkRoles($user, $type);

        return $user;
    }

    /**
     * Assign the default role for a freshly provisioned user.
     *
     * New IDIR users receive the Ministry Guest role so a fresh install is not
     * locked out; elevated access is granted separately via role management.
     */
    protected function checkRoles(User $user, string $type): void
    {
        $role = Role::where('name', Role::Ministry_GUEST)->first();

        if ($role !== null) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    /**
     * Sign the user out and return to the login page.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
