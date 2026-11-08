# Pinned AgenTools compatibility, corpus 1.0.1

The optional `knowkit/agentools-bridge` Composer project installs the actual
`agentools/platform-core` runtime at
`2054acda51eb69eca1579c78900858c5ff3de01e`. Its lock and
`tests/fixtures/agentools/v1/source-pin.json` bind the inspected implementation
and donor tests. PHP >=8.4 is required for this lane; KnowKit core stays PHP >=8.3
with no vendor runtime dependencies. Install with
`composer install --working-dir=packages/agentools-bridge --no-interaction`.
The private source repository requires the host's existing authorized Git access.
Do not add host dependencies to the root tooling project.

Donor review and local reuse decisions are recorded in
`tests/bridge/contracts/evidence/P8.1-reuse.json`. AgenTools copyright 2026 Dmitry
Vostrikov is retained under private local reuse authority. Public licensing,
redistribution and publication are not authorized here. DeepTutor's inspected
migration guide is Apache-2.0; no DeepTutor implementation is copied.

## Four independent compatibility claims

| Axis | Frozen promise | Evidence and limits |
| --- | --- | --- |
| Wire | Exact legacy EvidenceSet tool envelope, field names, six outcomes, refs and model-view JSON; native ToolResultView shape separately | `envelopes.json` executes the actual pinned donor class. It does not imply a native storage format or a canonical IR. |
| Semantic | Trusted identity mapping, exact host scope binding, immutable historical citation mapping; denied/unknown/unavailable/incompatible remain distinct | `TrustedContextTest` and `IdentityMapTest` assert the mapping boundaries. End-to-end cache/revoke/disclose behavior in library and remote modes remains the runtime bridge conformance gate, not a claim established by envelope equality. |
| Storage | An explicit read-only legacy identity adapter with conflict rejection | `IdentityMap` consumes host-loaded immutable records; it has no write/import/cutover API. SQL persistence, original retrieval, migration execution and backend durability are not qualified by these in-memory tests. |
| Citation | Every historical locator resolves only to its explicitly bound source/version/IR/span identity, or is refused | `R40-A01` and `R40-A02` preserve limited evidence and reject similar/latest fallback. Byte/IR validation belongs to the original-aware importer; synthetic host-accepted refs here test identity, not parser correctness. |

## Trusted boundary map

The host constructs `ExecutionContext::fromHost` through its authorization port.
Model/tool/browser input supplies neither tenant, actor, scope, purpose, run owner,
application owner nor policy epoch. `ContextMap` accepts typed host values and
calls `assertCurrent` before returning a scope or usage binding. Host exclusions
and current corpus/source eligibility must constrain `authorizeQuery` before
top-k. `decide(Operation)` rechecks each actual operation boundary. Business
purpose remains a namespaced string such as `support.read`, separate from the
Operation enum.

The legacy Knowledge Scope also requires a trusted host reconstruction:

| Legacy host field | KnowKit binding |
| --- | --- |
| tenant / subject | Registered tenantId / actorId, checked against ExecutionContext |
| audience / scopes | Host-owned eligibility and policy restrictions before query authorization; no model-created grants |
| purpose | Host-selected namespaced business purpose; no implicit conversion of legacy strings such as education |
| corpus / exact releases from host catalog | Trusted kbId and immutable legacy keys; no latest or similarity fallback |

The legacy Scope constructor is public, so a model-constructed instance alone is
not proof of trust. The native host context and host-supplied catalog bindings
remain authoritative.

`ContextMap::scope` checks the trusted native Scope against the context's tenant,
actor and purpose. This pass-through contract requires host identifiers already
registered as KnowKit ULIDs; it does not invent an implicit conversion of arbitrary
Agentools identifiers. The host supplies kbId and policyEpoch from its trusted
catalog. It also supplies the already pinned `ExecutionContext.scopeHash` as
`PayloadHash`; the mapper removes only its `sha-256:` wire prefix to obtain the
opaque 64 lowercase hex `Scope.hostScopeHash`. It never recalculates or substitutes
the digest. Selection, EvidenceSet, cache and Usage bindings must preserve those
64 hex characters verbatim. Different hostScopeHash is denied(scope_mismatch).
The mapper does not establish a permanent authorization grant.

