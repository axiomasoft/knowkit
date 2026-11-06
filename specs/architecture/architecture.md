# Normative architecture contract v1

The MUST requirements below materialize approved D1/D2 and the consolidated exact contracts. They authorize no additional implementation. Stable anchors are indexed by [manifest.v1.json](manifest.v1.json); ADRs record the decisions. JSON field-level schemas are owned by P1.4.

<a id="arch-authority"></a>

## ARCH-AUTHORITY — Authority and scope

Consolidated architecture and scoped exact contracts MUST control execution; raw Opus answers are approval provenance. D1/D2 approve design, not implementation completion, product readiness or publication. Detailed wire schemas and cross-language golden vectors belong to P1.4.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-core"></a>

## ARCH-CORE — Independent core

The only mandatory Composer library MUST be knowkit/core, PHP >=8.3, zero vendor runtime dependencies. Its namespace is KnowKit\ and paths are packages/core/src/{Domain,Application,Port,Registry,Adapter}, migrations/postgres and bin/knowkit. PostgreSQL requires ext-pdo_pgsql; base extensions are ext-pdo, ext-json, ext-mbstring. Separate contracts/application/postgres Composer packages MUST NOT be created.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-layers"></a>

## ARCH-LAYERS — Dependency direction

Domain MUST remain independent of Application, Port, Registry and Adapter; Port may use Domain values. Application uses Domain/Port and explicit Registry resolution, never concrete adapters. Registry exposes static descriptors/factories; adapters implement ports using Domain values. The composition root wires Application, Registry and Adapter. No core layer may import Laravel, Agentools, answer or PSR HTTP.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-package-dag"></a>

## ARCH-PACKAGE-DAG — One package DAG

Optional packages MUST follow the dependency edges in manifest.v1.json. answer/pgvector/http/laravel depend on core; chatom depends on answer/core and explicit Chatom contracts; agentools-bridge depends on core and the existing Agentools core/platform-chatom seam. Bridge MUST NOT depend on answer/chatom; Chatom and bridge MUST NOT depend on one another. No knowkit/edufy package exists.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-delivery"></a>

## ARCH-DELIVERY — Library and thin transports

Library and CLI MUST expose the same application use cases; HTTP is an optional thin transport that authenticates, constructs trusted Scope and serializes the same outcomes. The minimal delivery uses PHP/PostgreSQL/TXT with no Laravel, Agentools, Python, Redis, GPU, vector database or model key.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-milestones"></a>

## ARCH-MILESTONES — Approved roadmap

The roadmap MUST remain v0.1 core/hostScopeHash/registry/navigation/connectors port; v0.2 optional answer and Edufy example; v0.3 hybrid pgvector; v0.4 Python worker and Adapter\ProcessWorker\ProcessWorkerSupervisor; v0.5 HTTP/Laravel/Chatom direct; v0.6 Agentools bridge; v1.0 contract freeze.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-install"></a>

## ARCH-INSTALL — Explicit install and registration

Installation MUST have no routes, tables, workers or model-call side effects. Laravel MUST have no autodiscovery: the host explicitly registers the provider, publishes migrations and enables routes. Constructor wiring and adapter registration are explicit; unknown provider names never select a default.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-registry"></a>

## ARCH-REGISTRY — Adapter registry

KnowKit\Registry MUST expose static AdapterDescriptor{id,kind,version,configSchema,capabilities,requiresExt}; AdapterRegistry::register(AdapterDescriptor,Factory), describe(AdapterId), resolve(AdapterId,EffectiveProfile). Duplicate/unknown IDs and cyclic capability dependencies fail. Discovery MUST NOT instantiate a factory or scan arbitrary packages.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-profile"></a>

## ARCH-PROFILE — Immutable effective profiles

