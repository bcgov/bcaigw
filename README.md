# BC AI Gateway

BC AI Gateway (BCAIGW) is a Laravel 13 application using Inertia 2, Vue 3,
PostgreSQL, Redis, Sanctum, Keycloak OAuth, and OpenAPI.

## Local development

Requirements: Docker Desktop with Docker Compose.

```powershell
Copy-Item .env.example .env
docker compose up --build
```

The application is available at <http://localhost:8030>. Vite runs on port
5174, PostgreSQL is exposed on port 5458, and Redis is exposed on port 6387.
The compose `setup` service installs Composer dependencies, migrates the
database, and generates the OpenAPI document before the application, queue
worker, and scheduler start.

Useful commands:

```powershell
docker compose run --rm test
docker compose exec webserver php artisan l5-swagger:generate
docker compose exec webserver php artisan migrate
```

Health endpoints:

- `GET /up` - process liveness
- `GET /api/status` - application status
- `GET /api/ready` - PostgreSQL and Redis readiness
- `GET /api/documentation` - generated OpenAPI UI

## IDIR portal authentication

Configure a confidential Keycloak OpenID Connect client with the callback URL
`/auth/idir/callback`, standard authorization-code flow enabled, and PKCE S256
supported. Set the `KEYCLOAK_*` values in `.env`; never commit the client
secret. The client must map trusted `identity_provider`, `idir_user_guid`, and
`idir_username` claims into both the ID token and userinfo response.

Deployed HTTPS environments must set `SESSION_SECURE_COOKIE=true`; session
cookies remain HTTP-only and SameSite=Lax so the top-level OAuth callback can
return the browser transaction cookie.

The callback verifies the signed ID token against the realm JWKS, including
issuer, audience, authorized party, nonce, and userinfo subject. IDIR access is
accepted only when the configured trusted identity-provider claim is exactly
`idir`; email domains are not used as identity evidence.

Every new valid IDIR user receives only the `application_owner` role.
`administrator` must be assigned explicitly by an existing administrator or
an approved operational database process. Portal routes:

- `GET /auth/idir` - begin IDIR authentication
- `GET /auth/idir/callback` - Keycloak callback
- `GET /portal` - owner and administrator portal
- `GET /portal/admin` - administrator-only role management
- `POST /portal/logout` - local logout and Keycloak end-session redirect

Security audit records are append-only and contain event identifiers,
user references, request metadata, and allow-listed context only. OAuth
tokens, authorization codes, client secrets, and full userinfo documents are
never persisted or logged.

## Application lifecycle

Authenticated IDIR users can create applications under `/portal/applications`.
Applications use a public ULID in every portal URL while retaining an internal
database key. Owners provide contacts, organization, use case, target
environments, expected volume, data classification, and requested model
capabilities. Prompt/response retention starts enabled.

