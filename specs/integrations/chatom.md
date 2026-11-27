# Optional Chatom route contract

`knowkit/chatom` (`KnowKit\Chatom\`) is an explicit optional Composer project.
It requires PHP >=8.4, `axiomasoft/chatom` and Illuminate contracts ^13.0, plus
KnowKit core and answer. It does not require `knowkit/laravel`. The installed
native Chatom package itself requires Laravel ^13.0. Root tooling and core do
not acquire these dependencies. The PHP 8.3 core lane remains independent.

## Installation and qualification

From `packages/chatom`, run `composer install --no-interaction --no-scripts`.
This development install uses relative path repositories for KnowKit core and
answer and an explicit native Chatom package definition with a relative path
distribution from the existing sibling `chatom/packages/core` checkout, copied
into this project's ignored vendor directory. Its runtime requirements match
the native package; no native auto-discovery or scripts are activated by this
local contract profile. The lock pins native Chatom revision
`909d11e148ec9991bc83527891f8c3b4f5552f8f`; contract hashes in the versioned corpus
qualify its exact SPI. Composer path installations require that checkout at the
pinned revision; Composer does not fetch a git revision for a path repository.
No external repository is modified. A host supplies its own repository mapping
and qualifies the same native contracts and resolved lock. This is local
contract qualification, with `fixture=true`, **HOST QUALIFICATION PENDING**;
it does not establish production installation, provider readiness or rollout.

The package has no scripts, autoload files, Laravel auto-discovery, service
provider, listener, migrations or install hook. Merely installing/autoloading
it registers no KnowKit listener and makes no model call. Missing host resolver
or registration returns metadata-only `NotHandled(missing_configuration)`.
There is no readiness probe, retry, repair call or provider fallback.

## Composition and immutable pins

`KnowKit\Domain\ConversationRoute` is plain core enum data:
`caller_managed`, `direct_chatom`, `agentools_orchestrated`. It grants no access.
Core imports no framework, Chatom, answer or Agentools types.

The host constructs **one complete** `RouteRegistrationAssertion` containing
every caller, direct handler and Agentools relay registration before installing
any of them. Registration key is the tuple
`(tenant, space, channel, bot, binding)`, encoded without delimiter ambiguity.
Every configured binding has exactly one owner: any second entry is rejected,
including an identical owner or two instances of the same route. No silent
deduplication, last-writer wins or per-package private assertion is permitted.
Unconfigured bindings have no active owner. Freeze this composition before
listening; an intentional replacement is a new host composition, not a second
relay registered during delivery. Both route packages may coexist for distinct
bots/bindings, including two bots in the same channel.

The host implements `ConversationBindingResolver::resolve(ConversationRef)`
and returns the current immutable `ConversationBinding`: exact conversation,
route, `kbId`, `ModelBindingRef`, full trusted `Scope` as `hostScopeInputs`,
`viewerBindingRef`, opaque `bindingRevision`, `InstanceId` (`instanceId`), and
optional `?LayerStackRef` (`layerStackRef = null`). The scope retains tenant, kb,
actor, business purpose, policy epoch and `hostScopeHash`; it is never rebuilt
from model input. Business purpose remains distinct from `Operation`.
The host's hash preserves acting-as, learner, audience, consent, session and
other host facts. Tenant/kb mismatches are invalid bindings. Missing/unknown
authority throws; the guard returns `NotHandled(binding_unavailable)` with no
exception text or protected binding. `KnowKitDurableAgentHandler` forwards
`instanceId` and `layerStackRef` from `ConversationBinding` to
`ConversationAnswerFactory::create(...)` without owning history or writing to
Chatom tables (`ARCH-CHATOM`).

`ConversationRouteGuard::check(conversation, route, expectedBinding?)` checks
the frozen registration before consulting the resolver. Registered-route
mismatch returns `NotHandled(route_mismatch)` with zero resolver IO. A current
host route or conversation mismatch also returns `route_mismatch`. All these
paths expose no binding and must return before history, ledger, model, reply or
native mutation effects. On a match the guard returns the host snapshot; it
never selects a model or expands access. The host must invoke only the owner
whose route it registered; the guard is not a cross-process delivery lock.

Save that admitted snapshot, then pass it as `expectedBinding` at later owner
boundaries. Every field, including revision, model handle, viewer, actor,
purpose, policy epoch and host hash, must remain equal. A changed pin returns
`NotHandled(changed_binding)`; it cannot substitute a learner or adopt a newly
configured model for old work. Resolution exceptions fail closed. This guard
does not replace current access, lifecycle, egress or native write-policy checks.
It provides no global transaction across independently owned services.

## Runtime owners and subsequent direct adapter

P7.4 supplies binding/registration contracts and guards. The direct handler,
history hydration and reply mapper are subsequent runtime work, not an extra
model loop in this package. They must use the installed real SPIs:
`Chatom\Agents\Contracts\DurableAgentHandler`, `DurableAgentReply`,
`Chatom\Messages\Contracts\ViewerMessageReader::history(ViewerMessageHistory)`.
Native `invocationKey` passes unchanged to answer `InvocationKey`. History uses
the current viewer and bounded order; raw snapshots do not authorize history.
Check bot authority and intended learner audience before model send; recheck
current channel/assistant/learner/class/lesson/consent/rights/source revisions
after owner IO and immediately at the native mutation boundary. Unavailable
facts deny. Historical evidence or a saved answer grants no current access.

Publish an immutable checked answer through semantic slot `final.v1` of
`DurableAgentReply`; native Chatom owns membership, write policy, receipts and
deduplication. Answer owns model admission/ledger and transport. Streaming is
false. Lost ACK recovery reuses stored slot/content/key without generation;
same key with changed content conflicts. Citations keep immutable
source/version/IR/span identity and navigate through fresh host authorization.
Model changes cannot rewrite old replies or re-embed corpus indices.

`agentools_orchestrated` exclusively uses the existing native
IncomingConsumer/IncomingDelivery → PostgresInbox → intake/Turn/Run/wake and
Agentools context/model/budget/effect/delivery owners. No direct handler or
answer ledger is installed on that binding. `knowkit/chatom` never imports the
Agentools bridge; that bridge must never import answer or Chatom adapter code.
No Chatom persistence, native relay, Agentools journal or runtime is copied.

## Executable contract corpus

`tests/fixtures/chatom/routes/v1/cases.json` is versioned synthetic data.
`tests/fixtures/check-groups/chatom-contracts.json` binds all six required IDs
to actual test methods. The existing QA runner rejects missing, skipped,
failed and zero cases and verifies fixture and lock digests. PHPUnit's Chatom
suite points only at physically present contract tests. The coordinator runs
`composer qa -- chatom:contracts` against this package's locked install.
These tests qualify route composition and binding guards, not a live model,
production Chatom database or the subsequent direct handler implementation.
