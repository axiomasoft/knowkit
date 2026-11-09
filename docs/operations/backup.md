# Backup and isolated restore

Back up authoritative metadata, immutable source/IR/assets and their complete
manifest together. Search/cache projections are rebuildable; their text is never
citation authority. Preserve immutable source/version/document/IR/chunkset/index
IDs, digests and byte spans. A source upload does not establish redistribution or
external processing rights. Protect backup payload separately from minimal audit.

1. Block traffic and all metadata/blob writers, including external writers; stop
   or quiesce workers and outbox dispatchers. Record job, outbox and lifecycle high
   water marks. Export the authoritative tombstone journal independently and keep
   extending it after backup. Lease timeout is not writer quiescence.
2. Use `pg_dump --format=custom --no-owner --no-acl` with host secret injection
   (`PGSERVICE`/owner-only passfile) and the matching PostgreSQL client version.
   Keep database/schema selection explicit. Snapshot/copy immutable objects while
   the barrier holds. Record every key, object version, byte length and digest;
   verify copied bytes before releasing the barrier. Encrypt and access-control
   the backup in host infrastructure. No payload or credentials in logs.
3. Capture a trusted RecoveryPlan header before faults: tenant, backup sequence
   and journal chain digest, ordered object/ledger/manifest/immutable-outbox counts
   and `RecoveryDigest` chains. Sign or protect it in the backup authority. Keep
   artifact/schema/lock/runtime BOM identities with it. See the exact chain and
   journal contract in [restore](restore.md). Hashing an already incomplete restore
   cannot supply the expected header.
4. Restore with `pg_restore --no-owner --no-acl --exit-on-error` into a new empty,
   isolated database/schema with restricted roles. Keep production routing and
   external consumers disconnected. Restore object versions byte-for-byte and set
   `serving_state=blocked` before any search/disclosure host can connect.
5. Obtain the complete authoritative post-backup journal tail/cutoff/digest.
   Compose `PdoReconciliationStore`, local `RecoveryEvidence`, verified canonical
   loader/`ProjectionRebuilder`, exact external verifiers where required and a real
   denied-source payload/history canary. Start `Reconcile` using the trusted
   `RecoveryPlan`; schedule bounded `step` calls and retain the same run ID on retry.
6. Replay tombstones first, then verify object and ledger inventories, manifests,
   rebuild projections, verify immutable outbox and run the denial canary. Missing
   objects, orphan obligations, digest mismatch, incomplete journal or unknown
   required external storage keep readiness closed. Preserve all findings; repair
   through the owning workflow and use a fresh recovery run when evidence changes.
7. Open traffic only after the atomic ready receipt and successful denial canary.
   Reconnect consumers using their durable checkpoints/idempotency. Compare exact
   historical citations on retained permitted samples. Keep the old backup until
   restore verification and applicable retention/hold obligations are satisfied.

Restore older than the available authoritative tombstone journal cannot become
ready. Restore after delete retains payload only under retention/hold, while
ordinary read remains denied. Never substitute latest content for missing old
bytes. The delivery fixture restores pre-delete state and replays a deletion;
its negative schedule removes the required journal tail and asserts a closed gate.
It is a recovery protocol drill, not a claim that a particular host's pg_dump,
encryption, storage snapshot or disaster recovery infrastructure was qualified.
