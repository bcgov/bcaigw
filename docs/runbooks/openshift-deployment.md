# Runbook — OpenShift deployment

Operational procedures for BCAIGW on BC Gov OpenShift. Manifest layout and
rendering are described in
[`deploy/openshift/README.md`](../../deploy/openshift/README.md).

- [Prerequisites](#prerequisites)
- [Secrets inventory](#secrets-inventory)
- [First-time namespace setup](#first-time-namespace-setup)
- [Routine deployment](#routine-deployment)
- [Migrations](#migrations)
- [Rollback](#rollback)
- [Secret rotation](#secret-rotation)
- [Content keyring rotation](#content-keyring-rotation)
- [Scaling](#scaling)
- [Metrics and monitoring](#metrics-and-monitoring)
- [Network policy and egress](#network-policy-and-egress)
- [Streaming and timeouts](#streaming-and-timeouts)
- [Backup and restore](#backup-and-restore)
- [Troubleshooting](#troubleshooting)
- [Design notes](#design-notes)

---

## Prerequisites

- `oc` ≥ 4.14 with `edit` on the target namespace
- PHP 8.3 and `composer install` (the manifest validator uses `symfony/yaml`)
- Bash — Git Bash is sufficient on Windows; `render.ps1` / `validate.ps1` cover
  the read-only paths natively
- External PostgreSQL 16 and Redis 7 already provisioned and reachable

---

## Secrets inventory

Every secret is created **out of band** by a platform administrator. No
manifest, script or workflow in this repository writes a secret value; they
reference Secrets by name only.

| Secret | Key | Notes |
| --- | --- | --- |
| `bcaigw-app` | `APP_KEY` | `base64:` Laravel key. Encrypts sessions and framework payloads. **Not** valid as content key material. |
| | `DB_PASSWORD` | PostgreSQL role password. |
| | `REDIS_PASSWORD` | Omit only if the Redis service enforces no auth. |
| | `KEYCLOAK_CLIENT_SECRET` | From the BC Gov CSS integration. |
| | `TELEMETRY_METRICS_TOKEN` | Bearer token for `/metrics`. Defence in depth behind the NetworkPolicy. |
| `bcaigw-content-keyring` | `content-keyring` | JSON envelope-encryption keyring for retained prompts/responses. See [content keyring rotation](#content-keyring-rotation). |
| `bcaigw-provider-credentials` | per provider | Only for providers that cannot use workload identity. Projected read-only at `/run/secrets`. |

```bash
oc -n "$NS" create secret generic bcaigw-app \
  --from-literal=APP_KEY="base64:$(openssl rand -base64 32)" \
  --from-literal=DB_PASSWORD='...' \
  --from-literal=REDIS_PASSWORD='...' \
  --from-literal=KEYCLOAK_CLIENT_SECRET='...' \
  --from-literal=TELEMETRY_METRICS_TOKEN="$(openssl rand -hex 32)"
```

> Prefer creating these through the platform's secret management (Vault /
> External Secrets) rather than by hand, so rotation is auditable. The command
> above is the break-glass form.

Provider access should use **workload identity** — IRSA for AWS Bedrock, Azure
Workload Identity for AI Foundry — so no long-lived cloud credential exists in
the namespace at all. `bcaigw-provider-credentials` is the fallback, and the
control plane stores such values with Laravel encrypted casts and redacted
serialisation.

---

## First-time namespace setup

1. **Label the namespace** so the monitoring and router policies match:

   ```bash
   oc label namespace "$NS" \
     app.kubernetes.io/part-of=bcaigw --overwrite
   ```

2. **Create the Secrets** (above).

3. **Patch the overlay** for the environment. Every `REPLACE-WITH-*`,
   `*.invalid` and the TEST-NET-1 `192.0.2.0/24` placeholder must be replaced.
   `deploy.sh` runs the validator in `--strict` mode and refuses to roll out
   while any remain.

   | Placeholder | Replace with |
   | --- | --- |
   | `bcaigw.invalid` / `bcaigw-api.invalid` | Real Route hostnames |
   | `bcaigw-postgres.invalid` | PostgreSQL service hostname |
   | `bcaigw-redis.invalid` | Redis service hostname |
   | `https://keycloak.invalid` | `https://{dev,test,}loginproxy.gov.bc.ca/auth` |
   | `bcaigw-placeholder` | CSS integration client ID |
   | `192.0.2.0/24` | Approved provider egress CIDRs |
   | `REPLACE-WITH-...` registry | Image registry and repository |

4. **Confirm the database exists** and the role can create tables. The migration
   Job does not create the database.

5. **Deploy** — see below. The first deploy runs every migration from scratch.

---

## Routine deployment

```bash
# DEV: build and deploy from the current ref
./scripts/openshift/deploy.sh dev --image sha-1a2b3c4

# TEST
./scripts/openshift/deploy.sh test --image sha-1a2b3c4 --namespace abc123-test

# PROD: promote exactly the tag that was exercised in TEST
./scripts/openshift/deploy.sh prod --image sha-1a2b3c4 --namespace abc123-prod
```

Add `--dry-run` for a server-side dry run that changes nothing.

TEST and PROD reject any tag that is not `sha-*` or `sha256:*`. A floating tag
makes a rollout non-reproducible: the same manifest can pull different bytes on
different days, and `rollout undo` then restores a pod spec that no longer
describes the image it originally ran.

In CI, use the `deploy-*.yml` workflows instead. PROD **promotes only** — see
[Design notes](#design-notes).

---

## Migrations

Migrations run as a dedicated Job (`bcaigw-migrate`), never from the container
entrypoint. An entrypoint migration executes concurrently on every replica of
every workload during a rolling deploy, which races on DDL and on the
append-only audit triggers.

`php artisan migrate --force` is idempotent, so re-running after a partial
failure is safe.

Because the Job name is static and a completed Job's pod template is immutable,
the deploy must delete before applying:

```bash
oc -n "$NS" delete job bcaigw-migrate --ignore-not-found --wait
oc -n "$NS" apply -f rendered.yaml
oc -n "$NS" wait --for=condition=complete job/bcaigw-migrate --timeout=900s
```

### Expand/contract

`deploy.sh` applies Deployments before the migration completes, so for a short
window the previous release is serving against the new schema. **Every migration
must be backwards compatible with the release currently running.** Destructive
changes take two releases:

1. *Expand* — add the new column/table, dual-write, backfill. Deploy.
2. *Contract* — once no running code reads the old shape, drop it. Deploy.

Never combine the two in one release.

### If the Job fails

```bash
oc -n "$NS" logs job/bcaigw-migrate --tail=200
```

`backoffLimit: 1` and `activeDeadlineSeconds: 900` mean a failed migration stops
the deploy and waits for a human rather than retrying against a database that is
telling you something. Fix forward or restore from the pre-deploy backup —
`rollout undo` does **not** roll back schema.

---

## Rollback

```bash
./scripts/openshift/deploy.sh rollback prod --namespace abc123-prod
```

This runs `oc rollout undo` on all four Deployments and waits for each. The CI
deploy job does the same automatically on failure.

**Rolling back code does not roll back the database.** If the failed release
included a destructive migration, restore PostgreSQL from the pre-deploy backup
instead. This is the reason for the expand/contract rule above: a release that
only *expands* is always safe to roll back.

To pin a specific revision:

```bash
oc -n "$NS" rollout history deployment/bcaigw-portal
oc -n "$NS" rollout undo deployment/bcaigw-portal --to-revision=7
```

---

## Secret rotation

All application secrets are consumed via `envFrom.secretRef`, which is read at
**container start only**. Updating the Secret has no effect on running pods, so
every rotation needs a restart:

```bash
oc -n "$NS" patch secret bcaigw-app \
  --type=merge -p '{"stringData":{"DB_PASSWORD":"new-value"}}'

oc -n "$NS" rollout restart deployment/bcaigw-portal
oc -n "$NS" rollout restart deployment/bcaigw-gateway
oc -n "$NS" rollout restart deployment/bcaigw-queue
oc -n "$NS" rollout restart deployment/bcaigw-scheduler
```

Per-secret notes:

- **`DB_PASSWORD` / `REDIS_PASSWORD`** — change the value at the service first,
  allowing both old and new to authenticate if the service supports it, then
  rotate here. Otherwise expect a brief window of failed connections; readiness
  probes will pull affected pods out of rotation.
- **`KEYCLOAK_CLIENT_SECRET`** — regenerate in CSS, update the Secret, restart.
  Sessions already established keep working; only new authorization-code
  exchanges use the secret.
- **`TELEMETRY_METRICS_TOKEN`** — update the Prometheus scrape credential in the
  same change window, or scrapes will 401 until they match.
- **`APP_KEY`** — rotating it invalidates every session and every value stored
  under a Laravel encrypted cast. It is **not** the content key. Treat rotation
  as a planned outage: users are logged out, and any encrypted-cast provider
  configuration must be re-entered.

---

## Content keyring rotation

Retained prompts and responses use envelope encryption: a fresh 256-bit data key
per record, wrapped by the active master key from a keyring. Each record stores
the `key_id` it was wrapped under, so rotation rewraps data keys without
re-encrypting content bytes — O(records), not O(bytes).

`BCAIGW_CONTENT_KEYRING_REFERENCE` holds a *reference*, never key material.
`file:content-keyring` resolves inside `GATEWAY_SECRET_DIRECTORY`
(`/run/secrets`), which is where the projected Secret volume is mounted.
`APP_KEY` is rejected as content key material by design: the content key must
outlive session encryption and be rotatable independently of it.

The keyring is a JSON object mapping key id to a base64 AES-256 key:

```json
{ "kid-2026-03": "base64...", "kid-2026-09": "base64..." }
```

Full mechanism, AAD binding and tamper semantics:
[`telemetry-retention.md` §3](telemetry-retention.md). The OpenShift-specific
steps are:

1. Mint a key:

   ```bash
   oc -n "$NS" exec deployment/bcaigw-portal -- php artisan telemetry:content-key-generate
   ```

2. Add it to the keyring Secret **alongside** the existing entries. Removing an
   entry that records still reference makes those records permanently
   unreadable — that is an emergency shred, not a rotation.

   ```bash
   oc -n "$NS" get secret bcaigw-content-keyring \
     -o jsonpath='{.data.content-keyring}' | base64 -d > keyring.json
   # add "kid-2026-09": "<base64 key>" to the object
   oc -n "$NS" set data secret/bcaigw-content-keyring \
     --from-file=content-keyring=keyring.json
   shred -u keyring.json 2>/dev/null || rm -f keyring.json
   ```

   Write `keyring.json` to a `tmpfs`/RAM disk if possible, never to a shared or
   backed-up location.

3. Point new writes at the new key. `BCAIGW_CONTENT_KEY_ID` lives in the
   ConfigMap, so patch the overlay and redeploy rather than using `oc set env`,
   which the next deploy would overwrite:

   ```yaml
   # overlays/<env>/patch-config.yaml
   BCAIGW_CONTENT_KEY_ID: "kid-2026-09"
   ```

   All *new* records are now wrapped under it. Existing records stay readable
   through their own `key_id`.

4. Rewrap existing records in batches until none remain. The command is
   idempotent and interruptible:

   ```bash
   oc -n "$NS" exec deployment/bcaigw-queue -- \
     php artisan telemetry:content-key-rotate --limit=500
   ```

5. Only once no record references the old key may it be retired from the keyring
   and from the secret store.

---

## Scaling

HPAs scale portal, gateway and queue on CPU and memory. The scheduler is a fixed
singleton.

```bash
oc -n "$NS" get hpa
oc -n "$NS" scale deployment/bcaigw-gateway --replicas=6   # temporary override
```

A manual `scale` is reverted by the HPA within a minute. To hold a floor, raise
`minReplicas` in the overlay's `patch-hpa.yaml` and redeploy.

Sizing notes:

- **Gateway** — the constraint is *concurrent open streams*, not requests per
  second. Each SSE response occupies an Apache worker for its whole lifetime, so
  capacity is `replicas × MaxRequestWorkers`. CPU stays low while streams are
  merely open, which means CPU-based autoscaling lags; watch saturation metrics
  and set `minReplicas` from the expected concurrent-stream ceiling.
- **Queue** — scale on queue depth rather than CPU where the platform supports a
  custom metric. Telemetry finalisation and rollups are bursty.
- **PDBs** — `minAvailable: 2` in PROD (1 in DEV/TEST). The scheduler has no PDB
  on purpose; a PDB on a singleton blocks node drains forever.
- **Topology spread** — PROD uses `DoNotSchedule` so replicas land on different
  nodes. If the cluster is small enough that this leaves pods `Pending`, relax
  to `ScheduleAnyway` rather than raising replica counts.

---

## Metrics and monitoring

`/metrics` is served by the **portal** only, on a separate Apache vhost on port
**8081**, exposed through the `bcaigw-metrics` Service.

Two independent controls:

1. **NetworkPolicy** (`bcaigw-allow-metrics-from-monitoring`) — only the
   monitoring namespaces may open a connection to 8081. This is the primary
   control. Nothing in it grants access to 8080, and the router cannot reach
   8081.
2. **`TELEMETRY_METRICS_TOKEN`** — a bearer token. Defence in depth for the case
   where the policy is missing, mis-labelled, or the cluster plugin does not
   enforce it as expected. It must stay set.

Scrape the **Service**, never a PodMonitor. `RedisMetricsStore` counters are
global — every replica reports the same cluster-wide totals, so scraping pods
individually multiplies every value by the replica count.

```bash
oc -n "$NS" get servicemonitor bcaigw
oc -n "$NS" exec deployment/bcaigw-portal -- \
  curl -fsS -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8081/metrics | head
```

Series are capped at `TELEMETRY_METRICS_MAX_SERIES` and high-cardinality labels
(request IDs, credential IDs, content) are excluded or redacted — see
[`telemetry-retention.md`](telemetry-retention.md).

### Health endpoints

| Path | Checks | Used by |
| --- | --- | --- |
| `/api/status` | Process is up. No dependencies. | liveness, startup |
| `/api/ready` | PostgreSQL + Redis reachable; 503 otherwise | readiness |
| `/up` | Laravel default | ad hoc |

Readiness deliberately differs from liveness: a pod that has lost Redis should
leave the load-balancer rotation, not be killed and restarted into the same
failure.

---

## Network policy and egress

Base policy is **ingress-only** on purpose. NetworkPolicy is additive per
direction — the moment any policy selecting these pods declares an `Egress`
rule, egress becomes default-deny for them. Making egress lockdown an opt-in
component means a namespace adopts it deliberately, with the provider CIDRs it
has actually been granted, instead of the data plane silently losing upstream
connectivity the first time someone applies the base.

```bash
# enable it in an overlay
components:
  - ../../components/egress-lockdown
```

The component permits DNS (UDP/TCP 53 to `openshift-dns`), the database, Redis,
and an explicit provider allowlist. Overlays replace the placeholder CIDR list
with a JSON 6902 `replace` on `/spec/egress` — **not** a strategic merge patch,
whose list semantics would append to the TEST-NET-1 placeholder instead of
replacing it.

### `to: []` is allow-all

An empty peer list means *allow every destination*, not *deny*. It is the single
most common way a NetworkPolicy silently does nothing. The validator fails on it.
Fail-closed placeholders use TEST-NET-1 `192.0.2.0/24`, which is guaranteed
unroutable.

### FQDN limitation

Kubernetes NetworkPolicy matches **IP ranges, not hostnames**. Bedrock, Azure AI
Foundry and any SaaS endpoint sit behind large, changing IP ranges, so a CIDR
allowlist is necessarily coarse and will drift.

Mitigations, in order of preference:

1. **PrivateLink / Private Endpoint** into a fixed subnet, and allow only that.
2. **An egress proxy** with FQDN filtering; allow only the proxy.
3. **AdminNetworkPolicy / EgressFirewall** (OVN-Kubernetes supports DNS names in
   `EgressFirewall`) — cluster-scoped, so it needs a platform administrator.
4. Provider CIDR allowlist, reviewed on a schedule. This is what the overlays
   ship, and the current CIDRs are **illustrative and must be confirmed with the
   platform team**.

The application-level SSRF allowlist (`MODEL_CONTROL_ALLOWED_HOSTS`) and the
NetworkPolicy must stay in lockstep. The application refuses unlisted hosts and
resolves-then-pins the target IP; the NetworkPolicy refuses unlisted addresses.
Neither alone is sufficient.

### hostNetwork router caveat

`bcaigw-allow-from-router` matches the router by namespace label. If the
cluster's ingress controllers run with `hostNetwork` — common on bare metal and
vSphere — the router's source address is the **node IP**, and no namespace
selector matches. Ingress then fails closed and Routes return 503.

Verify before assuming the policy works:

```bash
oc -n "$NS" get pods -l app.kubernetes.io/component=portal
curl -sS -o /dev/null -w '%{http_code}\n' "https://$ROUTE_HOST/api/status"
```

If that returns 503 while the pod is Ready, add an `ipBlock` for the node CIDR
to the router ingress rule.

---

## Streaming and timeouts

Four timeouts sit in series on a streaming response. If any is shorter than a
legitimate stream, it severs mid-response:

| Layer | Setting | Value |
| --- | --- | --- |
| BC Gov API gateway | upstream read timeout | must be ≥ Route timeout |
| OpenShift Route | `haproxy.router.openshift.io/timeout` | `300s` |
| Apache | `Timeout` (`hardening.conf`) | ≥ Route timeout |
| Pod | `terminationGracePeriodSeconds` | ≥ longest acceptable stream |

The gateway Route also sets `disable_cookies` (bearer clients have no cookie
jar and a router affinity cookie in a machine-to-machine response is noise),
`balance: leastconn` (a pod holding several minutes-long streams is far busier
than its request count implies), and `set-forwarded-headers: append`.

Apache disables output buffering and compression for `/v1`, and HAProxy does not
buffer chunked responses, so events reach the client as they are produced. If
you add a compression module or a buffering proxy in front, streaming will
appear to hang until completion.

`TRUSTED_PROXIES` covers two hops — API gateway then Route — so
`X-Forwarded-For` resolves to the real client. Only cluster/private ranges are
trusted.

---

## Backup and restore

**Nothing in this deployment stores durable state.** All volumes are `emptyDir`;
the root filesystem is read-only. Durable state lives in:

- **PostgreSQL** — applications, credentials, control-plane configuration, the
  immutable quota ledger, telemetry metadata and encrypted content payloads.
- **Redis** — quota counters and reservations, sessions, cache, queues.
- **The content keyring Secret** — without it, retained content is unrecoverable.

### Before every PROD deploy

Use the platform's managed backup (CrunchyDB/Patroni scheduled backups) as the
primary mechanism. The application image ships only `pdo_pgsql`, **not** the
PostgreSQL client tools, so an ad-hoc dump needs a throwaway client pod:

```bash
oc -n "$NS" run bcaigw-pgdump --rm -i --restart=Never --quiet \
  --image=postgres:16-alpine \
  --env=PGPASSWORD="$(oc -n "$NS" get secret bcaigw-app -o jsonpath='{.data.DB_PASSWORD}' | base64 -d)" \
  -- pg_dump --format=custom --no-owner \
       -h bcaigw-postgres -U bcaigw bcaigw \
  > "bcaigw-$(date -u +%Y%m%dT%H%M%SZ).dump"
```

That command puts the password in the pod's environment; on a cluster where pod
specs are broadly readable, prefer the managed backup or a Job that mounts the
Secret instead.

### Restore

1. Scale the application to zero so nothing writes during the restore:
   `oc -n "$NS" scale deployment --all --replicas=0`
2. `pg_restore --clean --if-exists` into the target database.
3. Restore the content keyring Secret **from the same point in time**. A newer
   keyring may lack entries that older records were wrapped with; an older
   keyring will not decrypt newer records.
4. Scale back up and run the migration Job to reapply anything newer.

Redis is deliberately **not** restored. Quota counters are reconstructible and a
stale counter set is worse than an empty one — reservations recover through the
abandoned-reservation job. Restoring Redis would also resurrect sessions that
should have ended.

### Backups and deletion requests

Content deletion (`telemetry-retention.md`) shreds encrypted payloads in the
live database, but **backups taken before the deletion still contain them**.
Backup retention is therefore an upper bound on any deletion guarantee. Record
the backup retention period alongside the deletion request, and prefer keyring
entry destruction when a hard cryptographic-erasure guarantee is required.

---

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Pods `CreateContainerConfigError` | A referenced Secret does not exist. The deploy workflow checks for `bcaigw-app` and `bcaigw-content-keyring` first. |
| Route returns 503, pods Ready | Router blocked by NetworkPolicy — see the [hostNetwork caveat](#hostnetwork-router-caveat). |
| Readiness failing, liveness passing | PostgreSQL or Redis unreachable. Check the egress policy and the service hostnames. |
| Streams cut at a round number of seconds | A timeout in the chain above is shorter than the stream. |
| `/metrics` 401 from Prometheus | `TELEMETRY_METRICS_TOKEN` and the scrape credential are out of sync. |
| Metrics values are a multiple of replica count | Something is scraping pods rather than the `bcaigw-metrics` Service. |
| `field is immutable` applying the Job | The previous `bcaigw-migrate` was not deleted first. |
| Deploy refuses to start | Strict validation found a placeholder or a mutable tag. Run `./scripts/openshift/validate.sh --overlay=prod --strict`. |
| Upstream calls fail only in TEST/PROD | The provider host is allowed by `MODEL_CONTROL_ALLOWED_HOSTS` but its IP is not in the egress policy, or vice versa. |

---

## Design notes

**Deployment, not DeploymentConfig.** `DeploymentConfig` is deprecated as of
OpenShift 4.14. It offers ImageStream triggers and lifecycle hooks, neither of
which this deployment wants: image selection is pinned to an immutable tag by
CI, and the migration hook is an explicit Job so it can be observed, retried and
gated independently of a rollout. `Deployment` also gets `maxSurge`/
`maxUnavailable`, `rollout status` and `rollout undo` with no OpenShift-specific
behaviour, which keeps the manifests portable. The `docker-compose/openshift`
references retained from the original project are kept for their *configuration*
intent — env wiring, resource sizing, route settings — not their DeploymentConfig
shape.

**PROD promotes, never rebuilds.** A rebuild from an identical commit can still
produce a different image, because base images and OS packages move underneath
it. Promoting the exact tag exercised in TEST is the only way the bytes in
production are the bytes that were tested.

**Placeholders are non-routable.** `*.invalid` is reserved by RFC 2606 and
`192.0.2.0/24` by RFC 5737. A mis-wired overlay therefore fails to connect
rather than reaching something real.

**Read-only root filesystem.** Writable paths are explicit `emptyDir` mounts:
`/tmp`, `storage/`, `bootstrap/cache`. Combined with `runAsNonRoot`,
`drop: ALL`, `allowPrivilegeEscalation: false` and `RuntimeDefault` seccomp, this
satisfies the `restricted-v2` SCC without an exception. The image does not
hardcode a UID beyond `USER 1001`; OpenShift assigns one from the namespace
range and the entrypoint tolerates an arbitrary UID.
