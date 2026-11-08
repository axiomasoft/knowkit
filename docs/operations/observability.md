# Observability and diagnostics

The vendor-free core exports typed operational observations and a value-free diagnostics
snapshot. Hosts opt in through constructor injection. Loading a package opens no stream,
constructs no adapter, runs no provider probe, and starts no background loop.

Compose `KnowKit\Http\Server\Diagnostics\Application` around the host application passed
to the authenticated HTTP `Server`. It handles the existing `GET /v1/readiness`,
`GET /v1/capabilities` and `GET /v1/coverage` routes. Authentication and current host
scope checks apply to all three, including a second check immediately before exporting
diagnostics. Responses retain the normal envelope, Request-ID and `Cache-Control: no-store`.
The envelope's degraded flag, effective-profile fingerprint and warning also reflect optional outages.
There is no unauthenticated operator endpoint.

`DiagnosticState::current(Scope)` is a host-owned, request-local metadata read. Supply
the complete scope, the exact `HostProfile::digest()`, schema/tombstone replay state,
dependency observations and actual coverage/index outcomes. It must not load document
bodies, resolve adapter factories or make model calls. Exceptions propagate as failures;
unavailable/corrupt metadata never becomes empty coverage. This contract does not supply
or qualify an external OCR, vector or model adapter.

Each dependency observation explicitly declares whether it is required and enabled.
Installed, enabled, configured, verified, failed, unknown and ready remain separate.
Verification binds the full host profile fingerprint. Missing bound observations are
required/unknown; configuration or credentials alone never imply verification. A lexical
adapter also needs a successful index observation. Missing schema, incomplete tombstone
replay or any unready required dependency prevents readiness. Optional enabled outages
degrade the profile and remove affected capabilities; they never replace a required
profile with a fallback. Disabled optional dependencies do not degrade health.

The `diagnostics:1` data contains:

| Field | Meaning |
| --- | --- |
| `health` | Process live; required readiness; optional degradation; schema/replay gates |
| `dependencies` | Hashed adapter reference, required flag, distinct state booleans and outcome/reason, package/weights fingerprints |
| `capabilities` | Sorted capabilities of currently ready adapters only |
| `effectiveProfile` | Full host/factory fingerprints and whether the required profile is satisfied/degraded |
| `index` | Observed outcome, including denied/unknown/unavailable/incompatible |
| `coverage` | Actual complete/partial/unknown state, numeric counts, missing-feature count and diagnostic severity counts |

Readiness is an observation in a successful HTTP envelope (HTTP 200); operators must
check `data.health.ready`. An unavailable metadata read returns a failure (HTTP 503).
Absent coverage is `unknown`. Free-form config values, profile names, diagnostic codes,
segment IDs, provider URLs and filesystem paths are omitted from this export.

Create a `TelemetryFactory` with a host `ObservationSink`, `Clock` and a secret redaction
key of at least 32 bytes. Call `start()` with the request correlation ULID, trusted scope
and optional host run reference. The key is never exported. Tenant/run references are
domain-separated HMAC-SHA256 values; run references also bind the tenant. Keep the same
host key for the period in which operators need to join events. Propagate the resulting
request/job-local `Telemetry` explicitly; do not share a tenant-bound instance across
tenants. `withLease()` preserves correlation/run/usage and adds job/attempt/fence without
the lease token; `withUsage()` adds a committed usage ID. These objects grant no access
and do not replace current policy/lifecycle checks.

Use the optional application wrappers at existing boundaries:

- `Observability\Jobs` decorates `JobQueue`, preserving conditional fence/expiry results.
  A false heartbeat/checkpoint/completion emits `incompatible/stale_lease` with no retry.
- `Observability\EmbeddingTransport` decorates the transport inside `ConfiguredProvider`.
  A timeout or unclassified transport exception preserves uncertainty and rethrows the
  original exception. Successful transport completion does not claim validated vectors
  or durable provider acceptance; those remain `ConfiguredProvider` responsibilities.
