# Runtime operations

Select one versioned [profile](../../deploy/profiles/delivery.v1.json), preserve
artifact/Composer/uv lock digests and record actual PHP, PostgreSQL, worker BOM
and host configuration fingerprints. Select required capabilities explicitly.
Unknown adapter IDs fail `incompatible/unknown_adapter`; missing required runtime
is unavailable and cannot be downgraded to a successful profile. Readiness checks
do not invoke a model or download anything. Liveness means process alive; readiness
also requires current schema, completed tombstone replay and required adapters.
An optional outage reports degraded capabilities only under explicit policy.

Before opening traffic, apply migrations as described in installation, verify
`schema_migrations` IDs/checksums against the artifact, test a tenant-bound TXT
search/citation and a denied-source read. Run `php examples/plain-php/run.php`
only against a disposable `*_test` database: it creates/drops a random schema
and a restricted role. It never resets the application schema. Full delivery
qualification uses the independent walkthrough, not a success health response.

Migration upgrades require a verified backup, maintenance barrier and stopped
writers. Run the additive complete set with the dedicated migration role. SQL
DDL is transactional where PostgreSQL supports it; checksum or statement failure
rolls back this invocation. An application rollback is permitted only when its
reader/writer contracts support the already applied schema. There is no generic
destructive down migration: otherwise restore the previous artifact and isolated
backup, replay the authoritative journal, reconcile and pass canaries before
cutover. Never remove tombstones or rewrite historical citations to undo an upgrade.

Inject credentials at process launch from secret storage; keep environment files
outside version control with owner-only permissions. Pass credential handles in
profiles, resolve environment values in host adapters and rotate by restarting
affected hosts. Worker children receive scoped staged inputs/output only: no DB,
home, provider secret, arbitrary host path or HTTP metadata authority. Logs must
not contain environment dumps, document text, raw provider requests or responses.

For full documents, follow [the runtime build](../../deploy/worker/README.md) and
the qualified [PDF](../../python/knowkit-worker/PDF.md) and
[OCR](../../python/knowkit-worker/OCR.md) build recipes. Freeze `uv.lock`,
component revisions, schemas, OCR language assets and SHA-256 BOM. Verify code,
model and corpus rights separately. Required Linux capabilities include bwrap
namespaces, seccomp, delegated cgroup v2 cpu/memory/pids and writable private
staging. Set host-owned `KNOWKIT_OCR_RUNTIME` and `KNOWKIT_TEST_CGROUP_PARENT` for
qualification. Missing sandbox capability fails; an unsandboxed process is never
a fallback. A successful parser exit still requires PHP schema/digest/identity,
coverage, fence/epoch and current authorization checks before publication.

The host explicitly schedules these durable application loops; package discovery
starts none of them:

| Loop | Host wiring and recovery |
| --- | --- |
| Jobs | `PdoJobQueue`, `WorkerParserClient`, explicit stage graph and qualified supervisor. Lease, heartbeat, checkpoint and conditional complete; kill/join the whole child cgroup on timeout/cancel. An expired lease alone does not prove quiescence. Preserve uncertain provider effects. |
| Outbox | `Application\Events\Dispatcher`, `Adapter\Postgres\Outbox\PdoDelivery`, registered consumers. Publication and event append commit together; retry pending rows with consumer idempotency. Monitor oldest age, checkpoint and attempts; downstream delivery is not globally exactly once. |
| Purge | `Application\Deletion\DeletionWorker`, tenant-bound store and exact-version deletion transport. Enumerate obligations, preserve each attempt and provider receipt; unknown/unsupported never means done. |

Stop intake, drain leases, cancel/kill/join outstanding workers and then stop
consumers for maintenance. Restart from durable checkpoints with the same
processing identity; a changed parser/OCR language creates a new document revision.
Never edit lease fences, lifecycle epochs or deletion receipts to force progress.
Investigate stale-result counts, outbox age, orphan objects, denials, quota rejects
and unknown purge states using redacted IDs/digests. JSON logs include timestamp,
level/event/correlation, hashed tenant ref, job/attempt/stage/fence, usage/error,
duration and counts. See [observability](observability.md).

Local revoke is immediate at serialization commit. An external owner can change
rights after its last bounded receipt without updating a local epoch. That
distributed race window remains until the owner contract synchronizes/revokes
receipts or the host receives a tombstone; there is no global atomicity promise.
Fresh requests consult the owner again. The race fixture proves both this window
and denial when a local revoke commits during canonical loading.

Defaults are operational targets: first healthy purge attempt within 15 minutes,
exponential retries from 1 minute to 1 hour, at most 10 automatic attempts then
operator action; payload backups 30 days, minimal immutable audit 365 days,
explicit legal hold indefinite. The host must configure/enforce scheduling and
retention. These are not legal or universal provider guarantees. Reference capacity
is not yet qualified; use measured limits in [capacity](capacity.md).
