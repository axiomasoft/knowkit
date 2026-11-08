# Optional answer contracts (P4.7)

`knowkit/answer` requires PHP >=8.3, `knowkit/core`, PSR-18 client and
PSR-17 factory interfaces. Install from its own Composer project and committed
`packages/answer/composer.lock`; run `composer install --working-dir=packages/answer`.
The root tooling dependency graph and core remain unchanged. No Laravel,
Agentools, Chatom or provider SDK is required by this package.

This milestone supplies immutable domain values, ports, offline configuration
readiness and a test fixture transport. `GroundedAnswering` is the public
application **interface**, not a configured generation service.
Production HTTP transport, retrieval/disclosure/egress orchestration, SQL
invocation storage and managed payload custody require their later runtime
implementations. No paid provider or successful grounded generation is claimed
by these contract tests.

## Public invocation

`GroundedAnswering::answer(AnswerRequest, CancellationToken): AnswerOutcome`
means one bounded invocation. There is no agent/tool loop, automatic retry,
repair request, provider fallback or transcript store.

`AnswerRequest` contains exactly:

- trusted core `Scope`;
- UTF-8 `question`;
- `BoundedTurns`;
- host `ModelBindingRef`;
- immutable retrieval `ProfileRef(id, revision, digest)`;
- opaque host `InvocationKey`;
- `AnswerLimits`.

Scope retains tenant, KB, actor, namespaced business purpose, policy epoch and
opaque trusted `hostScopeHash`. Business purpose is distinct from core
`Operation` (retrieve, disclose, publish, model egress, navigate).
The host hash preserves actor/acting-as/subject/audience/consent/session/data
scope without importing host runtime types. Binding scope comparison checks
all six fields. A history receipt, prior answer, evidence or immutable pin is
never a current access grant.

Defaults are hard ceilings; a host may lower them.

| Boundary | Default ceiling |
| --- | --- |
| Question | 4096 UTF-8 bytes |
| Prior turns | 12 turns and 16384 text bytes |
| Complete serialized model input | 32768 UTF-8 bytes |
| Evidence context | 16384 UTF-8 bytes |
| Model output | 2048 tokens and 65536 UTF-8 bytes |
| Invocation deadline | 120 seconds |

Byte limits use `strlen`, reject invalid UTF-8 and never truncate, normalize,
clamp or silently drop history. Prior byte size sums turn text bytes; the
complete serialized input additionally covers roles, policy, template and
framing bytes. Prior turns accept only user/assistant roles and remain
untrusted supplied data. Their ordered digest hashes JCS of role/text pairs.

`BoundedTurns::requireCurrentAttestation` requires a trusted host
`HistoryReadAttestation` for every nonempty slice. It checks exact ordered
content digest, the complete Scope and expiry. The runtime must obtain this
receipt from the owning history reader and repeat current source/history/
audience/lifecycle checks before dispatch and replay; a caller cannot supply
authority by merely supplying text or constructing an attestation DTO.
Empty history is allowed only as the explicitly admitted request.

`AnswerLimits::assertInput/assertOutput` require core `Tokenizer` and its exact
pinned ID, version and vocabulary digest whenever checking a token budget.
Missing tokenizer yields `incompatible(tokenizer_required)`; inexact or
mismatched capabilities reject without estimation, network fallback or bytes/4.
Byte budgets remain active without token budgets.
Output's default 2048-token ceiling is a token budget too; constructing a DTO
does not constitute output validation.
`ResolvedModelBinding::assertInput` additionally enforces configured input and
context-window ceilings, including the pinned maximum output reservation.
Token counts are exact for the passed UTF-8 bytes and pinned tokenizer;
provider usage observations remain separate and nullable.

## Immutable model deployment and identities

`ModelBindingResolver::resolve(Scope, ModelBindingRef)` returns a
`ResolvedModelBinding`: complete Scope and requested handle; provider, account,
region, model and model revision; transport ID/version/production metadata;
capability snapshot digest, expiry, text-generation support and optional token
windows/tokenizer; credential handle and presence; exact generation settings;
and opaque host `bindingDigest`. Generation sampling decimals are represented
as decimal strings, preserving core's integer-only JCS profile. The host must
supply a new immutable digest for a changed deployment. The package never
recomputes or re-tags the trusted digest.

A binding contains no credential values or discovered endpoint. Runtime
`CredentialResolver` rechecks the current credential for this pinned deployment.
`Credential` is ephemeral, redacts debug output and refuses serialization.
Credential rotation must not substitute another provider, account, region or
model. Corpus embedding `EmbeddingSpace` remains independent: changing chat
bindings cannot alter existing corpus identity or embedding configuration.

Ledger lookup is unique by `InvocationRef(tenant, InvocationKey)` and precedes
mutable binding resolution. Existing admissions compare
`AnswerRequest::originalRequestDigest()` against the incoming original fields
and load their pinned binding/profile. A concurrent first-admission loser
reloads the winner and compares the original request; it never sends its
discarded resolution.

The original digest is SHA-256 of UTF-8 JCS of the original identity.
Invocation key is the separate unique lookup key, not an original payload
field. The request fingerprint is exactly:

`SHA256(ASCII("knowkit:answer-invocation:v1") || 0x00 || UTF8(JCS(payload)))`.