EffectiveProfile MUST be immutable and identified by SHA256(JCS(strict redacted profile)); CredentialRef is opaque and only credential presence is recorded. Readiness distinguishes installed/enabled/configured/verified/ready/failed/unknown. Capability absence and unsupported major versions are explicit; no paid verification occurs by default.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-worker"></a>

## ARCH-WORKER — Isolated worker

The Python worker MUST depend on schemas only and use subprocess staged files, not HTTP. Adapter\ProcessWorker\ProcessWorkerSupervisor belongs to v0.4. Workers receive scoped input/output capabilities, no catalog credentials, ACL or publication authority. PHP independently verifies source/profile/schema, full asset inventory, digests and fencing before accepting any result; symlink/hardlink/escaping paths are rejected.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-connectors"></a>

## ARCH-CONNECTORS — Optional source connectors

SourceConnector MUST remain an optional port with CrawlSnapshot{complete,items}; absence removes source inventory entries only when the inventory is complete. Native host storage rights remain authoritative; connecting or detaching external resources does not transfer ownership.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-navigation"></a>

## ARCH-NAVIGATION — Exact source navigation

SourceNavigation MUST use exact and whitespace matching, return at most 16 ambiguous candidates and never retarget a pinned citation. Optional NfdNormalizerAdapter requires an ext-intl capability descriptor; no silent normalization fallback is allowed.

Decision: [0001-independent-core-and-delivery.md](../../docs/adr/0001-independent-core-and-delivery.md).

<a id="arch-tenant"></a>

## ARCH-TENANT — Tenant persistence boundary

Every persistence boundary MUST verify tenant scope and immutable identity. Primary/foreign keys and uniqueness are tenant-scoped; a source, parent, snapshot, generation or resource of another tenant cannot be accepted, inferred from an opaque ID or disclosed. External identifiers do not establish authority.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-version-source"></a>

## ARCH-VERSION-SOURCE — Source version axis

SourceVersionId MUST identify immutable original bytes with SHA256(bytes). Changed bytes require a new SourceVersionId; the same identity with different bytes or parents is a conflict. Original bytes MUST NOT change when OCR, chunking or embedding changes.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-version-document"></a>

## ARCH-VERSION-DOCUMENT — Document revision axis

DocumentRevisionId MUST identify the immutable extraction of one exact source version. Parser/OCR/significant settings changes produce a new document revision without changing that source version. IR digest is SHA256(JCS(IR)); parser/profile identity and exact parents are retained.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-version-chunkset"></a>

## ARCH-VERSION-CHUNKSET — Chunkset axis

ChunkSetId MUST identify one immutable chunking of an exact document revision. Rechunking produces a new chunkset without changing the source version or document revision. Source spans and reading order remain bound to canonical text; added embedding context cannot masquerade as a contiguous quote.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-version-generation"></a>

## ARCH-VERSION-GENERATION — Index generation axis

GenerationId MUST identify a rebuildable index generation. Embedding, analyzer, backend or index profile changes create a new generation without impersonating new source/document/chunk identities. A sealed manifest binds exact members; rebuilds preserve historical citation identity.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-citation"></a>

## ARCH-CITATION — Immutable citations

Every citation MUST bind exact tenant/source/sourceVersion/documentRevision/document/IR digest, canonical page or segment, block, half-open UTF-8 byte spans and content digests. Byte offsets address immutable canonical text, not PDF bytes. Resolve the exact retained artifact or refuse; historical citations MUST NEVER redirect to latest.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-canonical"></a>

## ARCH-CANONICAL — Canonical authority

Authoritative metadata/lifecycle/manifests, immutable canonical artifacts and rebuildable projections MUST remain separate. Search backend text is never citation authority. Missing/corrupt canonical bytes fail closed even when backend snippets appear valid; caches store refs/ranks, not grants.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-snapshot"></a>

## ARCH-SNAPSHOT — Exact publication snapshot

An explicit latest policy MUST resolve once to an exact PublicationSnapshot. Retrieval/rerank/disclosure stay bound to it and cannot mix a concurrent activation. A KB has one activeSnapshotId and pointerVersion; all profile generations belong to the exact snapshot manifest.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-lineage"></a>

