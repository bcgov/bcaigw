# IDIR sign-in setup

Portal access is IDIR-only. There is no local account, no password form and no
seeded administrator — the only way in is an authorization-code flow against BC
Gov's Keycloak, which means **you must register an SSO integration before you
can sign in, even on localhost.**

If `KEYCLOAK_SERVER_URL`, `KEYCLOAK_REALM`, `KEYCLOAK_CLIENT_ID` and
`KEYCLOAK_CLIENT_SECRET` are empty, the home page shows *"IDIR sign-in is not
configured on this environment"* and the button is disabled. If they are set but
wrong, clicking **Sign in with IDIR** bounces back with *"IDIR sign-in could not
be completed."* That is the expected, fail-closed behaviour — the underlying
reason is recorded in `security_audit_events`, and is also shown on the page
when `APP_DEBUG=true`.

## Relationship to FSG

The integration surface deliberately matches FSG: the same environment variable
names, the same `kc_idp_hint=fsg` government-staff hint, the same
`openid profile email` scopes, and the same Keycloak PHP provider
(`stevenmaguire/oauth2-keycloak`). One CSS integration and one set of platform
secrets can therefore serve both applications.

BCAIGW additionally keeps PKCE, a
nonce bound to the ID token, ID token signature/issuer/audience validation,
session regeneration on login, an append-only security audit trail, and a strict
rule that no token or userinfo payload is ever logged.

## 1. Request an integration

Use the Common Hosted Single Sign-On (CSS) self-service portal:

<https://sso-requests.apps.gold.devops.gov.bc.ca/>

Choose:

| Setting | Value |
| --- | --- |
| Protocol | OpenID Connect |
| Client type | **Confidential** (the portal uses a client secret) |
| Usecase | Browser login |
| Identity provider | **IDIR** only |
| Environments | Development first; add Test/Prod when deploying |

Redirect URIs to register — one per environment where the app runs. The
`/auth/keycloak/callback` remains BCAIGW's default callback; BCAIGW also answers on
`/auth/idir/callback`, so register whichever you set as `KEYCLOAK_REDIRECT_URI`:

```
http://localhost:8030/auth/keycloak/callback
https://<dev-portal-host>/auth/keycloak/callback
https://<test-portal-host>/auth/keycloak/callback
https://<prod-portal-host>/auth/keycloak/callback
```

Also register the post-logout redirect URIs (`http://localhost:8030` and the
deployed portal origins) so RP-initiated logout returns cleanly.

Approval is normally quick for the Development environment.

## 2. Copy the credentials into `.env`

CSS gives you an installation JSON per environment. Map it as follows:

```dotenv
# Development:  https://dev.loginproxy.gov.bc.ca/auth
# Test:         https://test.loginproxy.gov.bc.ca/auth
# Production:   https://loginproxy.gov.bc.ca/auth
KEYCLOAK_SERVER_URL=https://dev.loginproxy.gov.bc.ca/auth
KEYCLOAK_REALM=standard
KEYCLOAK_CLIENT_ID=<"resource" from the installation JSON>
KEYCLOAK_CLIENT_SECRET=<"credentials.secret" from the installation JSON>

# Leave these at their defaults - they match FSG and the CSS standard realm.
KEYCLOAK_IDP_HINT=fsg
KEYCLOAK_IDENTITY_PROVIDER_CLAIM=identity_provider
# Comma separated allowlist; the claim echoes whichever alias the realm used.
KEYCLOAK_IDENTITY_PROVIDER_VALUES=idir,azureidir,fsg
KEYCLOAK_IDIR_GUID_CLAIM=idir_user_guid
KEYCLOAK_IDIR_USERNAME_CLAIM=idir_username
```

`KEYCLOAK_REDIRECT_URI` and `KEYCLOAK_POST_LOGOUT_REDIRECT_URI` are derived from
`APP_URL`, so keep `APP_URL=http://localhost:8030` locally.

Apply the change:

```powershell
docker compose exec -T webserver php artisan config:clear
```

## 3. Sign in

Browse to <http://localhost:8030/> and use **Sign in with IDIR**. On success you
land on `/portal` provisioned as an **application owner**.

## Why you are not an administrator

Administrator is never inferred from IDIR membership, from an email domain, or
from a Keycloak role. Every first-time user is provisioned with owner access
only, and the elevation is an explicit, audited role change.

To grant yourself administrator on a fresh local database, after your first
successful sign-in:

```powershell
docker compose exec -T webserver php artisan tinker --execute="\App\Models\User::query()->where('idir_username','YOUR_IDIR')->firstOrFail()->update(['portal_role' => \App\Enums\PortalRole::Administrator]);"
```

This is a local bootstrap convenience only. In deployed environments the first
administrator is granted deliberately and every subsequent change flows through
the audited admin UI.

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Immediate bounce to `/` with a generic error | Provider not configured, or `KEYCLOAK_SERVER_URL`/`REALM` wrong. Check `security_audit_events` for the reason. |
| `invalid_state` / `missing_transaction` | Session lost between redirect and callback. Confirm Redis is up and that you returned to the same origin you started from. |
| `non_idir_identity` | The token's `identity_provider` claim was not `idir`. The integration must have IDIR as an identity provider; Basic BCeID and GitHub logins are rejected by design. |
| `invalid_audience` / `invalid_authorized_party` | `KEYCLOAK_CLIENT_ID` does not match the client the token was issued to. |
| Redirect URI mismatch error from Keycloak | The callback URL is not registered on the CSS integration. It must match `KEYCLOAK_REDIRECT_URI` exactly, including scheme, port and path. |

## Deployed environments

In OpenShift the client secret is supplied by the `bcaigw-app` Secret and never
by the ConfigMap; the non-secret Keycloak settings live in `bcaigw-config`. See
[docs/runbooks/openshift-deployment.md](runbooks/openshift-deployment.md).

Because the pods sit behind the OpenShift Route and the BC Gov API gateway, the
redirect URI is only generated with the correct `https://` scheme when
forwarded headers are trusted — that is what `TRUSTED_PROXIES` is for. A wrong
value there shows up as Keycloak rejecting an `http://` redirect URI.
