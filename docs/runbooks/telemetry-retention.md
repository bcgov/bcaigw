# Runbook — Call telemetry and content retention

Audience: BCAIGW platform administrators and operators.
Scope: the `telemetry-retention` workstream — metadata capture, encrypted prompt
and response retention, dashboards, exports, deletion, metrics, and rollups.

---

## 1. Privacy warning — read before enabling in an environment with real users

**Prompt and response content retention is ON by default.** Every application is
created with `prompt_response_retention_enabled = true`, and there is **no
automatic expiry**: retained content is kept indefinitely until an administrator
deletes it.

Consequences you must accept or mitigate before onboarding real traffic:

- Retained payloads may contain personal information, health information, or
  otherwise sensitive ministry data that end users typed into a client
  application. BCAIGW becomes a custodian of that content.
- Content is retained per application, not per end user. BCAIGW does not see end
  user identities on the data plane, so it cannot honour an individual
  subject-access or erasure request without a ministry-supplied correlation (via
  the allowlisted `metadata` field) or a broader range deletion.
- Because retention is indefinite, storage grows without bound. Plan a periodic
  deletion cadence (§6) or turn retention off per application.
- A privacy impact assessment (PIA) and security threat and risk assessment
  should explicitly cover indefinite, enabled-by-default content retention
  before production onboarding.

Mitigations already built in:

- Content is never written in plaintext: it is envelope-encrypted at rest (§3)
  in a table separate from the indexed metadata.
- Content never appears in list views, detail projections, exports, logs, audit
  records, metrics, queue payloads, traces, or exception reports.
- Viewing content requires a *separate, stronger* authorization plus an explicit
  reveal action with a mandatory reason, and every reveal is audited.
- Turning retention off takes effect immediately and prospectively; content is
  never collected retroactively when it is turned back on.

---

## 2. What is captured

### 2.1 Always captured (safe metadata, `gateway_call_attempts`)

Captured for **every** attempt regardless of the retention flag:

| Group | Columns |
| --- | --- |
| Identity | `request_id`, `application_id`, `machine_credential_id` |
| Routing | `operation`, `requested_model`, `resolved_model_alias`, `public_model_alias_id`, `upstream_target_id`, `provider_account_id` |
| Exact versions | `alias_configuration_version`, `target_configuration_version`, `grant_configuration_version`, `application_status_version`, `model_pricing_version_id` |
| Timing | `started_at`, `first_byte_at`, `completed_at`, `queue_latency_ms`, `upstream_latency_ms`, `total_latency_ms`, `time_to_first_token_ms` |
| Outcome | `streaming`, `status`, `http_status`, `error_category`, `error_code`, `retry_count`, `client_cancelled`, `partial_response` |
| Usage and cost | `prompt_tokens`, `completion_tokens`, `cached_input_tokens`, `total_tokens`, `cost_microunits`, `cost_currency` |
| Correlation | `upstream_correlation_id`, `idempotency_key_hash`, `client_metadata` |

Notes:

- **Cost is exact integer microunits** (`cost_microunits`) with an explicit
  `cost_currency`. Binary floating point is never used for money.
- `error_category` is a sanitized bucket produced by `App\Support\ErrorCategory`
  (for example `upstream`, `validation`, `quota`, `authorization`, `timeout`,
  `cancelled`, `internal`). Raw upstream error bodies are never persisted.
- `client_metadata` is caller-supplied and **strictly allowlisted** by
  `ClientMetadataSanitizer`: keys must match a conservative character/length
  pattern, values are coerced to scalars and truncated, and the entry count is
  bounded. Anything else is dropped silently. Never treat it as trusted, and
  never instruct clients to put content in it.

### 2.2 Conditionally captured (content, `gateway_call_contents`)

Captured only when the application's `prompt_response_retention_enabled` is true
**and** the operator kill switch `TELEMETRY_CONTENT_CAPTURE_ENABLED` is true:

- the canonical normalized request payload, and
- the normalized response payload; for streaming calls, a bounded normalized
  transcript (one JSON event per line, in the order the client received them).

Bounds:

| Setting | Default | Meaning |
| --- | --- | --- |
| `TELEMETRY_MAX_REQUEST_BYTES` | 262144 | Global request ceiling |
| `TELEMETRY_MAX_RESPONSE_BYTES` | 262144 | Global response ceiling |
| `TELEMETRY_MAX_STREAM_CHUNKS` | 512 | Streamed events captured per call |
| `applications.retention_max_content_bytes` | null | Per-application ceiling. It may only **lower** the global ceiling, never raise it. |

