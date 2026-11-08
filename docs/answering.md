# Bounded grounded answers and safe replay

`knowkit/answer` is optional. Core has no dependency on it, Chatom, Agentools or
a framework. `GroundedAnswering::answer()` accepts one bounded invocation; it
does not run tools or own a chat transcript. Production requires an explicitly
configured host transport and credential resolver. Missing configuration does
not trigger discovery, fallback, retries or a paid readiness probe.

## Admission and immutable model identity

The trusted host supplies the complete `Scope`, including the opaque
`hostScopeHash` for actor/acting-as/subject/audience/consent/session/data scope.
The business purpose is separate from `Operation`. Never derive either from
question or model content.

Admission first looks up `(tenant, InvocationKey)`. An existing key compares the
original request and uses its immutable binding/profile pins without resolving
the tutor's current model. A changed original request conflicts. First admission
resolves outside SQL, then insert-CAS chooses one winner. A concurrent loser
adopts that winner's pins, discards its own resolution and cannot dispatch under
the winner's lease. A changed tutor model affects only a newly admitted key.

Before `markSending`, the exact outbound bytes, typed message hashes, template,
deployment/settings, evidence/history dependencies, egress receipt and deadline
are bound by `ModelCallIdentity`. The request's SHA-256 is the hash of raw
serialized bytes; the ledger admission fingerprint uses its own specified
domain. No database transaction surrounds model, blob or owner-policy I/O.

## Completed replay is a fresh disclosure

Historical answers and receipts never grant current access. `InvocationReplay`
checks the original identity and the same tenant, KB, actor, business purpose
and `hostScopeHash`. Its injected `InvocationAuthorization::beforeReplay` must
recheck current host actor/subject/audience/lesson authority, consent, output or
publication policy, history visibility and every source dependency, including
tombstones and the exact source version's usability. A policy epoch change
requires current authorization; an old stored epoch is not permission.

The first authorization happens **before response payload loading**. After blob
I/O, an answered response gets another current check immediately before return.
A denied, revoked or unknown decision returns only metadata, retaining truthful
historical dispatch certainty. A thrown owner-policy error becomes
`PolicyUnknown`. The completed row, response reference/hash, original citations
and model identity remain unchanged. Missing, expired or purged bytes cannot be
resurrected by historical metadata.

The host must put its current external replay destination and output/publication
policy into the trusted authorization context. Before forwarding externally it
must check outbound rights for that destination and those bytes. A model egress
receipt from generation is not permission to forward the saved answer. A host
adapter must implement core disclosure/usage linearization with the prescribed
tenant → KB → source locks; a permissive callback is not a production adapter.

Owner checks have actual timestamps, expiry and revisions. The final check and
an external recipient's delivery cannot be globally atomic. Revocation after
the disclosure linearization point cannot retract already prepared bytes. Call
the authorization/disclosure boundary at each actual new publication or send.

## Crash and cancellation boundaries

| Durable boundary before process loss | Recovery |
| --- | --- |
| Preparing/reserved, before `markSending` | No dispatch occurred. An expired lease may get a higher fence after fresh authorization. |
| `markSending` committed, before or during transport | Possible dispatch: `TransportUnknown`; never resend. |
| Provider reply observed, response not committed | `TransportUnknown`; preserve observations for owning reconciliation, never regenerate. |
| Completed, caller ACK lost | Fresh authorization may replay the identical saved payload; no new model call. |

This guarantees at most one local dispatch under the ledger contract, not
universal exactly-once provider completion. Unknown work never becomes sendable.
A new key is an explicit host-admitted operation, not an automatic repair.

Pre-dispatch cancellation is `Cancelled{sent:false}` only when the local guard
proves no send occurred. Known dispatch followed by cancellation records
`sent:true` and observed usage. Partial or ambiguous dispatch remains
`TransportUnknown`. Never invent zero usage or release possibly spent funds.
Captured responses survive settlement failures. Retry `AnswerSettlement` by the
same reservation key; the public service's completed replay path does this
without invoking retrieval, mutable model resolution or generation again.

Apply `PostgresAnswerMigrations` explicitly after core migrations. Additive
`answer:002` permits immutable `observation` and `egress-check` payloads used by
the grounded flow. It preserves the installed `answer:001` checksum and does
not weaken immutable payload triggers.

## Caller-managed turns

[The plain PHP caller](../examples/plain-php/grounded-answer.php) accepts an
injected, host-configured `GroundedAnswering`. The caller selects its prior turns
before admission and supplies a current `HistoryReadAttestation` for the same
scope and ordered turns digest. The host's owning history reader must validate
their underlying dependencies and current audience. An old assistant turn may
contain revoked source text even when today's retrieved evidence is allowed.

KnowKit neither appends turns nor stores a conversation. Managed invocation
payloads contain only bounded operational request/response bytes subject to
retention and dependency tracking. The caller separately owns chat hydration,
publication and persistence. It may explicitly admit a request without history;
it may not silently drop denied history on retry under an existing key.

## Conformance fixture v1

The [versioned fixture](../tests/fixtures/answer/replay/v1/cases.json) and
[required-case manifest](../tests/fixtures/check-groups/answer-conformance.json)
bind all ten required IDs to executed PHPUnit methods. The existing QA runner
rejects missing, skipped, failed, duplicate and zero cases. Its negative
self-check is retained in `tests/answer/conformance/FixtureTest.php`.

Independent PHP processes use separate PostgreSQL sessions and bounded pipe
barriers. Both duplicate contenders resolve before either insert-CAS; different
candidate binding digests prove that only the winner's pin survives. Other
schedules kill the process at reserved, sending, reply-observed and completed
barriers. SQL's clock determines lease expiry. `ScriptedTransport` counts actual
sends and body bytes. Current actor/audience/consent/history policy, source
correction, revocation and tombstone mutations commit in another process.
Payload spies prove denial before response reads and recheck after a concurrent
revoke during blob I/O. The public grounded flow also runs with real PostgreSQL
ledger/payload adapters, including egress receipt and observation persistence.

Run individual files for diagnosis, for example:

```sh
packages/answer/vendor/bin/phpunit --configuration phpunit.xml tests/answer/conformance/ReplayTest.php
packages/answer/vendor/bin/phpunit --configuration phpunit.xml tests/concurrency/answer/ConcurrencyTest.php
```

The coordinator runs the complete `answer:conformance` group and required-case
coverage check. The schoolA/schoolB and separate `support.read` tenant fixtures
use the same local invocation key to demonstrate isolation and generic reuse.
All scripted outcomes are `fixture=true`: **HOST QUALIFICATION PENDING**.
These tests qualify core/answer behavior with synthetic owner state, not actual
Edufy Library originals, Content↔Files callbacks, owner HTTP, installed host
versions, Chatom publication or any paid provider. No external host repository
or application state changes are needed.
