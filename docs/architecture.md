# Baseline architecture

## Application

BCAIGW is a Laravel 13 monolith with an Inertia 2 and Vue 3 browser client.
Sanctum provides first-party and API-token authentication primitives.
`KeycloakProviderFactory` supplies the FSG-aligned OAuth client integration
without importing FSG domain code. L5 Swagger generates the OpenAPI contract
and documentation UI from application attributes.

## Runtime services

| Service | Responsibility |
| --- | --- |
| `webserver` | PHP 8.3 and Apache application runtime |
| `frontend` | Node.js 22 and Vite development server |
| `postgres` | PostgreSQL 16 primary data store |
| `redis` | Cache, session, and queue transport |
| `queue` | Laravel queue worker |
| `scheduler` | Laravel scheduler worker |
| `setup` | Dependency install, migrations, and OpenAPI generation |

`/up` is a process liveness probe. `/api/ready` checks PostgreSQL and Redis and
returns HTTP 503 when either required dependency is unavailable.

## Existing deployment assets

The imported `docker-compose/` directory contains earlier OpenShift and
container build references. It is intentionally retained as source material
for later deployment work. The active local environment is the root
`docker-compose.yml` with runtime images under `bin/`; legacy application
paths and MySQL defaults are not used by the active baseline.

## Configuration

Configuration is environment-driven. PostgreSQL and Redis are the application
defaults. Keycloak client values must be supplied before IDIR sign-in can be
used. The local compose defaults are not suitable for deployed environments.

## IDIR authentication and authorization

The portal uses OpenID Connect authorization code flow with PKCE S256. A
short-lived server-side transaction stores only a hash of state, nonce, and
the PKCE verifier. The callback consumes the transaction once, verifies the
state in constant time, validates the signed ID token through cached realm
JWKS, checks issuer/audience/authorized-party/nonce claims, and confirms the
userinfo subject matches the ID-token subject. Tokens and raw userinfo are
kept only in callback memory.

`IdirIdentityPolicy` requires the identity-provider claim to be an accepted
IDIR alias. Matching FSG, the IDIR GUID is trusted as asserted and is not
required to be a dashed UUID. The policy does not use email domains. The stable
Keycloak subject and IDIR GUID uniquely identify users. Initial provisioning assigns
`application_owner`; administrator access is always an explicit role change.
Laravel gates enforce portal and administration boundaries, while denial
middleware emits an audit event.

`security_audit_events` is append-only at the application model boundary.
Events cover login success/failure, logout, authorization denial, and
administrator role changes. Audit context rejects key names associated with
tokens, authorization codes, userinfo, passwords, and secrets.

## Application aggregate

`applications` is the owner-facing registration aggregate. Public routes bind
the 26-character ULID rather than the sequential database key. A normalized
owner/member pivot supports multiple authorized IDIR users and enforces at
least one owner. Domain fields include contacts, ministry/organization, use
case, environments, expected volume, data classification, requested model
identifiers/capabilities, retention preference, and administrator-controlled
rate/token/cost budgets.

Each application also records a unique, non-secret
`api_directory_client_id`, supplied by the owner from the BC Data API
Directory registration. It is intentionally captured now as the stable link
for later machine-auth validation; client secrets and machine credentials are
not part of this aggregate.

`ApplicationStateMachine` is the only lifecycle transition matrix. Services
lock the application row, compare `status_version`, mutate state/configuration,
and append lifecycle plus security audit records in one transaction. This
prevents stale approvals and configuration races. PostgreSQL rejects update or
delete operations on lifecycle history through a database trigger.

## Machine authentication and gateway boundary

`machine_credentials` belongs to the application aggregate but stores only a
generated public client identifier and an Argon2id secret hash. Owners and
administrators may create, rotate, or revoke credentials; creation and rotation
require the application to be active and configuration-ready. Secrets are
encrypted while held in the one-response portal flash and are never persisted
in plaintext.

`POST /oauth/token` is an API-middleware route rather than a browser/session
route, so CSRF and IDIR session authentication are not mixed into machine
authentication. It accepts the OAuth `client_credentials` grant using
`client_secret_basic` or `client_secret_post`, requires the non-secret API
Directory application identifier, returns generic client failures, and is
rate-limited by source address.

BCAIGW issues short-lived opaque tokens. Only a SHA-256 digest is stored.
`AuthenticateMachineRequest` performs full server-side validation on every
request and attaches a typed `MachinePrincipal` to request attributes without
merging claims into request input. Validation checks token expiry/revocation,
application binding, active/configuration-ready state, application status
version, credential expiry/revocation/rotation cutoff, and credential version.
`RequireMachineScope` then enforces route abilities.

Opaque tokens were selected instead of JWTs to guarantee immediate central
revocation without signing-key or JWKS operations. Application suspension,
deactivation, reconfiguration, credential revocation, and immediate rotation
all invalidate existing tokens. A bounded rotation overlap is the explicit
exception: the predecessor remains valid only until its recorded cutoff.
Authentication and scope failures plus sampled successful use are append-only
security audit events; identifiers and reason codes are recorded, never secrets
or tokens.