## ARCH-LINEAGE — Exact derivative parents

Every derivative MUST retain exact same-tenant parent refs and immutable parent-set digest. Self-reference, duplicate parents and graph cycles are rejected. Current ancestor usability is checked synchronously even when projection cleanup or invalidation propagation lags.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-ir"></a>

## ARCH-IR — IR and evidence semantics

Document IR MUST preserve native structure, reading order, canonical text, assets and diagnostics; Markdown alone is not canonical. Paginated physical numbers are 1-based and separate from printed labels; nonpaginated segments do not invent pages. source/ocr/generated origin and verbatim/table_representation/description/summary provenance remain distinct. Integrity does not establish OCR quality or entailment.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-processing"></a>

## ARCH-PROCESSING — Exact processing identity

ProcessingIdentity MUST pin source digest, parser/OCR/chunker signatures, IR schema, index profile and full embedding space. Reuse requires declared stage identity plus independently verified manifests/digests; credentials and transient signed URLs are excluded. Equal vector dimensions alone never prove compatibility.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).

<a id="arch-ingestion-register"></a>

## ARCH-INGESTION-REGISTER — Register source

Ingestion::registerSource(Scope,RegisterSource,IdempotencyKey):SourceReceipt MUST use a host-allocated stable sourceId and tenant barrier, check unseen-source tombstones before insertion and authorize admission. Source identity is tenantId/sourceId, independent of URL, content digest or membership.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-ingestion-version"></a>

## ARCH-INGESTION-VERSION — Add source version

Ingestion::addVersion(Scope,SourceUpload,IdempotencyKey):VersionReceipt MUST reserve immutable bytes durably before PUT, verify exact digest/parents and check current lifecycle; stale registration cannot resurrect a revoked identity. Uncertain writers prevent GC/purge until reconciliation.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-build-generation"></a>

## ARCH-BUILD-GENERATION — Build generation

Publishing::buildGeneration(Scope,BuildGeneration,IdempotencyKey):GenerationReceipt MUST separate admission, immutable bytes, capability/egress-aware planning, extraction, validation, coverage, chunkset, staging indexes, readiness and publication. Partial staging MUST NOT become active.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-seal"></a>

## ARCH-SEAL — Seal generation

SealGeneration MUST independently verify artifacts, exact membership/counts/assets/digests and IndexReadabilityProbe before sealed. Under ordered locks verify fence, epochs, tombstones, policy and coverage; seal immutable manifest plus outbox in one local transaction. JSON validity alone cannot establish readiness.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-publish"></a>

## ARCH-PUBLISH — Publish snapshot

Publishing::publish(Scope,GenerationId,expectedPointerVersion):PublishOutcome MUST obtain fresh publish authorization outside the transaction and recheck binding/epochs/lifecycle under ordered locks. Insert snapshot and CAS active pointer in one short transaction; zero affected rows is conflict/rollback, never success. No unsealed generation is accepted.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-retrieve"></a>

## ARCH-RETRIEVE — Retrieve selection

Retrieval::retrieve(Scope,Query,Limit):RetrievalOutcome MUST call AccessPolicy::authorizeQuery(Scope):QueryGrant before top-k. Intersect grant, typed request filters, source/KB/collection/purpose rights and current host eligibility before ORDER BY/LIMIT. Unsupported enforceable namespace/filter rejects the backend; unrestricted search then postfilter is forbidden.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-selection"></a>

## ARCH-SELECTION — Reference-only selection

Selection MUST contain refs/ranks only and bind tenant/actor/purpose/hostScopeHash, query, exact snapshot/profile, policy, limits, usage/run and expiry. Selection TTL is 30 seconds by default, maximum 300. Cached selections and historical EvidenceSet never grant fresh disclosure.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-disclose"></a>

