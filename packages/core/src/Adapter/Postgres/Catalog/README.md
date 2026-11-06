# Catalog persistence

Use a dedicated PostgreSQL PDO connection and a pre-created schema. Runtime
`Connection` rejects superuser roles. Apply the baseline explicitly:

```sh
php packages/core/src/Adapter/Postgres/Catalog/migrate.php knowkit
```

Supply `KNOWKIT_PG_DSN`, `KNOWKIT_PG_USER` and `KNOWKIT_PG_PASSWORD` through the
environment. The CLI takes only the schema name. `Migrate` owns its transaction,
advisory migration lock and checksum ledger. Adapter construction never installs
anything. This core migration needs no extension or vector backend.

Wire the same `Connection` into `PdoCatalogTransaction`, `PdoCatalogRepository`
and `KnowKit\Adapter\Postgres\PdoOutbox`. Inject a host `AccessPolicy`, local
`Clock`, `IdGenerator`, offline validator and an unpredictable cursor HMAC secret.
`DomainEventFactory` and `MutationAuthorizer` belong to Application. Their
dependencies have no framework/provider runtime. Inject the same authorizer into
the repository and `CatalogCommands`, `SourceCommands` or `RemoveKnowledgeBase`.
Bind `MutationAuthorizer::requireAllow` and `DomainEventFactory::create` as the
repository's explicit local closures. Bind the repository's metadata methods as
the SourceCommands/removal closures. This preserves the approved import DAG:
Adapter imports Domain/Port; Application imports Domain/Port and never Adapter.
These callbacks only check preloaded receipts/assemble events; they must never
perform authorization I/O. The actual owner callback remains in `prepare`.
These workflows call the current owner before SQL, then consume single-use
receipts against current SQL policy epochs under the ordered guards. Direct
low-level insert methods are trusted transactional primitives; they do not grant
read/disclosure authority. No policy/blob/provider/process I/O occurs inside
these transactions. A replay returns original metadata and never grants access.

Initial revisions, lifecycle/policy epochs and pointer versions are zero.
Collection and membership commands compare expected state with the locked KB
revision/policy epoch and advance that KB revision. Source-version acceptance
compares the source's storage revision/lifecycle epoch and advances the revision.
Optional expected fields retain the approved MutationContext semantics. A changed
operation, payload or expected state under the same tenant/key conflicts.
Rollback leaves neither a success receipt nor an outbox event. Source registration,
version acceptance and revision acceptance append their fixed events in the same
caller transaction. Delivery/inbox processing remains with its owning item.

Tenant/collection DTOs have no business display-name field: their SQL technical
labels initially equal their canonical ID. Membership IDs are storage-generated
surrogates; the natural tenant/source/KB/nullable-collection tuple is unique.
All reads and joins name tenant explicitly. Continuations authenticate the entire
tenant/KB/filter/security binding and page limit. Hosts supply the current trusted
security binding, including actor, purpose and hostScopeHash, on each request.

P2.2 must persist a ledger reservation before PUT. Unknown digest and length occur
together with a staging ID. Finalize them once and retain the staging correlation.
An accepted BlobRef must resolve to exactly one accepted ledger row matching its
tenant, source, storage, object version, ownership, digest and length. Catalog
acceptance never invents a resource ID or performs PUT. Storage ownership and
accepted coordinates/finalized digests cannot be rewritten. Staging-to-CAS
coordinates may be finalized before uploaded; staging_id remains unchanged.
Terminal absent reservations
need closed writers and a durable receipt for the exact staging/key/object version.
Unknown writers/resources remain deletion obligations.

Late insert primitives accept explicit adapter metadata where their minimal
public exact refs cannot supply it: `RevisionMetadata` supplies validated IR
resource/schema/coverage/time; `ChunkSetMetadata` supplies the owning chunker's
validated manifest digest and `ChunkMetadata` for every canonical resource,
provenance and structure path. Missing metadata fails explicitly. No alternate
chunk manifest format or fabricated coverage/provenance is introduced. Generation
insertion creates building records with an explicit profile. Seal/readiness and
publication remain with their owning workflows. Snapshot insertion stages the
exact generation list in `snapshot_generations`; unsealed snapshots cannot become
the active pointer. Historical lookup requires an explicit ref and preserves
its digest and complete generation list.

KB removal retains audit identity, detaches memberships and creates frozen owned
resource deletion intents only for sources with no other KB memberships. Externally
owned resources are detached; this implementation grants no external delete
capability. Physical deletion, recovery archival and purge completion remain with
the lifecycle owners. `deletion_task_resources` preserves relational inventory
identity beside frozen JSON. Source-level rights remain authoritative in every KB.

Opaque owner/origin/rights refs are metadata, never filesystem or network targets.
Privileged adapters can use `OriginBoundary`: check every initial/redirect URL
and every DNS answer, pin those addresses for connection, and repeat the checks
on redirects. The helper performs no network read. Typed CollectionFilter prevents
SQL predicate injection. Full disclosure/connector workflows remain with their
owning items; the audit fixtures here prove the current-owner and privileged-input
boundaries without claiming those later workflows are implemented.

The versioned storage fixture manifest maps every required scenario to an actual
PHPUnit case. Tests use disposable `*_test` schemas, restricted runtime roles,
separate worker connections and real SIGKILL/restart. SQL fixtures intentionally
exercise structural metadata constraints; their synthetic blobs/indexes do not
claim publication readiness. Evidence logs redact only the repository root prefix.
