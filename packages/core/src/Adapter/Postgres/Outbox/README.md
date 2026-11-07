# Transactional event delivery

`PdoOutbox::append` remains on the publication/lifecycle caller connection and
transaction. `PdoDelivery` is bound to one trusted tenant; `Dispatcher` leases
at most 64 rows with `FOR UPDATE SKIP LOCKED` and commits before transport I/O.
Leases default to 60 seconds. Ack, retry and quarantine require the current fence,
hashed random token and unexpired lease. A lost ack causes at-least-once delivery.
Failed sends retry after five seconds; permanent invalid envelopes quarantine.
`status()` exposes outstanding count, oldest outstanding age, quarantine count
and cumulative transport errors. Errors store fixed codes without exception text.
For rows predating this migration, enqueue age starts at upgrade because their
original metadata commit timestamp was not recorded. Legacy leases without token
proof are requeued with their fence retained; the next lease advances it.

`Consumer` validates the closed event-1.0 contract, including payload digest.
Unknown versions/types, malformed and poisoned envelopes retain only their hash
and error code. `PdoInbox` atomically commits invalidation markers and inbox rows.
An event ID/envelope conflict or conflicting aggregate sequence durably blocks
that tenant/consumer. Recovery of a blocked consumer requires operator diagnosis;
there is deliberately no automatic reset or conflict overwrite.
Legacy inbox rows without immutable envelope proof also block on replay; their
original receipt remains intact for diagnosis.

Markers preserve maximum aggregate/lifecycle/policy/pointer epochs under reorder.
They always invalidate and stay stale: they never copy content, update authoritative
catalog state, grant access or activate a generation. A sequence gap triggers a
committed metadata read via `EventAuthority`; unknown/unavailable authority keeps
the gap, including on duplicate retry. The local PostgreSQL authority supports
source (including unseen tombstones), source version, KB and generation aggregates;
other aggregates remain stale when their authoritative watermark is unavailable.
Clearing a gap only records catch-up, not rebuilt projection usability. Serving
still requires the synchronous authoritative lifecycle/access checks. Host
transports and remote authoritative readers implement their ports separately;
no external transport or optional adapter is claimed implemented by these tests.