## ARCH-DISCLOSE — Disclose evidence

Disclosure::disclose(Scope,DisclosureRequest):DisclosureOutcome MUST require selectionId, subset chunkRefs and disclosureAttemptId. Optional usageContext is trusted host/bridge input; omitted library calls record runRef=null. UNIQUE(tenant_id,disclosure_attempt_id) prevents reuse as a new plaintext disclosure.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-disclosure-commit"></a>

## ARCH-DISCLOSURE-COMMIT — Disclosure linearization

Disclosure MUST fetch current policy receipts and preload/verify bounded canonical buffers outside SQL transactions, then take tenant/KB/source/generation shared guards. Recheck epochs, current lifecycle/tombstones, exact membership, scope, expiry and receipt freshness by DB clock; persist Usage/prepared_disclosure and confirm COMMIT before returning any bytes. Commit is the local linearization point.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-disclosure-replay"></a>

## ARCH-DISCLOSURE-REPLAY — Attempt replay and uncertain commit

An existing disclosureAttemptId MUST return metadata only, never old plaintext; a new transfer requires a new attempt and current checks. An uncertain COMMIT returns unknown without text. A later revoke cannot retract a buffer already committed; no guarantee of zero bytes after every future revoke commit is made.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-scope"></a>

## ARCH-SCOPE — Trusted scope

Scope MUST be host-created {tenantId,kbId,actorId,purpose,policyEpoch,hostScopeHash}. hostScopeHash is trusted opaque lowercase 64hex; Agentools ExecutionContext.scopeHash passes verbatim. Scope.purpose is a business string, separate from Operation. PolicyContext audience/grants/egress binds Scope and cannot come from model/tool JSON; mismatch returns denied(scope_mismatch).

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-operations"></a>

## ARCH-OPERATIONS — Operation is separate from purpose

Operation MUST distinguish retrieve, disclose, publish, model_egress_context, navigate and explicit mutation operations: catalog_create, collection_create, membership_mutate, source_register, source_version_add, ingest, reprocess, job_cancel, lifecycle_correct, lifecycle_revoke, lifecycle_delete, legal_hold_set, purge_status, provider_acceptance, diagnostics. Business purpose does not substitute for an operation.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-policy"></a>

## ARCH-POLICY — Authoritative intersected access

AccessPolicy::decide(Scope,ResourceRef,Operation):DecisionReceipt MUST check authoritative current rights at admission, publication and disclosure. Source/KB/collection/purpose/operation restrictions intersect; membership cannot widen source rights. Default policy and egress are DenyAll; Unknown is never a cached Allow.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-policy-epochs"></a>

## ARCH-POLICY-EPOCHS — Policy binding and changes

PolicyBinding MUST bind tenantPolicyEpoch, kbPolicyEpoch, sourcePolicyEpoch/lifecycleEpoch and hostScopeHash. Group changes increment tenant epoch; KB/collection/ACL/membership changes increment KB epoch; source ACL changes increment source epoch under respective exclusive guards. Epoch mismatch gives denied/POLICY_CHANGED and requires a fresh decision; an epoch is not permission.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-external-window"></a>

## ARCH-EXTERNAL-WINDOW — External-owner limitation

External-owner receipts MUST retain opaque ownerRevision, checkedAt, expiresAt and an explicit freshness contract. Local epochs cannot prove that remote rights have not changed. An admitted bounded check-to-commit window remains an owner-contract limitation; unknown freshness returns unknown without text. No distributed atomicity or downstream retraction is promised.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-egress"></a>

## ARCH-EGRESS — Complete classified egress

EgressPolicy::mayEgress(Scope,EgressRequest):DecisionReceipt MUST run immediately before each provider transmission. Immutable EgressRequest binds operation, destination(providerId/accountId/region/modelId?), classified items(category/contentDigest/sourceVersionRefs) and complete manifestDigest. QUESTION/history/evidence/assets cannot be omitted from the manifest; read permission is not provider consent. Core MUST NOT import answer DTOs or expose credentials/raw bytes in this request.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-locks"></a>