Truncated payloads set `request_truncated` / `response_truncated` and the stored
text ends with the `[bcaigw:truncated]` marker, so a truncated record can never
be mistaken for a complete one. `stream_chunk_count` records the number of
upstream events **observed**, which may legitimately exceed the number captured.

Streaming capture never sits between the upstream event and the client write:
events are appended to an in-memory accumulator *after* they have been flushed,
so streaming latency, flush behaviour, and cancellation propagation are
unchanged. Content is finalized after completion, cancellation, or failure, so
partial streams are still retained and flagged `partial_response`.

If sealing fails for any reason the content row is written with
`content_state = unavailable` and **no bytes**. Telemetry never fails a gateway
call, and never falls back to plaintext.

---

## 3. Envelope encryption and key management

### 3.1 Design

```
plaintext  ──AES-256-GCM(data key,   AAD = field binding)──►  ciphertext   (request/response columns)
data key   ──AES-256-GCM(master key, AAD = key id)───────►  wrapped_data_key
master key ◄── keyring resolved from the platform secret store, selected by key_id
```

- A fresh 256-bit **data key** is generated per content record.
- The data key is wrapped by the **active master key** from the keyring.
- Payload AAD binds ciphertext to
  `bcaigw.content.v1 | application_public_id | request_id | field`, so ciphertext
  cannot be relocated between records, between applications, or between the
  request and response columns.
- The wrapped-data-key AAD additionally binds the **master key id**. The payload
  AAD deliberately excludes the key id so that rotation can rewrap the data key
  without touching payload ciphertext.
- Destroying the wrapped data key **crypto-shreds** the payload. This is what
  makes deletion effective even against database backups.

### 3.2 `APP_KEY` is rejected as content key material

`PlatformContentKeyProvider` refuses `APP_KEY`, or any key equal to it, as
content key material. `APP_KEY` rotation is a routine Laravel operation and must
never destroy retained content; content keys belong in a platform secret store
with their own lifecycle.

### 3.3 Configuring the keyring

Generate a key:

```bash
php artisan telemetry:content-key-generate
```

Keyring format (JSON, key id → base64 32-byte key):

```json
{"kid-2026-09":"BASE64_KEY","kid-2026-06":"BASE64_PREVIOUS_KEY"}
```

Supply it in one of three ways, in order of preference:

```dotenv
# 1. Platform secret reference (preferred; OpenShift secret / mounted file)
BCAIGW_CONTENT_KEYRING_REFERENCE=env:BCAIGW_CONTENT_KEYRING_JSON
BCAIGW_CONTENT_KEYRING_REFERENCE=file:content-keyring   # read from GATEWAY_SECRET_DIRECTORY

# 2. Inline (local development only)
BCAIGW_CONTENT_KEYRING={"kid-2026-09":"..."}

# Always set the ACTIVE key used to wrap new records
BCAIGW_CONTENT_KEY_ID=kid-2026-09
```

If no usable keyring is configured, capture degrades safely: attempts are still
recorded, content rows are marked `unavailable`, and **no plaintext is
persisted**. Watch for a rising `content_state = unavailable` count.

### 3.4 Key rotation procedure

1. `php artisan telemetry:content-key-generate` to mint a new key.
2. Add it to the keyring **alongside** the current key. Do not remove the old key
   yet — existing records are still wrapped under it.
3. Set `BCAIGW_CONTENT_KEY_ID` to the new key id and roll the pods. All *new*
   records now use the new key.
4. Rewrap existing records in batches until none remain:

   ```bash
   php artisan telemetry:content-key-rotate --limit=500
   ```

   Rewrapping unwraps the data key using the record's own `key_id` and rewraps it
   under the active key. Payload ciphertext is untouched, so this is idempotent,
   interruptible, and O(records) rather than O(bytes).
5. Confirm completion:

   ```sql
   SELECT key_id, count(*) FROM gateway_call_contents GROUP BY key_id;
   ```
6. Only once no record references the old key, retire it from the keyring and
   from the secret store.

> **Warning:** retiring a key that records still reference makes those records
> permanently unreadable. That is a valid emergency shred, not a rotation.

### 3.5 Tamper detection

GCM authentication fails closed. A tampered, relocated, or wrong-key ciphertext
raises `ContentEncryptionException`; the reveal returns HTTP 409 and a **failed**
`telemetry.content_revealed` audit event is written. Investigate any such event
as a potential integrity incident.

---

## 4. Dashboards, detail views, reveal, and export