Run → bridge `UsageContext` retains tenant-qualified `applicationRef`, `runRef`
and host `correlationId` (the native traceId), plus the exact trusted Scope.
Foreign run/application refs fail closed. The current closed core
DisclosureRequest usageContext permits runRef, invocationId and snapshotUseId;
`disclosureWire()` projects runRef only. applicationRef and correlationId stay
host metadata, not extra wire fields or a schema extension. The core's existing
run-bound Selection checks still apply. No Run API, schema or ownership is changed.

Immediately before a model handoff, the runtime bridge must call `disclose` again.
A historical `EvidenceSet::toolView` cannot authorize a new handoff, preview or
replay. PreparedDisclosure records preparation only. ProviderAccepted requires a
separate trusted host acknowledgment with a protected provider request receipt;
the closed public event payload is `{usageId, disclosureAttemptId}`. Without
provider confirmation, acceptance stays unknown. No provider input or document
text is logged by this adapter.

## Envelope and hash meanings

Legacy EvidenceSet owns `trust_class=untrusted` and `memory_activation=false`.
These fields are not invented properties of native `ToolResultView`. The native
class has rawResultRef/modelViewRef/userViewRef/auditViewRef, classification,
freshAt and evidenceRefs. Its ContextBlock is always untrusted. Only modelView is
model data; raw/user/audit views remain distinct Agentools owner refs. Text that
asks to change trust, tenant or memory activation has no authority.

`hashes.json` pins both `PayloadHash` forms: `sha-256:SHA256(raw bytes)` and the
tenant/domain/revision canonical payload envelope. Object and list shapes differ;
object key order is canonicalized only for the latter. A bare legacy release
projection hash retains its original `legacyHashKind` and value. It is never
renamed into SourceDigest, IrDigest, contentDigest or a canonical Fingerprint.
The same hash algorithm does not establish compatible hash domains.

## Exact legacy records and locators

`ReleaseIdentity` uses the composite key
`(tenant, corpus, releaseId, legacyHashKind, legacyHash)`. The key encodes a tuple,
not delimiter concatenation. Duplicate keys fail with incompatible immutable
conflict, including attempts to overwrite a prior mapping with newer content.
Foreign tenant/kb lookups are denied; absent exact keys are unavailable, never
empty, and never resolved by a latest pointer or similarity search. An identity
lookup returns refs only; it cannot disclose payload or bypass lifecycle checks.

An original-aware importer supplies a validated SourceVersionRef inside the
DocumentRevisionRef, its immutable IR digest/parser signature, SnapshotRef and
explicit CitationBindings. Every canonical citation must match the target source,
version, source digest, revision and IR digest. The historical locator includes
material/version/fragment/page/start/end/content_hash and the exact old release.
It remains in donor units; page or byte offsets are not heuristically translated
into canonical IR spans. Each locator has an explicit binding to a canonical
CitationRef whose spans/content identity were independently accepted from the
original. A different locator is refused. Historical mappings never move to a
corrected or similar release.

Without originals, `Mapping::withoutOriginals` produces
`legacy_unverified_evidence` with original_bytes_unavailable,
provenance_uncertain and canonical_ir_not_validated limitations. It retains
legacyHashType, legacyReleaseHash, `provenance=legacy_unverified`, an exact
host-owned resolver ref and `freshlyValidated=false` in the approved
legacy-evidence-ref schema. It creates no canonical target or citation and never
claims fresh full validation. The host must retain the resolver's exact composite
identity; a generic source/latest ref is not a valid resolver assignment.

Migration ordering remains: freeze corpus → read-only bridge → import exact
catalog/versions/tombstones/dependencies → build new IR only from originals →
shadow compare without disclosure → source/KB authority CAS cutover → keep legacy
resolver. Rollback changes owner authority coherently, with one write path.
Resolver retention is at least 12 months after cutover and longer while retained
evidence references exist. Purging payload preserves deny/unavailable identity;
shortening retention requires the owner. No cutover or purge is implemented here.

## Executable proof and later work

`tests/fixtures/check-groups/bridge-contracts.json` declares every required case,
fixture SHA-256, private provenance and expected outcome. The existing QA runner
binds required IDs to executed PHPUnit methods and fails on unknown groups,
missing tests, empty selection, skipped cases and failed nested checks. Expected
digest changes require a fixture version and a behavior-based rationale;
regenerating response snapshots is not an approval mechanism.

P8.4 must qualify the proposed evidence-context contributor against a real
upstream API. This corpus neither invents that seam nor qualifies the complete
Chatom/Agentools loop. P8.2 still needs merged+installed seam qualification and
the shared library/remote authorization, cache/revoke and historical replay gates.
