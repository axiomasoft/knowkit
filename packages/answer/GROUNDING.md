# Bounded grounded answering v1

`GroundedAnswerService` implements one `GroundedAnswering::answer` invocation.
It uses the existing admission, invocation ledger, managed payload storage and
fresh replay authorization. It owns no transcript, agent loop or second journal.
Hosts inject the core Retrieval/Disclosure use cases, exact retrieval profile,
current InvocationAuthorization, EgressPolicy, AnswerBudget and ModelTransport.
The production transport still requires explicit host HTTP/credential configuration.

1. Admit by tenant/key before resolving mutable model settings. Existing completed
   work goes through `InvocationReplay`; sending/unknown work cannot dispatch.
   Reserved work requires its owning recovery, rather than silent recomposition.
2. Retrieve with the pinned profile through the configured core reader and call
   fresh Disclosure with a new attempt ID. A reference-only selection or historical
   evidence is insufficient. The adapter requires committed usage metadata, matching
   full Scope, snapshot, selected source/version/revision/digests and canonical bytes.
   Empty retrieval completes `NoEvidence` before budget reservation or model IO.
3. Compose the complete OpenAI-compatible JSON body, including pinned settings.
   Template `grounding/1` has one fixed system message. History retains its typed
   user/assistant roles; evidence metadata, exact canonical text and question are
   separate data messages. No supplied content enters the system message. No
   whitespace normalization, implicit history omission or byte/token approximation
   occurs. Host AnswerLimits and pinned model windows apply to the complete body.
4. Check current history attestation, host/source/lifecycle/audience authority and
   whole-content egress. The manifest classifies every content message, including
   QUESTION, HISTORY (also when empty), EVIDENCE and SYSTEM_INSTRUCTION. Its digest
   binds the complete request-byte hash through ModelCallIdentity, including wire
   punctuation/settings, plus exact provider/account/region/model destination.
   Only the injected serializer constructs the body; the transport verifies it again.
5. Persist the actual initial egress receipt and manifest as managed payloads.
   The final ModelCallIdentity references that receipt's real check time, policy
   binding version and expiry; the receipt preserves the opaque owner revision.
   A preliminary identity is used solely to obtain this receipt
   for the same exact outbound bytes; the final identity is checked again before
   reservation. These preparation checks do not dispatch or reserve budget.
6. Reserve by stable tenant/invocation key. Denied, unknown or thrown reservation
   results block dispatch. Durably reserve the exact request, check cancellation,
   then commit markSending. Cancellation after reserve and before markSending
   completes without creating sending intent.
7. After markSending, repeat current history/host/source authority and whole-content
   egress for the final identity. Persist this actual final receipt, assert the live
   claim, recheck cancellation/expiry, and call ModelTransport.send once. A local
   denial completes known-not-sent and releases only the confirmed unused reservation.
   An uncertain markSending commit, lost lease, thrown transport or partial/wrong-model
   observation remains unknown and retains the reservation. Recovery never resends.
8. Persist raw final observation, exact request/call hashes, final egress-check ref,
   reservation key and actual nullable usage before returning content or settling.
   Known completion with missing usage passes null observations to the budget owner;
   unknown dispatch never implies zero usage or a refund. Observation/response
   persistence failure returns TransportUnknown with no plaintext or regeneration.
9. Validate the final envelope and citations, durably complete the outcome, then
   settle by reservation key. Answered bytes pass fresh replay authorization before
   publication. A failed budget settlement preserves the captured response; replay
   retries settlement against that observation, without another model invocation.

The model output protocol is exactly `{"text":"...","citations":[CitationRef,...]}`.
`CitationValidator` accepts a nonempty unique subset of the disclosed citation
objects. Every field, including tenant, source/version, source/IR/content digests,
document revision/identity, origin, supporting refs, assets and ordered spans must
match the immutable disclosed reference. Narrowing/replacing spans or redirecting
to a latest revision is refused. Schema/provenance/span validity does not establish
entailment or semantic correctness. Invalid citations produce
`Refused(invalid_citation)` with the real usage observation and no repair call.
Malformed/oversize output produces a metadata-only refusal. Partial or wrong-model
output remains TransportUnknown.

Invalid explicit input token configuration remains a typed
`DomainFailure(Incompatible, tokenizer_required|tokenizer_mismatch|unsupported_capability)`;
no request is sent. Token budgets use only the pinned exact local tokenizer.
Absent input token budgets leave byte ceilings active. Provider output tokens are
bounded by pinned generation settings and any observed usage; a configured exact
tokenizer also verifies the output locally. No tokenizer is inferred from a model name.

`StaticCeilingBudget` enforces configured input/output ceilings, rejects unknown
token estimates when a token ceiling exists, and provides tenant-bound stable refs.
It intentionally has no monetary state. A host requiring financial accounting owns
its durable budget implementation and reconciliation. Captured unknown observations
remain managed payloads linked to invocation/call identities; their usage ref is
returned to the caller for owning reconciliation. A terminal unknown replay is
metadata-only and does not claim a new observation or publish any bytes.

The host must bind GroundingEvidenceReader's core use cases to its declared exact
profile and supply authoritative current lifecycle/history/audience decisions.
The second check uses the freshly disclosed policy binding; epoch drift denies
rather than silently substituting a profile, receipt or scope. External policy can
change after its last check. Persisted receipt times/versions document that
distributed race; these checks do not claim global atomicity.

Offline fixtures and required IDs live in
`tests/fixtures/answer/grounding/v1/cases.json` and
`tests/fixtures/check-groups/answer-grounding.json`. The existing `answer:grounding`
QA route validates every mapped executed test and rejects missing, skipped, failed,
duplicate and zero cases. Targeted proofs are the individual GroundingTest,
SafetyTest and FixtureTest files. No provider credentials or paid calls are needed.
