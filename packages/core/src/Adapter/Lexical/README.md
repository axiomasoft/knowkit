# PostgreSQL lexical simple v1

`PdoLexicalIndex` implements the existing `LexicalIndex` port using the baseline
`lexical_documents` indexed tsvector and exact identifier array. PostgreSQL FTS
ranking uses `ts_rank`; the profile is `postgres-fts-simple-v1`. RU/EN use literal
lexemes in the pinned `pg_catalog.simple` configuration. There is no morphology
or cross-language semantic guarantee. CJK uses canonical literal matching.

Construct `CanonicalChunkSets` from exact `ChunkingResult` buffers loaded by the
host from canonical storage **before** opening a SQL transaction. The inventory
validates exact revision, chunkset signature, document, chunk IDs/digests/lengths
and ordered spans against each canonical chunkset manifest. It contains no
permissions. Missing canonical buffers are unavailable; dishonest manifests are
incompatible. The host may prepare an operation-specific inventory; this v0.1
adapter materializes its authorized manifest chunk IDs per search. Its memory
and bound JSON size grow with that authorized corpus, rather than just top-k.

For `index(generation, chunks)`, the caller owns the catalog transaction and tenant
update guard (normal tenant/KB/source/generation lock order still applies).
Indexing checks the persisted exact revision/chunkset/chunks and accepted
canonical resource digests, and requires a building generation with the exact
profile. Identical replay is supported; changed inputs and writes after seal
reject. `IndexReceipt.manifestDigest` binds the indexed **chunkset manifest**;
the publication workflow subsequently builds and seals the generation manifest.
No publication, snapshot activation or lifecycle policy decision occurs here.

Search requires the exact sealed snapshot/generation mapping, validates the
schema and domain-separated generation manifest digest and resolves its exact
members through the canonical chunkset manifests. SQL joins that verified chunk
inventory with tenant-qualified index and snapshot memberships, source/version,
IR and chunkset identities, authorized versions and typed request source/collection
filters. `permitted AS MATERIALIZED` precedes rank and LIMIT. Rogue chunk IDs
cannot consume top-k. Returned rows are checked again against canonical refs.
Missing or failed mandatory storage is unavailable; no match is an empty list.

The `literal-query:1` syntax compiler retains the original UTF-8 query. Technical
identifiers such as `DOC-2026/0042`, `foo_bar.v2` and `KB-2026-001` require complete
identifier-token matches; they never fall back to their component lexemes.
Double quoted phrases require `phraseto_tsquery` plus canonical literal verification
before top-k. CJK phrases/runs require canonical literal verification. Literal
comparison collapses Unicode whitespace and folds ASCII case only, preserving
Unicode and identifier punctuation. Unquoted remaining terms use
`websearch_to_tsquery`. Punctuation-only and negative-only queries produce no
zero-score pseudo-hits. No semantic provider or query rewrite is invoked.

Candidates contain immutable `ChunkRef`, rank and finite positive score only.
Citation text and spans come from canonical storage through the application;
fresh authorization/lifecycle checks immediately before disclosure remain the
application's responsibility. Do not log query text, canonical buffers or SQL
bindings. See the executable versioned corpus and required-case mapping under
`tests/fixtures/retrieval/lexical` and the staged source qualification receipt
`tests/retrieval/lexical/evidence/P2.8-reuse.json`. The coordinator registers that
receipt in the native plan; this adapter does not mutate plan state.