Payload fields are `operationVersion="answer:v1"`, `tenant`, `kb`, `actor`,
`businessPurpose`, `policyEpoch`, `hostScopeHash`, `questionDigest`,
`orderedTurnsDigest`, `requestedBindingRef`, `pinnedBindingDigest`,
`exactRetrievalProfileRef` and `limits`. The original digest omits only
`pinnedBindingDigest`. Attestation refresh does not change the admitted slice.
Secrets and temporary URLs never enter these identities.
Fixtures contain independently calculated Python golden payloads and hashes.

Before `markSending`, runtime must persist `ModelCallIdentity`, covering raw
complete serialized model-body SHA-256, ordered typed role/content hashes,
template ID/version/digest, entire pinned deployment/settings, egress decision
reference/check time/version/expiry, immutable evidence/history dependencies,
tenant/key and deadline. Credential-bearing HTTP headers are separately
resolved and are not the serialized model body or audit data.
Identity's metadata digest is SHA-256(JCS(metadata)); only ledger admission
uses the answer-invocation domain. `TransportRequest` refuses body bytes whose
raw SHA-256 differs from the persisted call identity. Composer/transport
implementations must ensure role hashes and dependency hashes describe those
exact bytes; DTO construction does not establish policy authorization.

Immediately before actual send, after durable `markSending`, runtime must
repeat fresh source/history/host-audience and whole-content egress checks for
QUESTION, history and evidence, preserving exact destination and provenance.
Unknown policy fails closed. A proven local no-dispatch denial may complete as
knownNotSent and release only a confirmed unused reservation; a crash in sending
remains unknown. Record the observed check version/time and the distributed
race with external permission owners; no global atomicity is claimed.

## Outcomes and ports

`AnswerOutcome` is a closed tagged union with `type`:

- `Answered{text,citations,modelBindingDigest,evidenceSetId,usageRef}`;
- `NoEvidence`;
- `Refused(reason)`;
- `Denied(reason)`;
- `EgressDenied`;
- `BudgetDenied`;
- `Cancelled{sent}`;
- `Unavailable(component)`;
- `TransportUnknown`;
- `PolicyUnknown`.

Every variant carries invocationRef, dispatch certainty, optional observed
model and usage refs, and fixture flag. Only Answered accepts
`AnsweredContent`; every other variant excludes protected text and citations.
Reasons/components are typed machine enums. Answered requires known dispatch,
usage reference and exact core CitationRef values, including source/version/
document revision/IR/span identities. `evidenceSetId` is the core EvidenceSet's
SHA-256 identity (`EvidenceSet::digest()`), not a reference to latest.
Citation validation must check exact disclosed evidence membership, digests and
bounded spans; it does not claim entailment. Invalid citations produce
Refused(invalid_citation) with observed usage and no repair request.

`TransportResult` is a provider observation, never Answered. Partial or unknown
output cannot contain text. Ambiguous cancellation is TransportUnknown;
Cancelled.sent is only a known dispatch fact. Unknown token usage stays null.

`ModelTransport` exposes offline descriptor metadata and one `send`.
`ModelBindingResolver`, `AnswerBudget`, `InvocationLedger` and
`CredentialResolver` are explicitly injected host ports.
Budget reserve takes the opaque InvocationKey and Estimate carrying the full
trusted Scope, so the host's stable reservation key remains tenant-bound. Unknown reservation
prevents send. Commit settles observed nullable usage; release is restricted to
confirmed unused reservations. Unknown/lost ACK retains the reservation for
owner reconciliation. Settlement retry must never repeat model generation.

Ledger begin(key, fingerprint, pins) retains originalRequestDigest separately
inside the admission pins. The ledger contract is preparing → reserved → sending → completed, with
sending → unknown terminal for dispatch. Mutations require current row version,
live lease and fencing epoch; no transaction surrounds external IO.
markSending commits the exact outbound identity. Response bytes belong to
managed immutable tenant-bound payload storage, not plaintext ledger columns.
The port and checkpoint DTOs do not implement PostgreSQL durability or crash
recovery; those are separate qualification work.

Completed replay is a new plaintext disclosure. Before loading saved bytes,
verify full scope, current actor/audience/consent/business authority,
source/history lifecycle, every dependency and output/publication policy;
recheck egress for external delivery. Denied/unknown replay is metadata-only
and leaves the immutable prior outcome unchanged. Never resolve a changed
tutor setting or resend a completed/unknown invocation.

## Offline registry and fixture qualification

`Profile\\EffectiveProfile` requires explicit transport metadata and local
client/credential configuration. A non-test profile rejects every descriptor
with production=false before resolution/dispatch.
`ModelTransportRegistry::readiness` checks registered exact metadata and local
presence flags only; it never calls send, factories, credential resolution,
binding resolution, network discovery or paid readiness probes.
Missing configuration maps to Unavailable with knownNotSent and no protected
payload. Configured readiness is not a statement about remote availability.

`Testing\\ScriptedTransport` requires an explicit Test profile, declares
production=false, returns fixture=true even when supplied observations omitted
it, and never retries or falls back after an exhausted script. Pre-send
cancellation consumes neither script nor outbound bytes. This fixture transport
does not qualify a production provider, ledger or access-policy implementation.

The versioned corpus is `tests/fixtures/answer/contracts/v1/corpus.json`.
`tests/fixtures/check-groups/answer-contracts.json` maps all six required IDs
to actual PHPUnit identities; the QA runner rejects missing, skipped, failed,
duplicate and zero executed tests. The isolated Answer suite contains only
existing contract test directories. Scoped tests run using the optional
package's locked Composer install and the root PHPUnit configuration.
Reuse qualification is recorded in
`tests/answer/contracts/evidence/P4.7-reuse.json`.