| Surface | Route name | Who |
| --- | --- | --- |
| Call history list | `portal.calls.index` | Application members (own apps), administrators (all) |
| Call detail | `portal.calls.show` | Same, scoped |
| Content reveal | `portal.calls.reveal` | Application **owner** or administrator, reason required |
| CSV/JSON export | `portal.calls.export` | Same scoping as the list |
| Deletion console | `portal.admin.telemetry.deletions.index` / `.store` | Administrators only |

Enforced rules:

- Non-administrators are always scoped to applications they belong to. Cross
  tenant reads are denied; no row is ever leaked into a list or export.
- Content **never** appears in the list, the detail projection, or an export. It
  is returned only by the explicit reveal endpoint.
- Reveal requires `ApplicationPolicy::revealCallContent` (owner or admin) — a
  strictly stronger check than `viewCallHistory` (member or admin) — plus a
  5–200 character reason, and emits `telemetry.content_revealed`.
- Exports are bounded by `TELEMETRY_EXPORT_MAX_ROWS`, pass every cell through
  `App\Support\SafeCsv` (neutralizes spreadsheet formula injection for values
  beginning `=`, `+`, `-`, `@`, or tab, and strips CR/LF/NUL), and emit a
  `telemetry.exported` audit event recording the filter and row count.

---

## 5. Metrics

`GET /metrics` serves Prometheus text exposition format `0.0.4`, which is also
consumable by an OpenTelemetry Prometheus receiver.

```dotenv
TELEMETRY_METRICS_ENABLED=true
TELEMETRY_METRICS_TOKEN=<random>     # required in any shared environment
TELEMETRY_METRICS_MAX_SERIES=2000
```

- With a token configured the endpoint requires `Authorization: Bearer <token>`
  (or `?token=`), compared using `hash_equals`. A missing or incorrect token
  returns **403**.
- With metrics disabled the route returns **404**, not 403, so it does not reveal
  that the feature exists.

Exposed under the `bcaigw` namespace: request counts, token counters, cost,
latency and time-to-first-token histograms, failure counters, concurrency and
saturation gauges, budget rejection counters, and upstream target health.

**Cardinality control is enforced, not advisory.** Labels are restricted to
low-cardinality dimensions (provider type, model alias, operation, outcome).
Request IDs, application IDs, credential IDs, user identifiers, and content are
excluded by construction. `MetricSeries` caps the store at
`TELEMETRY_METRICS_MAX_SERIES` and redacts over-long or over-cardinality label
values rather than growing unbounded. If redacted labels start appearing, fix the
label source — do not raise the cap.

---

## 6. Manual deletion

There is no automatic expiry. Deletion is an explicit, audited,
administrator-only action performed from the deletion console.

### 6.1 Scopes and modes

| Scope | Meaning | Bound |
| --- | --- | --- |
| `selected` | An explicit list of attempt identifiers | `TELEMETRY_DELETION_MAX_SELECTED` (500) |
| `application` | Everything for one application | — |
| `range` | One application over a date range | `TELEMETRY_DELETION_MAX_RANGE_DAYS` (366) |

| Mode | Effect |
| --- | --- |
| `content` | Crypto-shreds retained payloads only. All metadata preserved. |
| `detail` | Crypto-shreds payloads **and** redacts detailed metadata columns in place, leaving an accounting tombstone. |

Every request requires a reason of at least 10 characters and the literal
confirmation string `DELETE`.

### 6.2 What is always preserved

Attempts are **never hard-deleted**. `quota_ledger_entries.gateway_call_attempt_id`
uses `restrictOnDelete` precisely so that accounting can never be orphaned. After
a `detail` deletion the attempt row still carries `application_id`, `started_at`,
token counts, and cost — enough to reproduce billing and quota history — plus the
tombstone fields `telemetry_content_deletion_id`, `content_state = deleted`,
`content_deleted_at`, and `details_redacted_at`.

The deletion record in `telemetry_content_deletions` is **append-only**, enforced
both by an Eloquent model guard and by a PostgreSQL `BEFORE UPDATE OR DELETE`
trigger (`bcaigw_reject_content_deletion_mutation`). It records the actor,
reason, scope, mode, filters, and the counts of content rows shredded and
attempts redacted. Deletion is idempotent: re-running the same scope shreds
nothing further and records an honest zero-count tombstone.

### 6.3 Backups

Crypto-shredding is what makes deletion meaningful against backups: a restored
database contains ciphertext whose wrapped data key no longer exists, so the
payload is unrecoverable. However:

- A backup taken **before** the deletion, restored **with** a keyring that still
  contains the wrapping master key, still contains readable content — the wrapped
  data key is inside that backup too.
- Therefore a hard privacy commitment must be paired with the backup retention
  window. Document it, e.g. "content is unrecoverable at most N days after
  deletion, once pre-deletion backups have aged out."
