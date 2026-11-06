# PHP domain and port appendix — P1.5

This appendix records the technical completion authorized by D4. Sources are the
approved architecture (ARCH-LAYERS, REGISTRY, VERSION-*, SCOPE, RETRIEVE, EGRESS,
JOBS, OUTBOX), knowledge/contracts#ports and #hash-domains, access, document-ir,
jobs, publication, storage, lifecycle, events, semantic, connectors and api#shapes.
P1.4 schemas remain the authority for existing wire shapes. No schema or
serialized field has been added or renamed.

## Representation and identity

Domain values have no filesystem, framework, provider or worker dependency.
ULIDs are canonical uppercase and each persisted ID has a different PHP class.
SourceDigest, IrDigest and Fingerprint remain distinct. Decimal/Uint64 use bounded
decimal strings; overflow rejects rather than wrapping. D4 reconciles both
ClockInstant and UtcInstant terminology to the single immutable UtcInstant class,
including BlobStore::listUnreferenced. Instants reject offset time and invalid
calendar dates, and compare with microsecond precision.

ExactSourceRef is the four-field reference in common.schema.json.
SourceVersionRef is its PHP boundary subtype with the identical wire shape.
DocumentRevisionRef and ChunkRef compose upstream values internally and flatten
only to their approved wire fields. No implicit latest resolver exists.
ExactIdentityGuard is tenant-bound and checks source-version, revision and chunk
identity reuse. It is an adapter helper, not a replacement for database constraints,
locks, tombstones or authoritative access checks.

ProcessingIdentity hashes exactly the publication contract payload. Re-parser or
re-OCR invalidates revision/chunkset/generation, re-chunk invalidates
chunkset/generation, and re-embed changes generation. Source bytes stay unchanged.
An existing source version with different bytes rejects with immutable_conflict.
A normal new version does not modify earlier refs.

ParserProfile and ChunkerProfile take an explicit ID, engine name/version and
semantic options. Options use their existing distinct hash domains. Credential,
token, machine-path and temporary-URL settings must not be semantic options.
EmbeddingSpace/SpaceRef preserve all eight fields and their complete fingerprint.
EffectiveProfile is a separate strict redacted PHP profile: id/version and
explicit port bindings; each binding contains adapter ID, configuration and
required capabilities. Its digest is untagged SHA256(JCS). Only CredentialRef
handle/presence can occupy a credential configuration member. This is a PHP
composition value, not a new transport schema.

SchemaValue DTOs (IR, QueryGrant, DecisionReceipt, EgressRequest, DisclosureRequest,
Selection, EvidenceSet, Usage, IndexManifest, events, jobs and receipts) receive an
explicit offline ContractValidator. They validate the P1.4 shape and semantic
rules, own canonical bytes, and return fresh JSON values. Domain does not load
schemas from disk. Typed failure/debug representations contain safe codes or
references; SourceBytes, query and embedding text are omitted from debug output.
Callers still must not log document DTO serialization or raw provider inputs.

TextLocator has separate page and segment constructors. Span.pageNo() maps only
to physicalNo, and slice checks text digest and UTF-8 byte boundaries. Bbox uses
integer millionths. Chunk carries documentId and ordered spans around its exact
ChunkRef; canonical text is never obtained from a search snippet.

Tests: IdentityTest, BoundaryTest::testReservationPrecedesUnknownDigestAndRedactsDocumentInputs,
testVectorsAndRerankerCannotChangeSpaceOrExpandCandidates.

## Public contracts

Application/Ingestion, Publishing, Retrieval, Disclosure and Lifecycle are PHP
interfaces in this item. Their implementations, concrete persistence adapters and
transactional workflows belong to their later owning items. No placeholder
successful operation or production adapter is advertised.

