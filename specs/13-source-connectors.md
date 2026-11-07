# Optional source connectors

Core retains the existing `SourceConnector` port in the vendor-free core package.
`describe(): ConnectorCapabilities` is a capability claim. It neither installs nor
qualifies a production adapter. `inventory(Scope, ConnectorRequest): CrawlSnapshot`
is the explicit host-authorized inventory operation. Business purpose remains part
of trusted Scope; it never substitutes for an Operation authorization check.

A host adapter resolves a selector to one immutable revision **before** tree,
pagination, compare and body reads. Every item and download must name that same pin.
A force push cannot redirect a read to a new branch head. Unsupported immutable
resolution fails; there is no latest substitution, automatic retry, repair,
provider fallback, model call or paid readiness probe.

## Verification and completeness

`VerifiedDownload::verify` compares the full SHA-256 and exact decimal byte length
of original bytes and enforces the configured byte limit. Oversized bodies fail;
the SDK never slices them into acceptable prefixes. Receipts contain metadata,
not source plaintext. Logical paths and URLs are metadata, never filesystem sinks
or stable source identity.

`InventoryReceipt::seal` checks typed download receipts for every verified item,
exact pin equality and uniqueness. A complete snapshot additionally requires
proven full tree/compare enumeration, complete pagination, no crawl cap, zero
omissions (explicit `0`, not unknown), zero failures and verified items only.
Partial/error/timeout/robots/capped adapters set `complete=false` and supply safe
machine failure codes. Unknown inventory is never evidence of absence.

The receipt binds the complete trusted Scope, including `hostScopeHash`, connector,
source namespace, exact revision, item tuples, completeness, failures, omission
count, observation instant and explicit rename tuples. Inventory digest uses the
existing `resourceInventory` domain: SHA256(`knowkit:resource-inventory:v1` + one
NUL byte + JCS(payload)); the self digest and receipt are excluded. Payload kind is
`source-inventory`, schema version `source-inventory-1.0`. Item tuples sort by full
origin key, rename tuples by full canonical tuple. No prefix hashes are accepted.
Receipt validation detects changed metadata/scope; it is a local contract proof,
not a cryptographic signature or independent proof of a remote server's honesty.
Legacy metadata-only CrawlSnapshots remain constructible for port compatibility
but cannot enter reconciliation without this proof.

## Reconciliation and host integration

`InventoryReconciler::reconcile(Scope, PreviousInventory, CrawlSnapshot,
MutationContext): ReconciliationOutcome` validates receipt and partition binding
before effects. A partition is `(tenantId, kbId, connectorId, sourceNamespace)`;
origin keys identify members inside it. Existing host-allocated SourceIds remain
the catalog identities: the host maps these tuples to SourceIds, without replacing
the normative tenant/SourceId identity hash. Different repositories with identical
logical paths cannot collide.

Present verified items replace only their exact origin entries. Failed or absent
old entries remain exact old revisions under partial inventory, with preservation
keys and machine reasons in the outcome. A complete authoritative inventory alone
can remove absent members. Complete empty is a valid replacement partition; when
no other partition has members, publication activates an empty snapshot. It must
never select the old index as fallback. Partial availability in namespace A
cannot remove unavailable namespace B.

Path changes of a stable origin require `Rename(oldOriginKey, newOriginKey,
oldPath, newPath)`. Origin changes may also be explicitly mapped; endpoints must
match old and newly verified members, without ambiguous duplicate mappings.
Matching bytes or filenames never implicitly identify a rename. Same-namespace
path collisions fail rather than overwrite a preserved old member.

`InventoryStore` is the host binding to the existing lifecycle and sealed/CAS
publication paths, not another catalog or scheduler. `synchronize` must hold the
authoritative guards, validate current access/epochs, previous inventory CAS and
idempotent request identity, including on replay. The reconciler calls `deny` for
all confirmed removals **before** `publish`. Deny/delete intent blocks current
disclosure synchronously and survives rebuild/projection failure. Historical
snapshots are never access grants. `publish` accepts exact versions idempotently,
merges other namespaces, rechecks access/epochs and uses the existing sealed
snapshot/CAS/outbox transaction; failed activation cannot restore denied access.
Retention hold and physical purge remain governed by the existing lifecycle.

`RecordedInventoryStore` demonstrates this coordination in one process with
injected authorization and publication failure hooks. It preserves deny markers
on failed projection, checks CAS/replay, and filters denied metadata from old
snapshots. It is an SDK double, with no durable, concurrent, catalog, or PostgreSQL
qualification claim. Durable host bindings are explicitly required for deployment.

## SDK corpus and qualification boundary

`RecordedConnector`, `RecordedDownload`, `BrokenConnector` and
`ConnectorQualifier` live in core's optional adapter SDK. The example factory is
`examples/custom-adapter/recorded-source-connector.php`. The qualifier checks
capability/schema claims and the receipt using one explicit inventory call.
Broken doubles exercise unsupported completeness, full-body integrity and pin
failures; they never become installed external connector qualification.

Versioned recordings are in `tests/fixtures/sources/v1/cases.json`, required IDs
and actual PHPUnit identities in `tests/fixtures/check-groups/sources-inventory.json`.
The `sources:inventory` QA runner reports every required ID, including DT-I07,
I08, I09, I10, I11, I12 and I14; missing, skipped, failed or zero execution fails.
The corpus covers contract/reconciler logic, without claiming network, SSRF,
filesystem extraction, GitHub API or production web security qualification.
Production GitHub/web and other external adapters remain optional host work;
advanced graph, timed media and pedagogy are deferred host extensions.