Owners must also provide the non-secret `CLIENT_ID` issued when they register
for BCAIGW API access through the
[BC Data API Directory](https://api.gov.bc.ca/devportal/api-directory).
`api_directory_client_id` is unique and will be the application lookup key
used by the later machine-auth request validation workstream; no secret or
machine credential is captured by this form.

The explicit lifecycle is:

`draft/rejected -> pending_approval -> approved -> active`

Administrators may reject pending applications, suspend active applications,
deactivate approved/active/suspended applications, and reactivate suspended or
inactive applications. Rejection, suspension, and deactivation require notes.
Activation requires both approval and administrator-confirmed configuration
readiness. Readiness requires rate, token, and cost budgets. Owners cannot
change lifecycle state, retention, budgets, or readiness directly.

Application owners can add existing IDIR users as owners or members. Members
have read-only access. The final owner cannot be removed or demoted. Every
lifecycle transition and sensitive change writes both immutable lifecycle
history and security audit records in the same database transaction.

## Machine authentication

Application owners and administrators can create named machine credentials
after an application is active and configuration-ready. Each credential has
explicit scopes and an optional expiry. Its high-entropy BCAIGW client secret is
shown once; only an Argon2id hash is persisted. The API Directory client ID and
the BCAIGW-generated client ID are identifiers, not secrets.

Request a short-lived opaque bearer token using HTTP Basic authentication:

```text
POST /oauth/token
Authorization: Basic base64(urlencode(BCAIGW_CLIENT_ID):urlencode(BCAIGW_CLIENT_SECRET))
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&
api_directory_client_id=API_DIRECTORY_CLIENT_ID&
scope=gateway.invoke
```

`client_id` and `client_secret` form fields are also accepted when Basic
authentication is not used. Mixed authentication methods are rejected. Success
responses use `Cache-Control: no-store`; authentication failures return the
same generic `invalid_client` response.

Opaque access tokens are stored only as SHA-256 hashes and validated against
the current application status/configuration version, credential state/version,
expiry, and scopes on every request. Suspending, deactivating, reconfiguring, or
revoking immediately invalidates existing tokens. Rotation can revoke
immediately or permit a bounded overlap; the previous credential and its tokens
fail closed at the overlap cutoff. No token introspection endpoint is exposed
because callers have no secure operational need for one.

Machine endpoints:

- `POST /oauth/token` - OAuth 2.0 `client_credentials` exchange, rate-limited
- `GET /.well-known/oauth-authorization-server` - authorization server metadata
- `GET /api/gateway/test` - protected principal/scope integration probe

The [NRSTS-style PHP client example](app/ClientExamples/NrstsGatewayClient.php)
caches a token only until its expiry buffer and calls the protected endpoint
with `Authorization: Bearer`. Never log token requests, client secrets, or
bearer values.

## Model control plane

Administrators manage provider accounts, upstream targets, public model aliases,
effective-dated pricing, and per-application grants at
`/portal/admin/model-control`. Supported provider types are AWS Bedrock, Azure
AI Foundry, and OpenAI-compatible vLLM.

Provider accounts should use workload identity or a platform secret reference.
Non-sensitive provider configuration accepts only a small provider-specific
allowlist of scalar metadata keys, preventing credentials from being hidden
under arbitrary JSON names.
Encrypted fallback configuration is hidden from serialization and disabled by
default; enabling `MODEL_CONTROL_ALLOW_ENCRYPTED_FALLBACK` is a controlled
break-glass option, not the normal deployment path.

Each active public OpenAI-compatible model ID maps to exactly one active target.
Mapping changes lock the alias, compare its configuration version, atomically
replace the pointer, append immutable mapping history, and audit the change.
Targets define provider-native model/deployment IDs, capabilities, token limits,
timeouts, safe connection limits, environment/region, health, and administrator
status. Referenced records are disabled or retired rather than deleted.

Pricing is append-only and records an effective timestamp, ISO currency, and
input, output, and cached-input cost per million tokens. The resolver selects
the price effective at request time so later usage reconciliation remains
historically reproducible.

Enabled grants can be created only for active applications and active mapped
aliases. Application owners see only their granted public aliases and
capabilities; provider accounts, endpoints, native deployment identifiers, and
configuration remain administrator-only.

Upstream endpoints must use HTTPS and resolve entirely to public addresses.
Loopback, private, reserved, link-local, mixed public/private DNS, URL
credentials, and cloud metadata destinations are rejected. Private HTTP vLLM
endpoints require all three explicit local-only controls: a matching
`MODEL_CONTROL_ALLOWED_HOSTS` entry plus both private-development and
HTTP-development flags. Production adapters must reuse the endpoint policy
immediately before connecting.

`ModelRoutingResolver` accepts the authenticated `MachinePrincipal`, requested
public model ID, and capability. It returns an immutable `RoutingDecision` only
when the application, grant, alias, target, provider, endpoint, capability, and
effective price are valid. The decision includes a platform secret reference
but never decrypted fallback configuration. Provider-specific inference remains
behind `UpstreamModelAdapter`.

## OpenAI-compatible gateway

Machine clients with the `gateway.invoke` scope can use:

- `GET /v1/models`
- `POST /v1/chat/completions`
- `POST /v1/responses`
- `POST /v1/embeddings`

The models endpoint returns only active aliases granted to the authenticated
application. Requests are validated against bounded gateway and selected-model
limits before an upstream call. Unsupported parameters return OpenAI-shaped
errors instead of being silently ignored.

Azure AI Foundry and vLLM calls preserve the validated hostname for TLS while
pinning cURL to the resolver addresses carried by `RoutingDecision`; redirects
are disabled. Bedrock uses the AWS SDK default workload-identity credential
chain with the same endpoint pinning rule. Secret references are resolved only
at the transport boundary and never enter responses or call-attempt records.

Chat and Responses streaming uses unbuffered SSE and always emits `[DONE]` after
normal completion or a sanitized framed error. Connection retries are bounded
and occur only before any upstream response; no retry occurs after stream bytes.
Per-application concurrent work is bounded with an atomic cache counter.

Every attempted inference stores request ID, operation, exact alias/target/grant
and pricing versions, timing, sanitized outcome, usage, and an upstream
correlation ID when available. Prompts, responses, bearer tokens, provider
credentials, and raw upstream errors are not retained.

Configure `GATEWAY_*` values to set payload, collection, timeout, retry, and
concurrency bounds. Production uses Redis for atomic concurrency tracking and
platform-mounted secrets below `GATEWAY_SECRET_DIRECTORY`. The
[OpenAI Python SDK example](examples/openai_client.py) exchanges machine
credentials and calls the gateway without changing the SDK wire contract.

## Quotas and budgets

Each active application can have request and token rates plus daily/monthly
token and cost budgets. Null limits are unlimited. All fixed windows use UTC:
minute keys are replaced at the next minute, daily keys at 00:00 UTC, and
monthly keys on the first day of the next UTC month.

Before an upstream call, BCAIGW atomically reserves one request and a
conservative maximum token/cost amount using the canonical input byte size,
requested output maximum, and exact pricing version in the routing decision.
Redis Lua scripts check and increment every applicable counter in one operation,
so gateway replicas cannot oversubscribe a budget. Redis/accounting failures
fail closed with `quota_accounting_unavailable`; inference is never dispatched
without a reservation.

Completion, provider failure, timeout, cancellation, and partial streams
reconcile the reservation. Reported usage is charged with exact integer
micro-currency arithmetic, including cached-input pricing, and only unused
capacity is released. Missing usage conservatively charges the full
reservation. OpenAI-shaped 429 responses distinguish `request_rate_limit`,
`token_rate_limit`, `token_budget_exceeded`, and `cost_budget_exceeded`, with
bounded retry/reset headers.

The append-only `quota_ledger_entries` records reservation, decision versions,
pricing, reconciliation, recovery, and administrator adjustments without
prompts or responses. A scheduled `quota:reconcile-abandoned` job
conservatively finalizes reservations left by interrupted workers. Owners see
only their applications' UTC usage projection; administrators configure limits
and make bounded, reason-required append-only adjustments.

The committed compose key and database credentials are local-development
defaults only. Set secure values in the deployment environment.

## Native development

PHP 8.3, Composer 2, Node.js 22, PostgreSQL, and Redis are required.

```powershell
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm run build
composer test
```

See [docs/architecture.md](docs/architecture.md) for the baseline architecture
and deployment asset notes.

## Call telemetry and retention

Every attempt records safe metadata only: routing and version identifiers,
latency and time-to-first-token, outcome and sanitized error category, tokens
including cached input, exact integer micro-unit cost, upstream correlation ID,
and strictly allowlisted caller metadata.

**Prompt/response retention is enabled by default and never expires
automatically.** Retained content is envelope-encrypted with a per-record data
key wrapped by a rotatable master key from a platform secret reference;
`APP_KEY` is rejected as content key material. Content is stored separately
from metadata and never appears in lists, exports, logs, audits, metrics or
traces. Viewing requires an explicit owner/administrator reveal with a reason
and is audited. Administrators crypto-shred content by selection, application or
range, leaving an append-only tombstone and preserving the quota ledger.

`GET /metrics` exposes token-gated Prometheus/OpenTelemetry metrics with
enforced cardinality caps. `php artisan telemetry:rollup` (scheduled hourly)
maintains UTC-day summary aggregates. Content keys are managed with
`telemetry:content-key-generate` and `telemetry:content-key-rotate`.

Read `docs/runbooks/telemetry-retention.md` before enabling this in an
environment with real users - it covers the privacy implications of
indefinite, enabled-by-default content retention, key rotation, deletion, and
backup handling.

## OpenShift deployment

Kustomize manifests live in `deploy/openshift` (base + DEV/TEST/PROD overlays).

```bash
./scripts/openshift/render.sh prod --image sha-1a2b3c4 --out build/prod.yaml
./scripts/openshift/validate.sh                 # all overlays
./scripts/openshift/deploy.sh test --image sha-1a2b3c4
./scripts/openshift/deploy.sh rollback test
```

Windows equivalents are `render.ps1` and `validate.ps1`. All entry points
delegate to `scripts/openshift/validate-manifests.php`, so bash, PowerShell and
CI run byte-identical assertions.

Workflows: `openshift-manifests.yml` validates on every change to `deploy/`;
`deploy-dev.yml`, `deploy-test.yml` and `deploy-prod.yml` build and roll out.
PROD promotes an already-tested tag and never rebuilds. Registry and namespace
come from repository variables (`OPENSHIFT_REGISTRY`,
`OPENSHIFT_IMAGE_REPOSITORY`, `OPENSHIFT_NAMESPACE_*`) since no git remote is
configured.

See `deploy/openshift/README.md` for layout and
`docs/runbooks/openshift-deployment.md` for secret rotation, migrations,
scaling, metrics, egress, backup/restore and rollback.
