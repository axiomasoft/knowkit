# Isolated restore and orphan reconciliation

Run migrations explicitly, including `007_reconciliation.sql`. Restoring a database or
constructing an adapter starts no loop. Keep restored traffic and all writers isolated.
The tenant's `serving_state` must be blocked before connecting a restored catalog to
search or disclosure. `Reconcile::start()` commits that barrier before recovery I/O.
Readiness is separate from current access permission; a ready tenant still cannot read
a tombstoned source or an unusable historical version. A legal hold retains payload;
minimal audit facts and ordinary payload/history access follow separate policies.

The host composes `Application\Reconciliation\Reconcile` with
`Adapter\Postgres\Reconciliation\PdoReconciliationStore` and `Port\RecoveryEvidence`.
`Adapter\LocalBlob\RecoveryEvidence` implements local object listing and exact digest/
length checks using the production private filesystem protections. Supply its mandatory
authoritative journal reader, projection rebuilder and denied-source canary callbacks.
These administrative readers must not be exposed as ordinary download endpoints.
External stores require an exact-version verifier; absent configuration returns unknown.
No credentials, payload text or raw provider responses belong in diagnostics.

Capture a trusted, immutable backup header containing the tenant, backup recovery
sequence and journal chain digest, plus complete ordered object, ledger, generation
manifest and immutable outbox inventories. Use the exact rows returned by the evidence
and catalog scans; outbox IDs/aggregate versions bind its backup high-water mark.
Each inventory records a count and `Domain\RecoveryDigest` chain: seed is
SHA-256 of `knowkit:recovery:v1`; each next digest is SHA-256 of previous digest,
a newline and the JCS canonical entry. Do not derive the expected header from an
already incomplete restored tree. Restore the authoritative journal tail independently
and obtain its complete cutoff/digest from its authority, including events after the
backup. A backup older than the available journal tail cannot be qualified.

Construct a `Domain\RecoveryPlan` with that header and a fresh run ID; retain the
same header/run ID on retry. Call `start($plan)`, then explicitly schedule
`step($plan, $limit)` with a limit from 1 to 100. Each call makes one page or stage
transition. Stop at `ready` or `blocked`; exceptions preserve the last committed
checkpoint and the closed serving barrier. Do not treat process liveness as readiness.
Use a fresh run ID only after resolving evidence/findings, preserving earlier history.

The ordered stages are journal replay, object listing, ledger verification, manifest
verification/projection reconstruction, immutable outbox verification and the denied
source canary. Journal entries are contiguous canonical decimal sequences. Each binds
tenant/source identity, lifecycle and source policy epochs, state, hold, an optional
identity tombstone and explicit version invalidations. Version invalidations contain
sourceVersionId, usability, eventId and correctedByVersionId. A source can appear in
several entries to keep version lists bounded at 256. The wire example and its fixed
digest are in `tests/lifecycle/restore/fixtures/v1/cases.json`.

Replay persists tombstones even before source metadata exists; source and version
high-water marks prevent late stale metadata from becoming usable. Recovery keeps
exact invalidation event/replacement IDs in `recovery_version_marks` even
when their post-backup metadata is absent. Existing version rows become unusable
immediately; their FK-backed event/replacement projections remain null only for
missing targets. Lifecycle reads use the authoritative marks, preserving the exact
identities without manufacturing events or replacement versions. Available targets
remain bound to the same tenant/source and cannot be cleared from the projection.
The database freezes catalog/resource/job mutations between pages. The host must also stop external
writers: lease expiry is not proof of writer quiescence. Active/uncertain reservations
remain unknown obligations. Reconciliation never kills a writer, deletes an orphan,
changes a deletion receipt, or infers successful purge from absent configuration.

Local listing includes staging and immutable objects. Its compact profile caps traversal
at 10,000 entries and depth 5, reads directories incrementally, then keyset-pages the
bounded inventory. It may revisit that bounded tree between pages. A cap overflow,
unsafe path or unavailable required backend fails closed. Larger stores must supply a
stable, bounded provider inventory adapter; never truncate a listing into success.
Objects without metadata become separately identified unknown obligations; when the
source exists they also enter the held resource ledger and invalidate completed purge
aggregation. Such newly registered rows are tracked separately from the run's backup
ledger scan so quarantine does not change the baseline being verified. Inventory
count/digest discrepancies become durable findings and the remaining scans continue;
they never qualify the mismatched inventory. Resource/generation findings retain their
actual IDs; an inventory-level finding binds the run ID. Missing objects, uncertain external receipts and incomplete inventory
counts/digests keep readiness closed. Diagnose `reconciliation_findings` by tenant/run;
repair via the owning lifecycle/resource workflow and start another qualified run.

For the baseline lexical profile use `Adapter\Postgres\Reconciliation\ProjectionRebuilder`.
Its host loader supplies verified canonical chunksets outside SQL. It checks the
immutable manifest digest, member identities, canonical resources and complete FTS
readback. Only derived lexical rows can change in its gated transaction; memberships,
manifests, snapshots and historical citation coordinates stay sealed. Optional vector
profiles need their own required recovery adapter; the lexical rebuilder rejects them.
A crash after reconstruction but before checkpoint safely repeats reconstruction.
An explicitly failed build without a manifest has no projection to rebuild, provided
no active snapshot requires it. An unfinished build without a manifest stays blocked.

The mandatory canary must exercise the host's real denied-source payload/history
boundary and return true only on the expected denial. It runs after replay and
projection verification, before the ready receipt and tenant gate commit atomically.
Never replace it with an unconditional callback in a serving deployment. Backup
retention/expiry and legal-hold obligations remain owned by the deletion ledger.

Versioned regression assertions cover backup resurrection, tombstone-before-source,
SIGKILL before checkpoint, object/metadata asymmetry, unknown external receipts,
source/version high-water marks, incomplete backup/journal, tenant/CAS/freeze guards,
required backend outage, manifest tampering and actual projection reconstruction.
The coordinator runs `composer qa -- lifecycle:restore`; the required-case manifest
rejects missing or skipped cases and binds fixture digests and provenance.