## ARCH-LOCKS — Global lock order

All operations MUST lock tenant → sorted KB → sorted Source → sorted IndexGeneration → sorted Job; auxiliary resource/idempotency/outbox rows follow semantic guards, sorted within groups. READ COMMITTED readers reread after waiting. Never acquire an earlier missing guard after a later one: rollback and replan. Lock timeout is unavailable, never empty.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-tenant-barrier"></a>

## ARCH-TENANT-BARRIER — Unseen source and membership barrier

Registration, unseen-source revoke and membership mutation MUST take tenant FOR UPDATE before KB locks. Identity-wide revoke obtains the full membership set under this barrier, then sorted KB/source guards. Tombstones exist without a Source row and apply to future versions; delayed jobs/registration cannot resurrect content.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-no-io-in-tx"></a>

## ARCH-NO-IO-IN-TX — Bounded local transactions

SQL transactions MUST contain only SQL, checks of already loaded bytes and bounded DTO assembly. No policy callbacks, blob/provider/process I/O or remote calls may run inside a transaction. Provider uncertainty is recorded outside the transaction rather than holding metadata locks.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-lifecycle"></a>

## ARCH-LIFECYCLE — Correction, revoke and hold

Lifecycle::correct(Scope,Correction,IdempotencyKey), revoke(Scope,Revocation,IdempotencyKey), placeLegalHold(Scope,LegalHold,IdempotencyKey) MUST remain distinct. Ordinary v2 may preserve authorized v1; correction invalidates exact old SourceVersion immediately, even before replacement readiness; revoke blocks every version and applicable derivative of Source. RetentionHold retains bytes without granting read rights; history hashes are never rewritten.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-purge"></a>

## ARCH-PURGE — Request purge

Lifecycle::requestPurge(Scope,PurgeRequest,IdempotencyKey):DeletionTaskId MUST distinguish logical revoke, projection removal, physical purge and backup-retention expiry. The ledger retains attempts/receipts/unsupported/unknown; required unknown resources prevent aggregate completion. Externally owned originals detach unless explicit owner-authorized deletion exists.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-restore"></a>

## ARCH-RESTORE — Restore and reconcile

ReplayTombstones MUST prove a complete tombstone tail before ready/search/disclosure after restore. ReconcileOrphans MUST correlate pre-PUT reservations and provider outcomes with owned resources; config removal alone is not completed deletion. Legal hold and uncertain writers block physical purge.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-jobs"></a>

## ARCH-JOBS — Durable jobs and fencing

JobQueue MUST support leases, fencing, heartbeat/checkpoint, retries and cancellation. Stale fences cannot accept/publish a result after lease replacement. Local result acceptance is idempotent; a timed-out provider may have executed twice, so no external exactly-once guarantee is made.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-outbox"></a>

## ARCH-OUTBOX — Committed events

Outbox::append MUST participate in the caller transaction alongside publication/lifecycle effects. OutboxDelivery lease/ack is separate; delivery is at-least-once with immutable event identity and deduplication. False conditional writes become typed stale/conflict, not successful receipts.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-catalog"></a>

## ARCH-CATALOG — Catalog services

Catalog::createKnowledgeBase/createCollection/addMembership/getMetadata MUST preserve tenant ownership and membership intersection; catalog, source owner and file storage remain separate authorities. No domain API exposes raw repositories.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-ingest-jobs"></a>

## ARCH-INGEST-JOBS — Ingest job services

IngestJobs::plan/start/status/cancel/reprocess MUST call the same use cases across library/CLI/HTTP. Internal ProcessDocumentRevision and BuildChunkSet preserve exact lineage and fencing; cancellation does not imply remote work was undone.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-reading"></a>

## ARCH-READING — Citation and preview reading