Model requests remain declarative identifiers from `config/gateway.php`.
Provider-specific inference I/O and runtime quota enforcement remain deferred
to their dedicated workstreams.

## Model control plane

The administrative routing catalog is normalized into provider accounts,
upstream targets, public model aliases, immutable alias-target versions,
immutable effective-dated pricing versions, and versioned application grants.
All external references use ULIDs. Database foreign keys prevent deletion of
referenced rows, model guards require disable/retire semantics, and PostgreSQL
triggers reject control-plane deletion plus mutation of mapping/pricing
history.

Provider accounts support AWS Bedrock, Azure AI Foundry, and OpenAI-compatible
vLLM. Workload identity and platform secret references are the default.
Ordinary JSON configuration is restricted to provider-specific allowlists of
non-sensitive scalar metadata keys. A
disabled-by-default fallback uses Laravel's `encrypted:array` cast and is hidden
from serialization; neither fallback data nor secret references are written to
audit context.

An active alias contains one nullable foreign-key pointer that is required to
reference an active target whenever the alias is active. Updates lock the alias,
compare `configuration_version`, validate capability compatibility, replace the
single pointer transactionally, append a mapping version, and audit only public
identifiers. There is no multi-target or weighted routing ambiguity.

`EndpointSecurityPolicy` allows HTTPS public destinations after hostname
allowlist and DNS resolution checks. It rejects credentials in URLs, queries,
fragments, localhost, metadata services, private/reserved/link-local addresses,
and mixed DNS answers. A private HTTP development target requires an explicit
local/testing environment, exact or wildcard host allowlist, and both
development exception flags. The routing resolver revalidates the endpoint at
decision time; data-plane adapters must also validate immediately before I/O.
Health checks disable redirects, enforce configured timeouts, send no provider
credentials, and retain no response body.

`ModelRoutingResolver` takes the authenticated machine principal plus model ID
and capability. It returns an immutable `RoutingDecision` containing only the
selected target, safe connection settings, limits, non-sensitive provider
context, platform secret reference, configuration versions, and effective
pricing. Decrypted provider fallback configuration is deliberately absent.
Explicit domain failure codes distinguish inactive applications, missing
grants, unavailable aliases/targets/providers, unsupported capabilities, and
missing pricing. Provider-specific request/response adaptation is isolated
behind `UpstreamModelAdapter`.

## Gateway data plane

Exact OpenAI-compatible routes are separated from portal sessions and require a
validated `MachinePrincipal` with `gateway.invoke`. The execution service
resolves a fresh immutable routing decision, constructs a bounded canonical DTO,
and creates a call attempt containing the exact catalog and pricing versions
before selecting the provider adapter. Prompts and generated content are not
written to call-attempt storage.

Azure AI Foundry and OpenAI-compatible vLLM share a strict adapter and pinned
HTTP transport. DNS addresses revalidated by the routing resolver are supplied
to cURL with `CURLOPT_RESOLVE`, retaining the original hostname for TLS SNI and
Host validation. Redirects are disabled. Bedrock uses the AWS SDK default
credential provider chain and applies the same cURL pinning. Platform secret
references are resolved only into outbound headers at the transport boundary.

Canonical results are rebuilt from recognized OpenAI fields; provider-specific
fields and malformed embedding, response, or SSE structures fail closed.
Upstream errors are translated to bounded public codes. A final `/v1` exception
boundary prevents unexpected provider details from reaching clients while
preserving Laravel reporting.

Streaming responses iterate provider bytes without full buffering, frame SSE,
flush each event, detect client cancellation, and end with `[DONE]`. Only
connection establishment may be retried, before an upstream response exists.
Target timeouts bound connection, idle, and total duration. An atomic
Redis-backed per-application counter bounds concurrent non-streaming work and
holds its slot until a streamed callback ends.

## Quota accounting

Quota enforcement is a reservation ledger coordinated by Redis and PostgreSQL.
Redis keys use the application public ID as a cluster hash tag and UTC
minute/day/month suffixes. One Lua evaluation checks request rate, token rate,
daily/monthly token budgets, and daily/monthly cost budgets before incrementing
all counters and creating an idempotent reservation marker. Window keys expire
after their accounting period plus cleanup grace.

The database ledger is append-only at both the model and PostgreSQL trigger
layers. It links each reservation and reconciliation to the gateway attempt,
application, pricing version, and alias/target/grant/application configuration
versions. Concurrent duplicate idempotency keys are rejected by the call
attempt unique constraint before quota reservation, preventing double charge.

Costs use integer micro-units. Pricing strings are multiplied with BCMath and
rounded conservatively, never converted to binary floating point. Reservations
use the larger full/cached input price; reconciliation applies cached-input
pricing only when the provider reports cached tokens. Missing usage charges the
reserved maximum, including failed and interrupted calls.

