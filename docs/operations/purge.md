# Revoke, deletion and retention

Revoke first using current trusted context, idempotency key and expected lifecycle
epoch. Logical denial takes effect at the local commit independently of cleanup.
Cached selections, old citations, preview/table/asset/history requests still need
current lifecycle and access checks. Legal hold retains bytes without read rights.

Request deletion through `LifecycleCommands::requestPurge` (CLI `lifecycle delete`),
then inspect `purge status` and the tenant-bound deletion ledger. Distinguish:

| State | Required evidence |
| --- | --- |
| Logical revoke | Serialized epoch/tombstone; new disclosure denied |
| Projection removal | Exact generation/namespace cleanup and manifest reconciliation |
| Physical purge | Every owned required object/version has a verified terminal receipt |
| Backup expiry | Separate backup-retention/hold authority confirms expiry |

Host schedules `DeletionWorker::attempt` with fresh attempt IDs and exact-version
transport. Preserve task/resource/attempt identity, result, observed time, known
expiry and provider receipt. A timeout after a provider effect is unknown; retry
needs fresh verification. A replayed old attempt is history, not fresh I/O. One
unknown or unsupported required resource prevents aggregate done, even when other
objects were deleted. Removing adapter configuration never erases its obligations.

Enumerate managed originals, canonical IR/text/assets, staged artifacts, index
generations and derived dependencies. Externally owned originals are detached;
request deletion only with explicit owner-authorized capability. A contract double
that returns deleted does not qualify an external provider. Unsupported provider
retention remains unsupported/unknown until an authoritative owner resolves it.
Never label that physical erasure or silently clear ledger rows.

Default healthy scheduling is first attempt within 15 minutes, exponential retry
1 minute..1 hour and 10 automatic attempts before explicit operator action. Alerts
must retain unknown outcomes. Record legal holds and payload retention separately
from the 365-day minimal audit and 30-day backup defaults; hosts enforce these
policies and applicable obligations. Preserve journal authority across backup expiry.
Use isolated [backup/restore](backup.md) and reconcile orphans before readiness.
Local completion cannot promise erasure from unsynchronized owners, consumers,
provider backups or already disclosed copies.