Reading::resolveCitation/readSource/readPage/readRegion/readTable/readAsset MUST use the same current disclosure protocol, exact retained identity and bounded payload. No permanent public blob URL may bypass authorization; missing retained originals cause refusal, never redirection.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-usage"></a>

## ARCH-USAGE — Usage and acknowledgments

Usage::registerProviderAcceptance/invalidations MUST distinguish prepared_disclosure from host-observed provider_accepted. Usage binds exact refs, actor/purpose, hostScopeHash and invocation/snapshot-specific dependency IDs. Historical usage is a fact, never permission to send old text again.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-diagnostics"></a>

## ARCH-DIAGNOSTICS — Diagnostics services

Diagnostics::capabilities/readiness/coverage MUST report capability absence and extraction completeness separately from job/publication state. Partial publication requires explicit allowPartial policy and exposes missing features; JSON/schema validity or batch success does not prove full coverage.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-outcomes"></a>

## ARCH-OUTCOMES — Six typed outcomes

Public outcomes MUST remain success, empty, denied, unknown, unavailable, incompatible. Unsuccessful outcomes disclose no document text. Allowed degradation reports degraded=true, effectiveProfile and warnings; an explicit embedding-space mismatch remains incompatible and cannot silently degrade. Partial coverage is separate from outcome.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-retrieval-integrity"></a>

## ARCH-RETRIEVAL-INTEGRITY — Candidates, fusion and rerank

Candidate refs MUST match immutable manifests/canonical artifacts. RRF uses ranks across lexical/vector scores; PostgreSQL FTS is not BM25. Rerank may only reorder/filter existing authorized refs, never add IDs, spans or quotes; external rerank needs separate current egress authorization. Optional EvidenceDiversifier runs after RRF, off by default.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-budgets"></a>

## ARCH-BUDGETS — Bounded disclosure budgets

Caller budgets MUST only reduce named profile limits and hard global caps on bytes/fragments/assets/tokens. Preserve table/code units or require explicit follow-up reading. compact defaults are 4 fragments/4096 bytes/1024 bytes per fragment/0 assets. Token limits need explicit tokenizer ID/version/vocabulary digest; otherwise incompatible(tokenizer_required), never bytes/4 estimation. One denied/stale/corrupt required ref blocks the entire payload.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-mutations"></a>

## ARCH-MUTATIONS — Safe commands

Mutations MUST use tenant-scoped idempotency keys and applicable expected revision/epoch; operation belongs to the request fingerprint. Same-key changed payload conflicts. Typed filters, bounded inputs, SSRF policy and validated origins prohibit arbitrary SQL/filesystem/URL injection; credentials MUST NOT be command arguments.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-untrusted"></a>

## ARCH-UNTRUSTED — Untrusted evidence and logs

Documents, worker results and provider text MUST remain untrusted. Approval metadata never promotes content to system/developer instructions or activates memory. Logs MUST NOT contain document text, credentials or raw provider inputs; typed redacted diagnostics and digests are sufficient.

Decision: [0003-access-publication-and-disclosure.md](../../docs/adr/0003-access-publication-and-disclosure.md).

<a id="arch-answer"></a>

## ARCH-ANSWER — Optional single-turn answer

knowkit/answer MUST remain optional GroundedAnswering with explicit model transport and caller-supplied bounded authorized history. It owns no transcript store or agent loop. Unconfigured transport returns unavailable; ScriptedTransport is fixture=true/production=false only. Injected PSR-18 client/credentials never cause a hidden paid request.

Decision: [0004-optional-answer-and-host-seams.md](../../docs/adr/0004-optional-answer-and-host-seams.md).

<a id="arch-model-binding"></a>

## ARCH-MODEL-BINDING — Immutable model binding

Answer model binding MUST resolve once at first accepted invocation and pin an immutable resolved model binding. The fingerprint includes tenant/invocation, full hostScopeHash, question/turns, exact retrieval profile and model binding. Model identity is independent of lesson/conversation identity.