Redis is a required enforcement dependency and accounting fails closed. A
provider call is not made after a reservation or ledger failure. Stale reserved
entries are scanned by a singleton scheduled command and reconciled
conservatively; terminal ledger uniqueness and Redis reservation markers make
recovery idempotent. Administrator usage adjustments are bounded,
reason-required, atomically reflected in Redis, and appended rather than
rewriting historical usage.

## Call telemetry and content retention

Every gateway attempt records safe metadata: identity and routing public IDs,
the exact alias/target/grant/application/pricing versions used, queue, upstream
and total latency, time to first token, streaming and terminal outcome, HTTP
status, a sanitized error category, token counts including cached input, exact
integer micro-unit cost with currency, the upstream correlation ID, retry count,
and client-cancel/partial indicators. Caller `metadata` is allowlisted by key
pattern, coerced to scalars, truncated and count-bounded before storage.

Prompt and response content is captured only when the application retention flag
and the operator kill switch are both enabled. Content lives in
`gateway_call_contents`, physically separate from the indexed attempt
metadata, and is sealed with envelope encryption: a per-record AES-256-GCM data
key wrapped by a rotatable master key resolved from a platform secret reference.
`APP_KEY` is explicitly rejected as content key material. Payload AAD binds
ciphertext to the application, request and field, so ciphertext cannot be
relocated; the wrapped key AAD binds the key ID, so rotation rewraps keys
without touching payloads. Destroying a wrapped key crypto-shreds its payload,
which is what makes deletion effective against database backups.

Streaming capture never sits between the upstream event and the client write:
events are accumulated after they are flushed, so latency, flushing and
cancellation are unchanged, and content is finalized after completion,
cancellation or failure. Global and per-application byte and chunk ceilings
bound every payload; exceeding them marks the record truncated rather than
silently discarding. A sealing failure degrades to `content_state` of
`unavailable` with zero bytes and never falls back to plaintext.

Owners see call history for their own applications and administrators see all.
Content is excluded from lists, detail projections and exports; disclosure
requires the stronger owner/administrator reveal check plus a mandatory reason,
and is audited. Exports are row-bounded and CSV cells are escaped against
spreadsheet formula injection. Administrator deletion crypto-shreds content by
selection, application or date range, optionally redacting detail columns, while
preserving an accounting tombstone and the restrict-on-delete quota ledger. The
deletion record itself is append-only at both the model and PostgreSQL trigger
layers.

Dashboard summaries read `gateway_usage_rollups`, rebuilt hourly over a
bounded UTC-day lookback window, so no summary performs an unbounded scan.
Prometheus/OpenTelemetry-compatible metrics are exposed on a token-gated
`/metrics` endpoint with enforced series caps and low-cardinality labels that
exclude request, application, credential and user identifiers as well as all
content. Operational procedures are in `docs/runbooks/telemetry-retention.md`.

## OpenShift deployment topology

The gateway deploys to BC Gov OpenShift through Kustomize (`deploy/openshift`)
with a shared base and DEV/TEST/PROD overlays. `Deployment` replaces the
deprecated `DeploymentConfig`: image selection is pinned to an immutable tag by
CI rather than an ImageStream trigger, and the schema migration is an explicit
Job rather than a lifecycle hook, so it can be observed, retried and gated
independently of a rollout.

Four workloads separate the failure and scaling domains. `bcaigw-portal` serves
the IDIR portal, control-plane API and the metrics vhost; `bcaigw-gateway`
serves the OpenAI-compatible data plane and is sized for long-lived SSE
connections, where capacity is bounded by concurrent open streams rather than
requests per second; `bcaigw-queue` runs telemetry finalisation, rollups and
reservation recovery; `bcaigw-scheduler` is a singleton with `Recreate` strategy
and deliberately no PodDisruptionBudget, since a PDB on a one-replica workload
blocks node drains indefinitely.

PostgreSQL and Redis remain external platform-managed services. Every pod
volume is `emptyDir` over a read-only root filesystem, so the workloads hold no
durable state and satisfy `restricted-v2` without an SCC exception.

Migrations run once per release from `bcaigw-migrate` before rollout completion
is declared. Because Deployments update before that Job finishes, schema changes
must be backwards compatible with the running release; destructive changes take
the two-release expand/contract path.

`/metrics` is reachable only on port 8081 through the `bcaigw-metrics` Service,
restricted by NetworkPolicy to the monitoring namespaces, with the scrape token
as defence in depth. Egress lockdown is an opt-in Kustomize component because
NetworkPolicy is additive per direction - any Egress rule makes egress
default-deny for the selected pods. The CIDR allowlist and the application-level
SSRF host allowlist must stay in lockstep; neither alone is sufficient.
Kubernetes NetworkPolicy cannot match hostnames, so PrivateLink, an FQDN egress
proxy or `EgressFirewall` are the durable answers for SaaS providers.

Streaming correctness depends on four timeouts in series - API gateway, Route,
Apache and pod termination grace - each of which must exceed the longest
legitimate stream. Procedures are in `docs/runbooks/openshift-deployment.md`.
