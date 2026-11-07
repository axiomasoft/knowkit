# Atomic snapshot activation

Wire `Application\Publication\Activate\Publisher` to `PdoActivation`, the same
catalog connection/transaction, `PdoStagingGenerations`, prepared policy authority,
and the canonical resource/backend probe. `ActivationRequest` contains one exact
generation and its complete immutable member inventory, a live ingest lease,
the expected pointer version, and a tenant-wide idempotency key.

Policy and object/provider calls happen before acceptance. The short READ COMMITTED
transaction takes tenant UPDATE, KB UPDATE, sorted source UPDATE, every affected
generation UPDATE (including retirement), then the current job UPDATE. It repeats
policy binding, epochs, tombstones, exact version/ancestor usability, membership,
lease fence/deadline, sealed receipt, resource inventory, coverage and persisted
required projection checks. Snapshot rows and exact memberships, sealing, pointer
CAS, generation state, outbox and the original metadata receipt commit together.
`POINTER_CONFLICT` rolls back everything. The caller must build an explicit new
request against the changed pointer and obtain fresh authorization.

Replay checks the exact tenant/key/fingerprint before external I/O and again under
the tenant barrier. It returns the first receipt after later pointer/lifecycle
changes and grants no disclosure rights. A different operation, scope, generation,
manifest, job or expected pointer conflicts. No automatic retry or latest merge is
performed. The snapshot digest uses `knowkit:publication-snapshot:v1` over
`{tenantId,kbId,generations:[{generationId,manifestDigest,members}]}`; the immutable
generation manifest binds all exact chunk digests and resources. Its rows and the
snapshot's exact memberships remain available for authorized pinned history.

Resolve an active selector once with `CatalogRepository::resolveSnapshot`; carry
that immutable `SnapshotRef`/generation throughout retrieval and reranking. Use
the pinned selector for historical reads. Recheck current rights and ancestor
usability before disclosure. Retirement never redirects old refs to latest.

The tenant barrier serializes local revoke and publication only. Remote owner
freshness remains the host's bounded owner contract; a delayed external answer
does not establish distributed atomicity. No dispatcher or paid embedding call is
part of activation. Pending outbox events survive dispatcher interruption.

Reuse qualification and donor limits are recorded in
`tests/publication/activate/evidence/P3.4-reuse.json`.
