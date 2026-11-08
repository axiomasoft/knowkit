# Concurrency, crash recovery and restore

The P9.3 corpus is `tests/ops/resilience/fixtures/v1/cases.json`, version
1.1.0. Its check-group manifest explicitly requires 19 case IDs and binds them
to PHPUnit methods. Expectations are authored lifecycle outcomes and immutable
content/journal digests. A changed expectation requires a version and rationale;
the fixtures are not response snapshots.

The coordinator's acceptance entry is `composer qa -- ops:resilience`. For local
diagnosis, run one changed file, for example:

```sh
vendor/bin/phpunit --configuration phpunit.xml tests/ops/resilience/JobsTest.php
```

Use the disposable PostgreSQL 16/17 test database described in `development.md`.
Missing PostgreSQL, a missing test, a skipped/failed required case, an unknown
group, and an empty execution fail the check. The backup/restore cases also
require `pg_dump` and `pg_restore`. `TEST_PG_BIN` may select their common binary
directory. Otherwise the harness uses `pg_config --bindir`, selecting the
server's installed major sibling where present. It invokes concrete binaries
to avoid a multi-version wrapper silently selecting a newer client. Application
code has no new runtime dependency.

| Required case | Deterministic schedule | Assertions after reconnect |
| --- | --- | --- |
| PUBLISH-REVOKE | Both orders; first application transaction paused before commit, second session observed waiting | Revoke leaves one authoritative tombstone/epoch; a late publication is rejected; exact historical membership stays fixed |
| GRAPH-REGISTER-INVALIDATE | Both orders on the actual dependency/resource graph | An existing child becomes unusable; registration after invalidation creates no child or edge |
| UNSEEN-ROOT-TOMBSTONE | Both orders under the tenant barrier | Late registration cannot resurrect the identity; a stale owner binding may require a fresh revoke attempt; tombstone applies to future versions |
| LEASE-TAKEOVER-COMPLETE | Old worker parked while a new process takes over an explicitly expired test lease; reverse order completes first | Stale heartbeat/checkpoint/resume/complete fail; completed job stays terminal; checkpoint/fence remain exact |
| PURGE-LATE-RESOURCE | Real blob fsynced before acceptance; purge holds its precommit guard while writer waits; reverse order accepts first | Purge inventory retains the exact resource; late acceptance is denied and writer remains uncertain; accepted resources remain pending deletion |
| CACHE-HIT-REVOKE | Independent revoker commits during a real stale cache hit; disclosure also occurs before revoke | New disclosure returns denied without object reads or a new usage; historical evidence/citations stay unchanged and replay contains references only |
| RESTORE-READINESS | Actual custom-format metadata dump and immutable blob copy restored twice; replay runner killed after its first durable journal checkpoint | Readiness remains blocked until replay/inventory/canary proof; one ready receipt; revoked source remains denied; bytes and journal digest remain exact |
| KILL-BEFORE-COMMIT / KILL-AFTER-COMMIT | SIGKILL at precommit or committed/lost-ack barrier | No partial lifecycle/outbox/idempotency effect; retry yields one epoch, tombstone, event and receipt; historical citation identities are unchanged |
| OBJECT-UNCERTAINTY | SIGKILL after real canonical-object fsync and before ledger acceptance | Object is unreadable until stopped-runner and digest proof; false quiescence proof remains unknown |
| PROVIDER-BEFORE-EFFECT / PROVIDER-AFTER-EFFECT | SIGKILL around a durable exact-version transport-failure double | Same attempt replays unknown with zero I/O; explicit fresh attempt verifies; unresolved flight prevents done until stopped-runner proof |
| DUPLICATE-OUTBOX | Two consumer processes observed waiting on the same consumer guard | One applied, one duplicate, one inbox/sequence effect; conflicting payload blocks consumer; foreign tenant is denied; source stays revoked |
| TWO-INGEST / TWO-PUBLICATIONS | Concurrent duplicate enqueue/lease and competing sealed snapshot CAS | One durable job/receipt/lease; only one publication wins expected pointer version |
| SHARED-WRITER-RESTORE-GUARD | Both workers hold tenant SHARE and distinct job UPDATE guards; A must commit while B retains SHARE | Both writes commit without a late tenant upgrade; a separate restore case rejects ordinary writes while gated |
| RESTORE-FAIL-CLOSED | Missing journal, then failed denied-source canary across new processes | Unknown stays distinct; no ready receipt until authoritative proof becomes available |
| FIXTURE-INTEGRITY / RUNNER-NEGATIVES | Version/digest/provenance and executable mapping checks; malformed executions | Missing, skipped, failed, duplicate, empty and unknown checks are rejected |

The process controller uses stdin/stdout barriers with bounded reads. PostgreSQL
`pg_blocking_pids` confirms actual wait dependencies, including chains of waiters.
No sleep establishes a schedule. Every child uses a distinct interpreter/backend;
lease capabilities and connection configuration travel only over trusted test
stdin. SIGKILL is confirmed with terminal process status and signal 9.

The two-ingest regression exposed a late tenant lock upgrade in
`kk_recovery_freeze`. Additive migration `011_recovery_shared_guard.sql` changes
that trigger's tenant read to SHARE. The existing restore UPDATE barrier remains
exclusive. Earlier migration checksums are preserved. A deterministic negative
schedule retains another shared guard until the first write completes; the old
trigger returns `unavailable/RETRYABLE_LOCK_TIMEOUT`, while the new trigger commits.

Test runs append transaction IDs, backend/process IDs, observed waits and asserted
outcomes to `.phpunit.cache/P9.3/transactions.jsonl`. This is untracked runtime
evidence. Only `tests/ops/resilience/evidence/P9.3-reuse.json` is tracked in the
item's evidence directory; it records seven pinned, SHA-256-verified donor decisions.
The coordinator retains its own acceptance result for the final candidate.

The reference execution uses real PostgreSQL and local fsync/object I/O. The
provider crash double qualifies interruption semantics, not a remote provider's
erasure guarantee. Process SIGKILL does not qualify host power loss or storage
controller behavior. This corpus does not claim performance or PostgreSQL 17
qualification unless those profiles are actually executed.