Decision: [0004-optional-answer-and-host-seams.md](../../docs/adr/0004-optional-answer-and-host-seams.md).

<a id="arch-answer-replay"></a>

## ARCH-ANSWER-REPLAY — Direct answer replay

The answer invocation ledger MUST belong only to the direct route. unknown/sending states never resend; completed replay rechecks current Scope/lifecycle/history visibility/audience/egress BEFORE plaintext materialization. Denial preserves the old record and never resends a model request.

Decision: [0004-optional-answer-and-host-seams.md](../../docs/adr/0004-optional-answer-and-host-seams.md).

<a id="arch-chatom"></a>

## ARCH-CHATOM — Chatom history and delivery

Chatom MUST own current authorized ViewerMessageReader history, persistence and delivery. ConversationBindingResolver maps host lesson/channel/model references; a direct handler delegates answer and returns a semantic reply slot. Chatom reauthorizes/persists; KnowKit MUST NOT write Chatom tables.

Decision: [0004-optional-answer-and-host-seams.md](../../docs/adr/0004-optional-answer-and-host-seams.md).

<a id="arch-routes"></a>

## ARCH-ROUTES — Per-binding route guard

Route registration MUST use key=(tenant,space,channel,bot,binding) with exactly one direct_chatom OR agentools_orchestrated handler per key. Other bindings/bots may use different routes. Exclusivity is per binding, not global for a host.

Decision: [0004-optional-answer-and-host-seams.md](../../docs/adr/0004-optional-answer-and-host-seams.md).

<a id="arch-bridge"></a>

## ARCH-BRIDGE — Existing Agentools loop

The bridge MUST preserve the existing Agentools context compiler, routing/skills/actions/confirmation/budget/journal/loop/delivery. It uses upstream EvidenceContextContributor/TextComposition/CurrentSourceAuthority seams, not a partial HostOwner implementation, and imports neither answer nor chatom. Canonical bounded text enters ReadContextCompiler BEFORE token counting, never as a transport append.

Decision: [0004-optional-answer-and-host-seams.md](../../docs/adr/0004-optional-answer-and-host-seams.md).

<a id="arch-bridge-usage"></a>

## ARCH-BRIDGE-USAGE — Bridge scope and fresh evidence

The bridge MUST forward ExecutionContext.scopeHash verbatim, map Run to trusted usageContext and exact pins, keep independent owner references and untrusted tool envelope with memory_activation=false. Current source state is rechecked at model invocation and delivery even when a mirror lags. Dependency usage IDs are invocation/snapshot-specific; changing parents cannot be appended to one immutable Run.

Decision: [0004-optional-answer-and-host-seams.md](../../docs/adr/0004-optional-answer-and-host-seams.md).

<a id="arch-edufy"></a>

## ARCH-EDUFY — Host-owned Edufy

Edufy MUST own learner/lesson/topic/age/tutor binding and source authority. KbResolver override/additive exclusions define host eligibility before top-k. MaterialPassageReader belongs to the AiCore→Content consumer with its adapter in the owning host, avoiding a Content→AiCore HTTP cycle. No pedagogy domain or edufy package belongs in core; examples/edufy-first-adopter uses explicit typed fakes until host Library/Files qualification.

Decision: [0004-optional-answer-and-host-seams.md](../../docs/adr/0004-optional-answer-and-host-seams.md).

<a id="arch-migration"></a>

## ARCH-MIGRATION — History and migration limits

Migration MUST distinguish wire/semantic/storage/citation compatibility, import tombstones/dependencies and switch one authoritative writer per KB/source. Limited legacy evidence retains its original hash type/provenance uncertainty and exact resolver; it MUST NOT be called freshly validated or silently remapped to similar/latest content.

Decision: [0002-immutable-identity-and-authority.md](../../docs/adr/0002-immutable-identity-and-authority.md).
