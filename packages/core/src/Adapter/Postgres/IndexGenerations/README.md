# Staging and readiness

Build `Domain\ManifestCandidate` with `Application\Publication\Manifest\Builder`, using `ResourceInventory`
for the tenant-qualified canonical resources and authoritative revision coverage.
The wire format remains `index-manifest-1.0`: sorted exact member tuples bind
immutable chunkset manifests, which bind every chunk digest and ordered source
span. `knowkit:index-manifest:v1` plus NUL and JCS binds the complete payload.

`PdoStagingGenerations::stage` records the candidate and source epochs before
projection writes. It requires a current durable job lease, KB/source membership,
current publish policy receipts, and sorted tenant/KB/source/generation/job guards.
Retries for the same job/candidate can refill a building generation. A failed
generation is terminal; rebuilding always gets a new generation ID.

`seal` verifies every referenced object outside the SQL transaction, using its
accepted immutable storage version, digest and length. `PdoResourceReader::open`
adapts the existing BlobStore to the probe. Missing/corrupt objects are unavailable.
The opener is a trusted persistence dependency, never a backend snippet reader.
It must return the bytes of that exact immutable object version, never latest.

`PdoReadBackend` reloads PostgreSQL FTS data and verifies every membership tuple,
count, stored tsvector and exact identifier token list. It queries deterministic
canonical text without a query embedding provider. The full probe runs before
seal; the SQL portion runs again under the seal guards. A successful write receipt
alone cannot seal. Lifecycle, tombstones, version usability, source epochs, job
fence/token/expiry and current policy bindings are checked again under guards.
The job's immutable ProcessingIdentity digest and parser/chunker/embedding
signatures must also match the candidate; a different processing profile is incompatible.
The seal, immutable resource/check receipt and `generation.sealed` outbox event
commit together. No external object/provider I/O runs under those SQL guards.
The host wires the prepared-policy, probe, optional-profile and event callbacks;
their signatures follow the existing catalog adapter's callback composition.
The adapter imports only Domain/Port and other Adapter classes.

The required projection is PostgreSQL `postgres-fts-simple-v1` lexical search.
This is FTS with literal/identifier behavior; it does not promise BM25. Optional
host projection probes report distinct denied/unknown/unavailable/incompatible
outcomes in durable capabilities and a degraded profile. They must verify their
persisted projection and space, rather than accept a write receipt. No external
vector/graph adapter is implemented or qualified here. The generic readability
port and its vector fingerprint contract are covered with an explicit test double.
`RankFusion` consumes ranks with RRF k=60, independent of backend score scales.

Staging and seal never write the active snapshot pointer. A required failure
preserves the previous active snapshot; a later publication use case owns fresh
authorization, snapshot creation and pointer CAS. The R11-A02 fixture composes the
existing catalog transaction/CAS/outbox primitives against real PostgreSQL; it
does not claim a Publishing implementation. The R11-A01 actor is killed after a
committed partial projection, before seal; canonical assets and staged identity
remain discoverable and a building retry completes the same candidate.

The source reuse receipt is `tests/publication/manifest/evidence/P3.3-reuse.json`.
Versioned authored fixture expectations are in `tests/publication/manifest/fixtures/v1`.
The required-case manifest maps every mandatory audit/DT ID to executed tests.
