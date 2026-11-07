# Physical deletion

The host uses the existing authorized lifecycle `requestPurge` command first. It commits
the identity tombstone and frozen, typed-digest inventory before a deletion worker can
reserve an attempt. Configuration removal never deletes ledger rows or inventory members.

`PdoDeletionStore` is a trusted worker port bound to a tenant and a shared transaction.
`DeletionWorker` begins an attempt under tenant/source guards, calls its transport outside
SQL, and persists one immutable receipt. Replaying an attempt performs no transport I/O.
An unresolved in-flight replay returns unknown while the durable attempt remains in flight.
After a trusted runner proves that process stopped, `interruptAttempt` records unknown;
a new attempt must explicitly verify or delete the same object version. Safe error codes
contain no exception text. Raw provider requests must never be used as receipts or logged.

An adapter must explicitly assert `provesExactVersion` for the supplied ledger target.
Deleted/absent without this assertion and an opaque provider receipt becomes unknown.
`BlobDeletionTransport` bridges a qualified BlobStore; the host explicitly supplies its
storage identity and proof capability. The tests qualify the existing local BlobStore;
external provider behavior uses contract doubles and does not qualify external adapters.

All owned original, IR, canonical slice, asset, embedding, provider and backup resources
belong in the ledger before PUT, using P2.2 writer reservations. Explicit `resource_class`
supports optional provider/embedding/backup stores; relational metadata classifies core
blobs. `retention_until` is an immutable backup obligation, not evidence of byte deletion.
Backup completion requires a distinct exact receipt with confirmed expiry. External linked
bytes retain their `external` ownership and are excluded from owned erasure claims.

Aggregation verifies the original digest/membership and current writers, jobs, holds,
late resources and all outstanding deletion attempts. Empty inventory stays pending.
New unknown/unsupported receipts block done even if an older attempt succeeded. Resource
and citation identity rows remain as audit history when physical bytes are deleted.

P3.7 owns orphan discovery, trustworthy writer quiescence and restore replay. Quarantine
already admits late objects as held unknown obligations and immediately invalidates done.
It never rewrites frozen inventory: reconciliation needs a new lifecycle purge snapshot
including the discovered obligations. Existing unknown/in-flight receipts remain immutable.
Cancelled jobs with uncertain external effects continue to block aggregation; the current
job schema does not permit clearing that uncertainty without a separately proven recovery.
