# Embedding providers

The host constructs a `ConfiguredProvider` with an immutable `SpaceRef`, explicit
byte/batch limits, current access/egress policies, a transport, and a durable
`EmbeddingReceiptStore`. Register the provider using `EmbeddingRegistration` and
resolve the complete space through `EmbeddingProviderRegistry`. Unknown spaces
are incompatible. There is no default provider, model, retry or fallback.

Each call validates its complete outbound manifest and limits, checks processing
rights, durably records an unknown attempt, checks egress, and sends once. The
transport receives the exact configured document or query role. It must report
its observed space and role and must not retry or substitute a model internally.
Output must match cardinality, dimensions, finite numeric nonboolean coordinates,
and the complete fingerprint. L2 vectors must have unit norm within 1e-6;
unnormalized spaces retain finite vectors without silently normalizing them.
`SuppliedVectors` is an IO-free host transport using the same validation path.

Before local result acceptance, document calls obtain fresh publish decisions
for the KB and every source/version; query calls recheck retrieve rights. A
changed binding or revoked rights rejects acceptance. These decisions do not
publish a generation: generation publication retains its own authoritative
lifecycle/epoch checks under database locks. Host policies own current source
lifecycle and the intersection of KB, collection, source and purpose rights.

Receipts contain safe scope/request/space/result digests, attempt number, opaque
provider request ID, status and typed failure outcome. Request digests bind the
whole trusted scope, destination manifest, ordered immutable chunk refs and
content hashes. Result digests encode IEEE-754 coordinates as hexadecimal strings
inside the integer-only canonical JSON profile. Credentials and raw text never
enter receipts or exception messages. Provider request IDs must be opaque safe
identifiers supplied by a trusted adapter, never response bodies or URLs.

Timeouts and unexpected exceptions after dispatch remain unknown. A crash leaves
the durable unknown intent. Explicit retries allocate new attempts and may repeat
remote effects. Identical local acceptance is idempotent; a different result for
the same request conflicts. Receipts are evidence, never reusable permissions.

The optional `knowkit/pgvector` project provides `Embedding\PdoReceipts`.
Administrators explicitly apply `migrations/001_embedding_receipts.sql` to their
configured schema using a non-superuser role. No migration runs at construction
or Composer installation. This item implements no external HTTP/model adapter
and no ANN index. Transport doubles test the boundary contract only.
