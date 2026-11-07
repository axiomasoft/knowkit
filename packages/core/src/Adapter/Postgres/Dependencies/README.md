# Dependency persistence

Wire `PdoDependencyStore` with a bound tenant, the caller's `Connection` and
`PdoCatalogTransaction`, and an `IdGenerator`. No constructor installs schema.
It uses the existing core metadata tables and has no optional extension dependency.

All methods require the caller's tenant guard. Writes require tenant UPDATE,
before any sorted KB/source/generation/job locks. This conservative barrier
serializes graph registration with unseen-source tombstones and membership
changes. Callers use READ COMMITTED and retain the ordered guards until commit.
There is no policy, provider or blob I/O here. A lifecycle result is not an access
grant: disclosure must also perform current host authorization and invoke the
synchronous ancestor gate immediately before issuing bytes, under those guards.
`Application\Dependencies\RequireUsable` preserves denied and unknown results;
database lock failures remain unavailable.

Nodes bind exact accepted catalog metadata through `NodeMetadata`. Supported
source-bound kinds are source, source_version, document_revision, chunk, and
resource (including derived asset/summary payload resources). Other kinds fail
incompatible; multi-source derivation is expressed with multiple required parents.
The node identity is the typed DependencyNodeIdentity hash of that metadata;
source-version/revision/chunk identities match their existing exact DTOs. Resource
identity contains tenantId, sourceId, resourceId and contentDigest. Parent order
is irrelevant, while parent kind/id/tenant and the complete set are immutable.
Repeated registration validates the old set before inserting anything. SQL's
existing cycle trigger is an additional guard. Ancestor reads recompute parent
digests and consult current source/version state, tombstones and lifecycle events;
they do not depend on completed propagation or index cleanup.

The authorized lifecycle writer owns expected-epoch CAS, replacement readiness,
source/version transitions and snapshot replacement. It calls `invalidate` in
the same transaction with its validated LifecycleEvent. The dependency adapter
retains the complete event in outbox, appends lifecycle_events for existing
sources, records source-wide tombstones for revoke/delete, and propagates node
and usage markers. Correction seeds only the exact version. Retention hold alone
does not invalidate or restore access. For an unseen source, tombstone plus outbox
are durable before any node exists; no fictitious source row is inserted to
satisfy the lifecycle_events source FK. Event replay preserves the first effect;
changed content under the same event ID conflicts.

The disclosure writer inserts its authoritative `evidence_usages` record first.
`recordUsage` appends only lineage into `usage_refs`, matching the prepared
invocation's actor, purpose, host binding, policy digest and time. Exact ChunkRefs
and fragment digests are grouped by node in `exact_version_ref.fragmentRefs`;
no text or payload is duplicated. Historical replay may confirm identical refs
after invalidation, and cannot authorize another invocation. A fresh usage must
pass the live ancestor gate. Propagation appends `usage_invalidations` without
rewriting historical hashes or lineage, even when payload_resource_id is absent.

Source adaptation: AgenTools PostgresGraph at
2054acda51eb69eca1579c78900858c5ff3de01e (immutable-set comparison, recursive
ancestor/descendant traversal and historical replay). Private local authority
and original notices are in licenses/local-reuse-authorization.md and
licenses/notices/agentools-LICENSE.txt. The P3.1 receipt records exact donor hashes,
rejected deletion/recovery components and regression adaptations.