- `Observability\Disclosure` decorates the public disclosure application and records
  outcomes and committed evidence/replay usage IDs without serializing evidence.
- Inject optional `Telemetry` into `DeletionWorker` and the tenant-bound outbox
  `Dispatcher`. Purge receipts preserve unknown/unsupported outcomes. Outbox lag comes
  from the existing tenant-bound store status; failure to read it reports unavailable,
  never a fabricated zero. Hosts must use the telemetry of that store's tenant.
- `Telemetry::measure()` wraps a Result-returning stage without changing the result or
  swallowing the original exception. `emit()` accepts enums and numeric `Measurements`
  for quota usage/limits and orphan counts supplied by the authoritative host ledger.
  The HTTP decorator also observes disclosure denials and known quota error codes.

`KnowKit\Adapter\Observability\JsonLines` writes to a host-opened stream. Each
`observation:1` line contains UTC timestamp, level, event, correlation ID, redacted tenant/run
references, optional usage/job/attempt/fence, stage, outcome, closed reason code, integer
durationMs/count, optional digest reference and metric samples. There is no arbitrary
context/message input. Document text, raw provider inputs/receipts, exception messages,
secrets and lease tokens cannot enter the serializer. Unrecognized upstream error codes
become `internal_failure`; the domain result itself is preserved. Sink/clock failures
are isolated so telemetry cannot turn a committed operation into a retry. Monitor the
host logging pipeline separately: telemetry is not the durable security/usage audit.

Metric samples use only finite `event/stage/outcome/reason` labels:

| Metric | Kind | Interpretation |
| --- | --- | --- |
| `knowkit_events_total` | Counter increment | Observation count/work count; filter disclosure/denied, lease/stale_lease, provider/unknown, purge/unknown, quota or checkpoint stage |
| `knowkit_duration_ms` | Histogram observation | Monotonic elapsed time, integer milliseconds |
| `knowkit_outbox_lag_seconds` | Gauge | Age of oldest outstanding event |
| `knowkit_object_orphans` | Gauge | Host-observed orphan count |
| `knowkit_quota_used`, `knowkit_quota_limit` | Gauges | Authoritative finite quota measurements |

Correlation, tenant, usage, run, job and digest references are log fields, never metric
labels. Metrics count observations, including replay observations; they are not a billing
ledger or a deduplicated count of durable operations. Do not install both HTTP and library
wrappers into the same metric series without accounting for the two observation boundaries.

Synthetic versioned fixtures and the required-case mapping live in
`tests/ops/observability/fixtures/v1` and
`tests/fixtures/check-groups/ops-observability.json`. They include provider timeout,
stale lease, denied disclosure, optional OCR outage, unknown purge receipt, secret/content
canaries, trace propagation and DT-Q10 lazy unavailable/corrupt metadata diagnostics.
They do not certify live external adapters. Fixture digest changes require an explicit
version rationale. The existing QA runner verifies digests, executed required case IDs
and failures/skips/empty selections. Addressed tests can be run individually with
`vendor/bin/phpunit --configuration phpunit.xml tests/ops/observability/TelemetryTest.php`
(and the corresponding DiagnosticsTest, OperationsTest and FixtureTest files).

Reuse decisions and verified donor hashes are recorded in
`tests/ops/observability/evidence/P9.1-reuse.json`. Metadata/readiness and lazy-discovery
patterns adapt DeepTutor at `f7aed0744c36c5afef16a4261c31ebee1208cac2`, copyright
2025 Data Intelligence Lab, The University of Hong Kong, under Apache-2.0. See the
[retained license](../architecture/third-party/DeepTutor-LICENSE.txt) and
[notices](../architecture/third-party/DeepTutor-THIRD_PARTY_NOTICES.md). No Python runtime
or upstream discovery/network code is imported. AgenTools' embedding-identity candidate
is rejected for this item because KnowKit already has the stronger exact space contract.