| Boundary | Technical completion and obligation |
|---|---|
| CatalogTransaction | run(callable):mixed; locks retain the approved argument order; lockTenant establishes the adapter's transaction tenant before later ID-only guards; casPointer takes Decimal and returns bool. No global ambient tenant is permitted. |
| CatalogRepository | Every method starts with TenantId. getKb/getSource/getSourceVersion return nullable typed records; listMemberships returns list<Membership>. Explicit insertKb/Source/SourceVersion/Membership/DocumentRevision/ChunkSet/Generation/Snapshot cover the named catalog entities. Every implementation must validate tenant and immutable identity at each read/write. |
| BlobStore / SourceReader | Approved methods use exact typed refs, prior BlobWriteReservation and bounded ReadableStream capability; no arbitrary path input. Cursor and ResourcePage carry tenant binding. Reservation permits null digest/length only together with stagingId. An accepted BlobRef always has full digest/length. |
| Parser / validator / quality / chunker | DocumentParser capabilities and named profile are explicit. ValidationReport is valid/invalid/unknown with safe codes. Coverage and QualityDecision remain separate from job/publication success. ChunkSet is bound to one exact revision and signature. |
| LexicalIndex | index(GenerationId,ChunkSet):IndexReceipt; search(AuthorizedSearchScope,Query,int):CandidateList. Namespace/membership filtering precedes backend ORDER BY/LIMIT. |
| VectorIndex | Same arguments plus explicit EmbeddingSpace on both index and search. Full space mismatch is incompatible, never an implicit lexical fallback. Query embedding or backend preparation belongs to the adapter; this port never infers a provider. |
| EmbeddingProvider | capabilities():EmbeddingCapabilities; embedDocuments(EmbeddingAccess,list<EmbeddingInput>,SpaceRef):Result<EmbeddingBatch>; embedQuery(EmbeddingAccess,QueryInput,SpaceRef):Result<Vector>. EmbeddingAccess is the typed completion of “access”: trusted Scope plus the complete EgressRequest, never a cached Allow. |
| Reranker | Approved signature returns CandidateSubset, which rejects duplicate, unknown or changed exact candidate refs. Fresh processing/egress checks and canonical bounded text remain adapter obligations. |
| DependencyStore | Node is ResourceRef+immutable identity digest; Parents/NodeRefs are typed same-tenant lists. Parent-set digest sorts this declared set, not source spans. Registration must also reject graph cycles under authoritative storage locks; ancestor checks are synchronous. |
| LifecycleStore | Explicit scoped reads of Source, SourceVersionState and Tombstone; correctVersion/revokeIdentity/setRetentionHold return conditional bool under caller-held ordered locks and expected lifecycle epochs. Correction, identity revoke and retention remain separate. |
| JobQueue | Exact approved methods. JobCommand carries Scope, exact source/profile identity and availability. Lease carries Scope, job/attempt/source/generation, sourceEpoch, fence, transient token, expiry and deadline. Checkpoint carries digest and external-effect uncertainty. JobResult carries outcome, digest and optional coverage. Adapter instances are bound to trusted tenant/worker partitions before lease selection. |
| Outbox / OutboxDelivery | append(DomainEvent):void stays in caller transaction. leaseDelivery(int):list<DeliveryLease> and ack(DeliveryLease):bool are segregated. DeliveryLease binds tenant/event/fence/token/expiry; external sends follow lease commit. |
| Clock / IDs | now():UtcInstant and next():Ulid. No system clock or random-ID construction is hidden in application interfaces. |
| SourceConnector | Exact describe/inventory signatures and pinned CrawlSnapshot records. complete cannot coexist with failed/unavailable entries, failures or omissions. Full inventory proof and complete-only reconciliation belong to connector qualification and the later reconciliation use case. |

Additional PHP-only command details: SourceUpload couples in-memory SourceBytes to
a preexisting reservation. BuildGeneration has parallel lists of exact sources
and complete processing identities, checked one-to-one. Correction permits a null
replacement for the approved urgent correction case. LegalHold carries a boolean
separate from lifecycle state. Lifecycle methods without an approved return
record return void; requestPurge returns DeletionTaskId.

