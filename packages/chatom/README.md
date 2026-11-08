# Direct Chatom durable handler

`KnowKitDurableAgentHandler` implements the installed Chatom `DurableAgentHandler`.
This is explicit optional host composition; nothing installs a listener or chooses
a provider automatically. Native PHP 8.4+/Laravel 13 contracts are pinned by this
package's lock and the existing contract fixtures. The handler advertises
`streaming() === false` and emits one buffered immutable `final.v1` reply.

Configure a `HandlerConfiguration` for one complete tenant/space/channel/bot/binding
tuple, native handler key/version, exact retrieval profile and bounded limits.
`botId` is the complete serialized native ActorRef (for example `bot:tutor`).
The native tenant key must match the host's trusted KnowKit TenantId; an absent
or unmapped tenant is refused. Register exactly one route owner with the existing
route guard. Caller-managed and Agentools bindings never enter this handler.

The host implements `ConversationAudience` using current authoritative identity,
assistant/channel, learner, audience, class, lesson, consent, rights and source
services. `viewer()` resolves the intended current viewer from `viewerBindingRef`,
never from bot membership or trigger content. `attest()` rechecks the exact native
projections and every historical assistant source after reader I/O, returning a
short-lived `HistoryReadAttestation` for the full trusted Scope, ordered turns
digest and `AuthorizedHistory::manifestRef()`. That manifest binds native message
IDs/positions/revisions/body hashes, viewer and host binding pins. Missing, stale,
denied and unavailable facts throw. `assertCurrent()` repeats these checks for all
history and supplied model/evidence dependencies; old receipts are never grants.
The full hostScopeHash and business purpose survive unchanged.

`ChatomHistoryHydrator` reads only `ViewerMessageReader::history()` with the current
viewer, descending native position and a single bounded 100-message page. It
requires the current authorized trigger to match its accepted ID/sender/position/
revision, uses its projected body as the question and selects up to 12 prior turns
within the explicit byte limit. Later messages are excluded. If the trigger has
fallen out of that bounded window, hydration denies; it never falls back to the
raw snapshot. History limits are chosen before admission, never as a retry repair.

Implement `ConversationAnswerFactory::create()` by composing the existing
`GroundedAnswerService` and `InvocationReplay` with the supplied
`ChatomInvocationAuthorization` in both authorization slots, sharing the existing
answer ledger, payloads, model transport and budget across invocations/retries.
Inject that same `InvocationLedger` into the handler. Before mapping a reply it
compares the checked outcome's digest with the ledger's saved immutable response
and reads the complete dependency manifest (including evidence not cited in the
answer), then reauthorizes that manifest at the native write boundary.
Additional host checks must be combined with this guard. The guard runs at the
answer owner's final beforeSend/beforeReplay boundaries. Whole-content egress
remains the existing answer service's obligation. Native `invocationKey` is passed
unchanged, without using Chatom's prefixed `idempotencyKey`. Binding/history manifest
changes deny replay, including empty prior history; changed question/history body
conflicts in the existing ledger before another model call or reply proposal.

Install **the same request-scoped `ChatomReplyBoundary` instance** as Chatom's
`ChannelAccess` decorator and in this handler. Its delegate is the existing native
ChannelAccess. This explicit Laravel composition is required: it is not an optional
check. It delegates native membership/write authorization first, then rechecks
the pinned binding, learner/audience/history and source dependencies in native
`reauthorize()` before message persistence. It forwards unrelated operations to
the native owner. Do not share the instance between concurrent requests/fibers;
the guard exists only within the synchronous `DurableAgentReply::send()` call and
is cleared in `finally`, including lost ACK. No Chatom tables are read or written
by KnowKit. Chatom retains its delivery token, write policy, receipts and slot
deduplication. The check time/revisions belong to the authoritative host; remote
owner revocation after its last check remains a distributed race, not a globally
atomic guarantee.

The mapper is deterministic: checked answer text and `knowkit:citation:<citationId>`
locators, with no model-provided extension nodes, raw source locators or preview
payloads. The host routes each locator to `ChatomCitationGateway` with a fresh
`CitationViewerContext`, current viewer, bounded `ReadRequest` and fresh attempt ID.
The existing core Readings service rechecks access/lifecycle after object I/O,
preserves exact source/version/IR/span identities and never redirects to latest.
The citation ID is an opaque locator, never an access token.

On lost native ACK the owning delivery redrives the same invocation. Rehydrate and
authorize current facts, replay the saved immutable answer through the answer
ledger, map the same body and call the same `final.v1` slot. Do not regenerate,
look up a completed receipt as permission, revise old replies, or retry internally.
Native Chatom intentionally keeps the first slot body; changed-body detection is
the answer ledger's responsibility before reaching that API.

The versioned handler fixtures execute real native interfaces and the existing
answer application with a synthetic host/ledger contract. The citation fixture
also exercises the real PostgreSQL core reader. They are `fixture=true`,
**HOST QUALIFICATION PENDING**. Installed host pipeline/tenant mapping and
Chatom PostgreSQL 17/18 qualification belong to the separate host lane; these
tests make no live provider or Edufy rollout claim.