- Emergency shred of everything: destroy the master key in the secret store. All
  records wrapped under it become permanently unreadable, in every backup. This
  is irreversible — require two-person control.

### 6.4 Deletion checklist

1. Confirm the request and the authority behind it (ministry request, PIA action,
   incident response).
2. Choose the narrowest scope that satisfies it.
3. Prefer `content` mode unless the metadata itself is the problem.
4. Record a meaningful reason — it is retained forever in the tombstone.
5. Verify afterwards: content rows gone, attempts show `content_state = deleted`,
   quota ledger entries intact, tombstone present with the expected counts.
6. Communicate the backup aging window to the requester.

---

## 7. Retention toggle operations

- `prompt_response_retention_enabled` and `retention_max_content_bytes` are
  **administrator-owned** controls on the application configuration screen.
- Changes are audited and apply **prospectively only**. Enabling never backfills;
  disabling never deletes existing content (use §6).
- `TELEMETRY_CONTENT_CAPTURE_ENABLED=false` is an environment-wide kill switch
  that can only reduce capture. It never enables capture for an application that
  has opted out.

Check the effective state for an application:

```bash
php artisan tinker --execute="\$a = App\Models\Application::where('public_id','APP_ID')->firstOrFail(); \
  dump(\$a->prompt_response_retention_enabled, \$a->retention_max_content_bytes, config('telemetry.content.capture_enabled'));"
```

---

## 8. Rollups

Dashboard summaries read `gateway_usage_rollups`, never the raw attempts table,
so summary queries stay bounded regardless of traffic volume.

```bash
php artisan telemetry:rollup
```

Scheduled hourly in `routes/console.php` with `withoutOverlapping()`. The rebuild
is idempotent: it deletes and reinserts a bounded lookback window
(`TELEMETRY_ROLLUP_LOOKBACK_DAYS`, default 3) inside a transaction, so a missed
run self-heals on the next tick. Buckets are **UTC days**, matching the
quota/budget timezone convention. Summary range requests are capped at
`TELEMETRY_SUMMARY_MAX_DAYS`.

If a scheduler outage exceeds the lookback window, temporarily raise
`TELEMETRY_ROLLUP_LOOKBACK_DAYS`, run `telemetry:rollup` once, then restore it.

---

## 9. Structured logs

The `telemetry` channel emits one JSON line per completed call containing the
same safe metadata as §2.1 — never content, never credentials, never tokens.
Set `LOG_TELEMETRY_CHANNEL=null` to disable it (this is the default under tests).

---

## 10. Incident quick reference

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `content_state = unavailable` climbing | Keyring missing or invalid, or a sealing error | Confirm `BCAIGW_CONTENT_KEY_ID` resolves in the keyring; check the `telemetry` log channel |
| Reveal returns 409 | GCM authentication failure — tampering, relocation, or a retired key | Inspect the failed `telemetry.content_revealed` audit event; confirm the record's `key_id` is still in the keyring; treat as an integrity incident |
| Reveal returns 403 | Actor is a member but not owner or administrator | Expected — reveal is deliberately stricter than list access |
| `/metrics` returns 404 | `TELEMETRY_METRICS_ENABLED=false` | Expected; enable only if the scrape is legitimate |
| `/metrics` returns 403 | Missing or incorrect scrape token | Fix the scrape configuration; do not remove the token |
| Redacted metric labels appearing | A label source went high-cardinality | Fix the label source; do not raise `TELEMETRY_METRICS_MAX_SERIES` |
| Unbounded storage growth | Indefinite retention with no deletion cadence | §6, or disable retention for the noisiest applications |
| Summary stale or empty | Scheduler not running rollups | Run `php artisan telemetry:rollup`; verify the `scheduler` service is healthy |

---

## 11. Live smoke test

`scripts/telemetry-smoke.php` verifies the paths the PHPUnit suite cannot
reach, because the suite runs on in-memory SQLite with an in-memory metrics
store: the PostgreSQL append-only tombstone trigger, the PostgreSQL rollup
bucket expression, real envelope sealing/rotation against PostgreSQL columns,
and the real Redis-backed metrics store and exporter.

```bash
docker compose exec -T webserver php scripts/telemetry-smoke.php
```

All database work runs inside a transaction that is always rolled back, so it is
safe against a populated environment. If no keyring is configured it mints an
ephemeral one for that process only and additionally exercises a full two-key
rotation. Exit code is non-zero if any check fails.

Pair it with a live endpoint check:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:8030/metrics                       # 403 without a token
curl -sS -H "Authorization: Bearer \" http://localhost:8030/metrics | head
```
