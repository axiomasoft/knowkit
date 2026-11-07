# Durable jobs

P2.7 Q1=a authorizes `003_jobs.sql` and this internal protocol. The public
`JobQueue`, `Checkpoint`, `Lease` and `JobResult` signatures stay unchanged.
Use a dedicated connection, the same `PdoCatalogTransaction`, a trusted Scope
partition, an injected IdGenerator, a registered type-to-stage-plan map and
`Application\Jobs\VerifiedCheckpoints::load` as the verification callback.
Construction does not install tables or start workers. Host admission and cancel
authorization precede queue calls; this adapter does not grant disclosure rights.

A plan contains 1..16 unique stage codes, in execution order. Enqueue pins the
whole plan, complete ProcessingIdentity, Scope and exact source/generation.
The first stage consumes the source digest. A verified successful checkpoint
advances to the next stage, whose input is the preceding output inventory digest.
The last checkpoint makes completion eligible; extraction partial coverage stays
separate from job success and publication readiness. Unknown/unavailable outcomes
retry; denied/incompatible outcomes fail. Success/empty complete only after all
stages. Five attempts, 5/10/20/40/80-second backoff, a 60-second lease and a
900-second attempt deadline use the database clock. Hosts heartbeat every 15s.
An expired lease is scheduled from its previous expiry plus backoff, so a worker
can recover a sufficiently old attempt immediately. Exhausted attempts fail.

Candidate planning reads at most 32 tenant/KB/type/host-scope-bound rows. Each
candidate uses READ COMMITTED, 500ms lock timeout and 2s statement/wall limits:
tenant advisory/row guard, KB, source, optional generation, then job
`FOR UPDATE SKIP LOCKED`. Other mutations use the same order and blocking job
locks. No missing earlier guard is acquired after a job lock. Lock timeout is
`unavailable/RETRYABLE_LOCK_TIMEOUT`, never empty work. Fence exhaustion is
`unavailable/decimal_exhausted`. Only token hashes persist. Every conditional
write checks exact attempt, source epoch, fence, token hash, state, cancellation,
DB-clock expiry and deadline. Scope and immutable source identity are checked too.

`JobCheckpointManifest` is closed `job-checkpoint-1.0` metadata with tenantId,
kbId, sourceId, sourceVersionId, jobId, sourceEpoch, processingDigest, stage,
inputDigest, outputDigest, uncertainExternalEffect and resources. Resources are
an ordered unique list of `{resourceId,digest,byteLength}`. outputDigest is the
existing typed ResourceInventory digest over that list. The checkpoint digest
is SHA256(`knowkit:job-checkpoint:v1` + NUL + JCS(manifest)); it contains no fence,
paths, source text, tokens or credentials. Manifest bytes are bounded to 64KiB,
resources to 128 and independently hashed resource bytes to 256MiB total.
Successful checkpoints require resources; an uncertain observation may have none.
Verification reads immutable storage before BEGIN. Inside SQL, the adapter checks
the same manifest binding, live capability and exact accepted/uploaded ledger
ownership/digests/lengths, with closed writers for reusable output.

Uncertain observations are immutable checkpoint rows but never advance progress
or replace the reusable checkpoint. The job's uncertainty flag only grows. Retry
must call `resume` with the complete ProcessingIdentity: it reopens and hashes the
last successful checkpoint/resources outside SQL and rechecks the capability and
ledger inside SQL. A per-fence verification marker prevents skipping this step,
even after all stages completed before a crash. No volatile lease cache is needed.
Expired/cancelled/unknown attempts mark active resource writers uncertain. Lease
expiry and cancel do not prove remote quiescence or permit garbage collection.
Provider timeouts may execute remotely more than once.

`accept` offers a trusted SQL-only hook for local effects under tenant/KB/source
update guards, generation and job locks. The hook may use preloaded validated data,
pointer CAS and the existing PdoOutbox on the same connection. It must not take
additional semantic locks or call policy, storage, providers or processes. Failure
rolls back acceptance and all effects. An identical committed success replays its
receipt under the same identity/fence/token and current source guard, including
after its old deadline; it performs no new acceptance or hook. Different results,
new fences, cancellation and unusable sources cannot replay it. Publication
readiness/authorization and outbox delivery retain their separate owners.
An unconfirmed SQL commit is `unknown/job_commit_unknown`; callers recover by
replaying the same immutable request/capability, rather than claiming rollback.

`CancelJob` commits cancellation/fence invalidation before invoking the trusted
host process-group stop callback with a five-second grace. A failed stop can be
retried using the idempotent cancelled state. The subprocess supervisor and Python
worker remain the separate v0.4 integration, not an implemented provider adapter.

Tests use real PostgreSQL processes, SIGKILL, ledger metadata, pointer CAS and
PdoOutbox. R11 proves the SQL composition boundary with synthetic generation
metadata; it does not claim a production Publishing implementation or dispatcher.
External acceptance faults are explicitly contract doubles. Reuse qualification
is in `tests/jobs/leases/evidence/P2.7-reuse.json`; the coordinator registers the
plan artifact. Executors do not mutate native plan state.
