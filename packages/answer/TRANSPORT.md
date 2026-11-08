# Explicit OpenAI-compatible PSR-18 transport, version 1

`OpenAiCompatibleTransport` is a production adapter with descriptor
`openai-compatible-psr18` / `1`, `production=true`, `requiresCredentials=true`.
It implements exactly one non-streaming chat-completions POST: ordered text
messages with system/user/assistant roles, one choice, pinned `model`,
`max_tokens`, optional temperature/top_p/seed/stop, `stream=false`, and `n=1`.
Tools, JSON-output modes, automatic model selection, streaming answers, retries,
repair, redirects and provider fallback are unsupported. The descriptor version
identifies this exact capability set; it does not certify a host HTTP client.

The host injects PSR-18 client, PSR-17 request/stream factories, CredentialResolver,
Clock, HostHttpPolicy and an explicit immutable Deployment. There is no discovery,
environment-variable lookup or readiness probe. The endpoint is used verbatim.
The host must associate the binding digest with this exact endpoint and client
configuration, and issue a new binding digest when that deployment changes.
Credentials are resolved against the full pinned Scope/binding immediately before
building Authorization; they never enter the model body or identity.

Compose `ChatMessage` values and call `OpenAiRequest::bytes()` **before** admission,
egress authorization, budget reservation and markSending. Store those bytes in
ModelCallIdentity and TransportRequest. `send()` verifies the complete admitted
bytes, each role/content digest, exact settings, binding, current capabilities,
deadline and egress receipt interval. It sends the original bytes, without
reserializing them. Version 1 deliberately accepts only this exact serializer's
wire format: extra fields, duplicate keys and alternate whitespace fail locally.
The separate ChatMessage permits trusted system policy; caller PriorTurn still
cannot supply that role. Question, history and evidence remain untrusted data.

PSR-18 does not expose timeouts, DNS/peer validation, buffering limits or retry
middleware. `HostHttpPolicy::prepare()` must configure **the injected client** for
the supplied HttpLimits, validate the endpoint/provider/account/region allowlist,
validate and pin public DNS results and the connected peer, verify TLS hostname,
control proxy behavior, disable credentials/cookie forwarding, redirects and all
internal retries, and bound response buffering. Unsupported enforcement returns
false without sending or probing. URL syntax checks are supplementary. Default
limits are connect 5s, total 60s, response 131072 bytes, plus AnswerLimits (input
32768 bytes, output 65536 bytes, output 2048 tokens and deadline 120s or lower).
The adapter narrows total timeout to the remaining deadline and also polls a
monotonic elapsed limit, the deadline and cancellation while reading the body.
Host socket enforcement is required to bound blocking send/read calls.
Model input/window token limits require an exact injected core Tokenizer.

Check `readiness()` before admission. It performs no IO and returns a Readiness
whose outcome is Unavailable for missing deployment/policy/client/factory/credential
configuration. `send()` also raises a safe TransportUnavailable before dispatch
for missing configuration or an absent credential. InvocationDispatch completes
this as Unavailable/KnownNotSent, without inventing usage. An unsupported host
policy, stale deadline/capability, cancelled or invalid request returns an
incomplete KnownNotSent observation. Once sendRequest begins, any exception,
timeout, cancellation, incomplete body, HTTP failure or invalid provider response
is an incomplete Unknown observation, never a retry or an Answered outcome.

Only a bounded application/json `chat.completion`, one assistant text choice,
index 0 and finish_reason `stop` can complete. Tool/refusal/partial/SSE output is
Unknown with no answer text. The parser uses core strict JSON: duplicate names,
non-integer numbers, non-finite/unsafe integers, invalid UTF-8 and excess depth
fail closed. Providers returning extra fractional metadata are unsupported by
this minimal response profile. Usage prompt/completion counts are nullable safe
nonnegative integers; absent observations remain null. Provider request IDs and
error bodies are deliberately not exposed. Valid usage and model observations on
a parsed incomplete/wrong-model response are retained. An unexpected model is
observed and rejected as Unknown; it never rewrites the pinned binding. The
adapter returns a transport observation, not an access grant or published answer.

The host composer supplies typed EgressContentItems for QUESTION, HISTORY,
EVIDENCE and SYSTEM_INSTRUCTION. EgressContentManifest requires the question,
history dependencies, every ordered message digest and exact cited evidence
digest/source versions. Even absent history has an explicit HISTORY entry (use
SHA-256 of empty bytes). `toCore()` preserves classifications, digests, exact
source references and provider/account/region/model in core EgressRequest. Its
manifest digest binds both those entries and the entire ModelCallIdentity,
including complete wire digest, template, deadline and full Scope/hostScopeHash.
The core port's source refs carry source/version/digest; the identity retains
the full immutable citation IR/span dependencies and history ref/revision.

Host InvocationAuthorization::beforeSend calls CurrentModelEgress **after** the
durable markSending, alongside authoritative lifecycle, source/history access,
attestation, audience/consent and credential checks. Supply the authoritative
current policy-binding digest. CurrentModelEgress calls core EgressPolicy for the
whole manifest, requires fresh Allow receipts for every exact source-version
resource (or KB when there are no source refs), and denies stale/unknown/error
observations. The host must recheck local binding/lifecycle drift after an
external owner call before returning from beforeSend. InvocationDispatch then
checks the current capability/deadline, claim and cancellation before one send.
Remote owner state can change after its last check: record the actual policy
check/version; this boundary does not claim distributed atomicity.

Offline tests use dev-only Nyholm PSR-7 messages/factories and test-gated
ScriptedHttpClient, with no network backend. The versioned HTTP corpus and
answer-transport required-case manifest bind all nine mandatory IDs to real
PHPUnit cases, including evidence-allowed but QUESTION/history-denied checks
after markSending with zero ModelTransport sends and zero outbound bytes. The
existing QA runner consumes JUnit and rejects missing, failed, skipped, duplicate
or zero executed cases. These fixtures do not qualify a production socket client.
