# PostgreSQL vector generations

This optional PHP 8.3 package implements the core `VectorIndex` and
`GenerationReadBackend` ports. It uses PostgreSQL 16/17 and pgvector with L2
distance, profile `pgvector-l2-v1`, and 1–2000 dimensions. Core has no extension,
framework, provider SDK or Python runtime dependency.

Install the `vector` extension in the `public` schema explicitly as the database
owner, apply the core catalog migrations, then apply
`migrations/002_vector_generations.sql` in the catalog schema. Grant the runtime
role access to these two tables and temporary-table creation in its database.
The runtime role must be a non-superuser. No migration or extension installation
runs on package discovery. Embedding receipts retain their separate migration.

The host accepts document embeddings through the existing role-aware provider
pipeline, including its fresh processing/egress/publish checks, before acquiring
catalog locks. Construct `DocumentVectors` with the pinned space, document role,
vectors keyed by chunk ID, and their exact immutable `ChunkRef` input bindings.
Bind one `PdoGenerationBackend` and `PdoVectorWriter` to the same catalog
connection/transaction and canonical chunksets. Supply `PdoVectorIndex` a query
callback that calls that pipeline and returns a `QueryVector` receipt bound to
the authorized scope, original query, filters and query role. The callback runs
outside SQL transactions. Host-supplied vectors use the same validation.

Build a core `ManifestCandidate` with the explicit vector profile and space.
Use the existing `PdoStagingGenerations` with an `IndexReadabilityProbe` backed
by `PdoGenerationBackend`, for both resource verification and the mandatory
backend verification under seal/publication guards. Its effective-profile
callback must mark vector readiness as required. Stage first, index each exact
chunkset under the tenant update guard, then seal. Publish using the existing
`Publisher` and `PdoActivation`; there is no second active pointer. A failed or
interrupted build preserves the previous snapshot. Retired generations remain
resolvable through their pinned snapshot while current policy permits them.

Every reload verifies the full eight-field space payload/fingerprint, manifest
digest, counts, exact membership, canonical document/span identity, resources
and persisted vectors. Vector digests use SHA-256 over
`knowkit:pgvector-float32:v1` + NUL + big-endian IEEE754 float32 coordinates.
Finite PHP inputs that overflow float32 are incompatible; normalization is
checked again after conversion, and signed zero is canonicalized to positive
zero for text round trips. Replaying a different vector for an existing
membership fails. Re-embedding changes generation/space identity and preserves
source/version/IR/chunk/document/span refs.

`SearchPolicy()` defaults to exact search. A materialized SQL corpus intersects
tenant, KB, exact snapshot/generation, manifest IDs, authorized source versions,
requested source/collection filters and current source lifecycle/membership
before distance ordering and LIMIT. Unsupported filtering capabilities are
incompatible before any provider call. Backend errors remain unavailable;
space mismatch remains incompatible. The adapter returns references only.
Core selection and disclosure still perform authoritative fresh policy checks
immediately before releasing text or citations.

`SearchPolicy('authorized-hnsw', efSearch: 40, fallback: 'exact')` builds a
transaction-local HNSW index over that already permitted corpus. No denied
vector enters the ANN index. Each request measures recall@k against exact
prefiltered top-k. Recall below 1 selects the exact result; `fallback: 'reject'`
instead returns unavailable. `searchWithDiagnostics()` exposes requested and
effective modes, recall, permitted count, fallback policy and whether fallback
ran. `search()` preserves the core port's reference-only result shape. Ties use
stable chunk ID ordering. Errors do not trigger lexical or alternate-model
fallback.

Building a temporary HNSW index and measuring exact recall on each request is a
correctness baseline with substantial overhead; this package makes no
throughput claim. Temporary data disappears at transaction end or rollback.
The real-backend regression measures recall 1 at ef=40 and 0.5 at ef=1 for a
two-document authorized corpus with a closer denied document, and proves both
fallback policies. Run individual files under `tests/semantic/vector` with the
root PHPUnit configuration. `TEST_VECTOR_PG_DSN` selects an explicit disposable
`*_test` database; its default is the Compose vector service on port 55433.
Mandatory backend failure fails tests and never becomes a skip. Versioned
fixtures and the required-case map bind all audit IDs; the conductor runs
`semantic:vector` acceptance. Donor provenance and source gaps are recorded in
`tests/semantic/vector/evidence/P5.2-reuse.json`.
