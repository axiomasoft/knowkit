# Fresh disclosure boundary

`FreshDisclosure` implements the approved `Application\Disclosure` interface.
Its authenticated `HostDisclosureContext` resolves the original query, exact
snapshot and opaque token by selection ID. Identity and original search bindings
never come from request JSON. Replay metadata remains available even after the
host discards its original search bindings.

`Disclosures::disclose()` requires a trusted `AccessContext`, the opaque selection
token, its exact query/snapshot and a validated `DisclosureRequest`. The host alone
supplies optional usage context. A direct call uses a null run reference. Call this
operation immediately before every new send; a historical `EvidenceSet` grants no
permission to send its text again.

The owner decision and bounded canonical object reads happen before BEGIN.
`PdoUsages` takes tenant SHARE, KB SHARE, sorted source SHARE (including dependency
ancestors) and generation SHARE under READ COMMITTED. It checks the current policy
epochs, current membership/collections, exact snapshot and refs, synchronous DAG
usability, selection expiry and receipt freshness using database time. A changed
lock plan is rejected without trying to acquire an earlier lock. The host policy
binding must cover the complete source/ancestor set; incomplete bindings fail
closed. No policy, blob, host or provider callback runs under these guards.

Usage, exact fragment lineage and `usage.prepared_disclosure` outbox fact commit
atomically. SQL never stores document text. Only a confirmed COMMIT releases the
bounded response. An ambiguous COMMIT returns unknown without a payload. A repeated
tenant/attempt key returns `DisclosureReplay` metadata, even after expiry/revoke;
it never returns historical bytes. A new attempt always takes fresh checks.

The immutable v1 wire schema remains unchanged. A prepared `EvidenceSet` exposes
typed `usageRef()` and `checkedAt()` metadata, `trustClass() === 'untrusted'` and
`memoryActivation() === false`; citation origin and supporting refs remain in the
wire payload. A deserialized historical wire value has no fresh checked time.
`searchRepresentations()` exposes labelled whitespace-normalized text with explicit
UTF-8 byte mappings into each immutable citation span. It preserves canonical
spelling and marks joiners with no source range instead of fabricating a range.

COMMIT is the local linearization point. Revoke before that point blocks disclosure;
revoke afterwards cannot recall an already prepared buffer. This does not promise
distributed atomicity with an external provider. The host records provider acceptance
separately and must call fresh disclosure at the actual next handoff. Preview/asset
delivery must use this same boundary and bounded bodies, never a permanent public URL.
