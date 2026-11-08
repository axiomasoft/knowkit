# Evidence context contract 1.0.0

KAT-EVIDENCE-CONTEXT-1 is a **pending upstream change request** owned by Agentools,
package `agentools/platform-chatom`. This repository delivers the proposal and
contract-scripted qualification tooling. It does not deliver or claim a merged,
installed or runtime-qualified upstream seam.

The normative machine-readable surface is
[change-request.v1.json](../../contracts/agentools/evidence-context/change-request.v1.json).
Its `signatures` records exact FQNs, method and parameter names, types, order,
optionality, constructor defaults, readonly DTO properties and boundary stages.
The fixture's closed schemas freeze the serialized test contract; its metadata
schema has `additionalProperties: false`, including nested records. Unknown fields,
different types, missing fields and signature/schema/request/source hash drift fail.
Changing this proposal requires an explicit version/digest update and qualification;
duck typing cannot accept a different upstream surface.

```php
EvidenceContextContributor::pin(): ContributionPin;
EvidenceContextContributor::collect(
    ExecutionContext $context,
    IncomingInput $input,
    EvidenceContextRequest $request,
): EvidenceContribution;

CurrentSourceAuthority::id(): string;
CurrentSourceAuthority::authorizeBlock(
    ExecutionContext $context,
    ContextBlock $block,
    ContributionReceipt $receipt,
    BoundaryStage $stage,
): void;
CurrentSourceAuthority::authorizeSnapshot(
    ExecutionContext $context,
    ContextSnapshot $snapshot,
    ContributionReceipt $receipt,
    BoundaryStage $stage,
): void;
```

Here `ExecutionContext`, `ContextBlock` and `ContextSnapshot` are the existing public
platform-core types; `IncomingInput` is the existing public Chatom type. All new
types are proposed under `AgenToolsPlatform\Chatom`. The fixture uses an isolated
namespace and explicit test-only type mapping for synthetic input/block/snapshot
surrogates. The surrogate block schema is a test projection of immutable evidence
identity and model bytes; it is not a replacement for native ContextBlock's layout,
manifest, scoped hash algorithm, trust metadata or constructor. Native source/hash
pins and installed schema/reflection parity remain separate upstream obligations.

`ContributionPin` is `{id, version, contractDigest, configDigest}`. All four fields
are immutable and credential-free. `EvidenceContribution` is `{pin, blocks,
dependencies, receipt}`: blocks are a bounded list of native ContextBlock values,
dependencies are exact `{ref, version, hash}` records. Every selected block must
have an exact dependency; conflicting identities/hashes refuse. The fixture
retention manifest binds the complete pin, receipt and dependencies, and does not
claim to implement native SnapshotPins or native context hashing.

The pin matrix's `contractDigest` hashes exact change-request bytes, binding the
complete versioned proposal, including signatures, schemas and algorithms.
`signatureDigest` and `schemaDigest` hash RFC8785 JCS values; source, artifact and
empty-default request hashes bind exact bytes. `pinDigest` hashes the complete
ContributionPin as JCS. Hash domains are explicit and never renamed into native
platform-core scoped hashes.

`EvidenceContextRequest` contains trusted `turnId`, `runId`, proposed
`invocationUseRef`/`snapshotUseRef`, `bundleRef`/`bundleHash`, `hostScopeHash`,
`maxFragments`/`maxBytes`, `operation=ModelEgressContext` and current UTC `now`.
These refs correlate existing owners and do not establish another journal.
Preserve the entire trusted ExecutionContext, including tenant, actor, acting-as,
subject, authorization, consent, agent principal, policy revision, session and
business purpose. Operation never replaces business purpose. Raw model/input text
cannot supply or change trusted scope.

`ContributionReceipt` is `{schema, id, pinDigest, hostScopeHash, selectionRefs,
evidenceSetIds, sourceEpochs, expiresAt, modelViewDigests}`. Its schema is
`urn:knowkit:agentools-contribution-receipt:1.0`. Epoch entries are `{ref, epoch}`;
model digest entries are `{ref, hash}`. Receipt metadata contains no source body or
credentials. Historical evidence, answers and receipts never grant current access.
Every citation stays bound to its immutable source/version/IR/span; latest content
must never replace historical identity.

Append `list<EvidenceContextContributor> $evidenceContributors = []` to
TextComposition's existing constructor, preserving every preceding parameter,
name, type, order and default. The empty default must preserve native layout,
request bytes/hash and behavior exactly, without adding empty evidence keys.
TextContextAuthority gains optional `array $currentSourceAuthorities = []`.
Register authorities by exact contribution `id` plus complete `pinDigest` and
require `authority.id()` to match. Missing, stale, denied or unknown refuses.

Contributors run before native `buildPlan`; their dependencies merge into native
SnapshotPins, then the normal compiler/finalize/save path runs. Native retention
and hash manifests must bind contribution pins and receipts. Current-source
authorization supplements existing HostAuthorization/profile/history checks. It
rechecks current KnowKit policy, ancestor lifecycle, epochs, scope, expiry and exact
selected source and model-view digest at compilation, saved restore, immediately
before model IO, after durable intent, buffered delivery and actual delivery.
Saved compiled bytes must not silently recollect latest or change on retries.
Stale/expired evidence requires an explicit new normal invocation/snapshot.

External owner calls occur outside Agentools SQL transactions. Agentools keeps
its native journal/CAS; recheck after durable intent. There is no global transaction
across KnowKit, Agentools and Chatom, automatic retry, repair call, provider fallback
or hidden paid readiness probe.

The compact profile is 4 fragments/4096 UTF-8 bytes. Consumer ceilings are 8/8192
and at most 32 aggregate owner slices. Effective limits are the minimum of the
qualified named profile, caller reduction and consumer ceiling, aggregated across
contributors. `agentools-v1` needs separately declared defaults and qualification;
this fixture does not invent them or accept an arbitrary unqualified profile.

The versioned case manifest contains the eight required `bridge-upstream:*`
checks and exactly A01–A18. Their obligations and scripted/native distinction are
listed in the change request. The local runner executes all mapped PHPUnit methods,
requires each case, rejects missing, duplicate, unknown, skipped and zero cases,
checks fixture hashes and returns `fixture=true`, `upstream_seam=pending`.

```sh
php tests/bridge/upstream-contract/run.php
composer qa -- bridge:upstream-contract
php packages/agentools-bridge/bin/probe-evidence-context.php
```

The first two commands qualify only the scripted contract. The last is an
independent nonmutating installed-driver surface probe, loads no fixture mappings
and returns nonzero `unavailable` until real merged+installed delivery is pinned.
No provider, database, repository mutation or dependency installation occurs.
Even installed surface parity is not full runtime/host qualification.

Golden empty-default request bytes are independently authored synthetic bytes,
not captured native compiler evidence. Local A01–A18 checks prove the scripted
contract and rejection paths; they cannot prove real owner/compiler/runtime/relay
behavior or advance the v0.6 release gate. Core QA may omit pending optional
installed/host profiles explicitly. A missing seam is never skip-success or a
fixture substitute.
