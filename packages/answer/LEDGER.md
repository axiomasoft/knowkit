# Direct answer invocation ledger v1

Apply core migrations, then explicitly call `PostgresAnswerMigrations::apply()`
with the same schema and an administrative connection. Runtime uses a restricted
role, a tenant-bound connection and a unique worker reference per claimant.
No constructor installs tables or contacts a provider.

`InvocationAdmission::admit()` first reads `(tenant, invocationKey)`. An existing
row compares the original request digest and the fingerprint using its saved
binding. A new row resolves the host binding outside SQL, saves managed pins,
then inserts with `ON CONFLICT DO NOTHING`. A concurrent loser adopts the winner
only for the identical original request. Discarded resolutions cannot dispatch.
The fingerprint is the core `answerInvocationFingerprint` domain, a zero byte
and JCS of every original field plus the opaque pinned binding digest. Full
Scope includes business purpose, policy epoch and host audience hash.

`preparing -> reserved -> sending -> completed` is versioned and fenced.
Pre-send work may complete a known-not-sent outcome. `reserve()` stores the exact
outbound bytes and source/history dependencies with a confirmed host budget
reservation. `markSending()` saves the **complete** `ModelCallIdentity`, verifies
its raw request hash, binding, question digest and dependency set, and commits
before dispatch. It also pins the entire egress manifest in that managed record.
There is no transaction around policy, blob or model I/O.

`InvocationDispatch` repeats current host/source/history/whole-content egress
authorization after intent. The host authorization implementation must check
current credentials, policy and capability validity for the pinned deployment;
it must never resolve a replacement deployment. A local denial completes
known-not-sent without calling transport. Only a caller that actually observed
the dispatch result may call `complete()` with a sent outcome. Completion never
settles a budget speculatively: the host budget owner settles captured usage,
or releases only confirmed unused reservations. This ledger retains its original
reservation receipt as immutable technical evidence.

An expired preparing/reserved claim can resume with a fresh authorization and
higher fence through `InvocationRecovery`. An expired sending claim becomes
`unknown`; explicit transport uncertainty also becomes unknown. Unknown and
completed are immutable terminal records. A response received only in process
memory is lost on crash: it cannot authorize regeneration or a second dispatch.
This is at-most-one local dispatch, not universal exactly-once provider execution.
There is no automatic reconciliation, repair call, fallback or retry.

`InvocationReplay` checks the original identity, uses the saved pins, checks
current host audience and calls fresh authoritative source/history/lifecycle
and egress authorization **before** reading the response payload, then again
after blob I/O, immediately before disclosing an answer. Current policy epoch
can differ in the authorization scope without rewriting the admitted
request. A denial returns only metadata and leaves the completed row untouched.
Replay metadata retains the saved dispatch certainty; unavailable pins report
uncertainty rather than claiming that generation was never sent.
TransportUnknown never materializes a response. Delivery failure cannot rewind
generation. A new key is a separately host-admitted operation.

`PostgresInvocationPayloads` reuses core ResourceLedger, Upload and BlobStore.
A host-configured Source owns payloads. Reservation and retention exist before
PUT; writer uncertainty, digest/length checks and immutable storage versions use
the core protocol. SQL holds only tenant-bound resource references, hashes,
retention and immutable dependency metadata; no prompt, answer or history text.
Exact source/version/IR/span identities and history revision/digest remain in
the dependency manifest, which is available for current host authorization and
retention/deletion planning. Pending and abandoned payload reservations remain
discoverable managed obligations. Core source deletion enumerates these owned
resources; dependencies are also retained on the payload record for host history
or cross-source deletion planning. A hold is never an access grant. Expired,
unaccepted or physically purged payloads fail closed; ledger metadata does not
recreate their bytes.

Fixtures are synthetic, versioned in `tests/fixtures/answer/ledger/v1/cases.json`.
The required-case manifest binds every required scenario to concrete PHPUnit
methods. The existing QA runner rejects missing, skipped, failed or zero cases.
No production transport or provider readiness probe is introduced here.