Tests: PortContractsTest reflects every public parameter/result type and exact
AccessPolicy::authorizeQuery, EgressPolicy::mayEgress, Disclosure::disclose and
embedding signatures. It verifies tenant arguments, outbox segregation and the
real package/import DAG, including absence of answer/framework imports.

## Authorization and adapter selection

AuthorizedSearchScope materialization intersects current QueryGrant with the
host's already eligible source universe and typed request filters before top-k.
Collection filters require explicit CurrentMemberships from authoritative host
resolution; missing data rejects rather than guessing. The object retains
hostScopeHash verbatim and the scope binding internally; toWire emits only the
existing authorized-search-scope fields. QueryGrant cannot expand host exclusions.

DecisionReceipt::requireAllow verifies operation/resource/tenant/hostScopeHash,
policy epoch and digest, checkedAt, expiry and the five-second freshness ceiling.
Unknown and Deny remain distinct. This helper is not the short SQL transaction
or the remote owner's freshness contract. Disclosure implementations still must
recheck authoritative epochs/lifecycle/ancestors under the approved locks and
return bytes only after confirmed Usage commit; uncertain commit is unknown.

PolicyCheckedEmbeddingProvider is an optional abstract port template, not a
provider implementation. Its final entry points bind actual document/query
digests, exact source refs and destination to the complete request. They call
processing policy and egress policy afresh for every transmission, immediately
before the protected provider method, then validate result type/count/full space.
Providers implementing EmbeddingProvider directly must meet the same obligations.
Timeout uncertainty is not converted into empty/success.

AdapterRegistry implements the final canonical register(AdapterDescriptor,Factory),
describe(AdapterId), resolve(AdapterId,EffectiveProfile) contract. Explicit boot
freezes registration and validates duplicate/missing/cyclic capability dependencies.
Dependencies in CapabilityDeclaration are local requirements of that descriptor;
they do not cause the registry to discover or select another adapter.
Capabilities are explicit known enum values. Discovery never invokes a factory;
unknown IDs/capabilities, unavailable extensions and invalid closed config fail
without invoking another factory. Factory results must implement the declared port.
Readiness has separate installed/enabled/configured/verified/ready/failed/unknown
values; registration does not claim verification or perform a paid call.

Conditional-write false results must be mapped by application code to typed stale
or conflict, e.g. DomainFailure::requireWrite. Lease::isCurrent is the in-memory
predicate counterpart for contract tests; production queue adapters additionally
check durable leased state and use the database clock atomically.

Tests: RegistryTest (including intentionally invalid selections/factory),
BoundaryTest (prefilter, receipt drift, provider spy, space mismatch, stale
fence/token/expiry/deadline, dependency and reservation negatives).

## Fixtures, source reuse and verification

tests/fixtures/identity/manifest.v1.json is a versioned test-only manifest with
exact file digests, supported test schema IDs, provenance and expected outcomes.
The check-group manifest maps every ports test, R05-A01/R05-A02, typo and unknown
capability scenarios to actual PHPUnit cases. Missing/skipped/empty execution
remains a runner failure. Expected processing/IR/space digests were independently
authored with Python hashlib over ASCII-key JCS payloads; they are not snapshots
of the PHP implementation. Changing expectations requires a new fixture version
and rationale.

Pinned donor decisions, exact source hashes, licenses, adaptations and test
provenance are staged in tests/contract/ports/evidence/P1.5-reuse.json. The
coordinator registers this receipt at @plan/artifacts/implementation/P1.5-reuse.json;
the executor does not mutate native plan state. Existing P1.4 canonicalization,
schema, locator and binding helpers are reused directly, with no second encoder.

This is contract coverage with deterministic local doubles, not qualification of
a PostgreSQL/vector backend, a production connector, provider availability or a
transactional disclosure implementation. Those remain the owning later items.
