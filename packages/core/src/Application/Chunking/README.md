# Structural chunking — P2.6

`StructuralChunker` implements the existing `Chunker` port. Construct it with the
trusted exact `DocumentRevisionRef` and persisted `DocumentId`; `SpanAssembler`
rechecks tenant, source version, parser signature and SHA256(JCS(IR)) before use.
No source/revision/document identity is inferred from content or resolved to latest.

Use `build(IR, profile)` when persisting or consuming evidence: its immutable
`ChunkingResult` couples the existing domain ChunkSet with provenance, common
structure path, ordered block IDs, explicit joiner, table cells and read requirements.
The port's `chunk()` returns that same ChunkSet for existing index consumers.
Metadata must accompany chunks into disclosure; text equality alone does not turn
a generated description into a source quotation. This is an application sidecar,
following the text extraction pattern, without changing the IR/citation schemas.

Engine signature is `structural` / `1`. Semantic options are `maxBytes` (default
1200, range 1..1048576), `overlapBlocks` (default 1, allowed 0 or 1), optional
`maxCount` and `counter:{id,version,vocabularyDigest}`. `maxCount` means exact
token count. Both count and counter are required together, with a local Tokenizer
whose capabilities declare that complete identity and exact counting. These
semantic names fit the existing credential-filtered ChunkerProfile; no secret
token, provider credential or estimate is accepted. Unknown options reject.

The byte and optional token budgets cover quote plus inherited heading/header,
unit and note context, joined by newline. Context has its own origin, roles and
canonical spans and is explicitly `isQuote=false`. Unit labels come from the
IR's structural metadata. They are never invented byte ranges. A citation's
quote uses only its chunk spans and declared joiner. Generated descriptions stay
in their canonical segment with `origin=generated`, `kind=description` and
supporting block/asset refs; OCR origin and coverage diagnostics are preserved.

Text blocks pack at paragraph boundaries within the same parent/provenance.
Only a whole final block can overlap into a subsequent chunk, and only when it
fits with new content. Long paragraphs split first at sentence punctuation, then
at UTF-8 codepoints without trimming or normalization. Code, headings, formulae
and table row groups remain whole. Rowspan closure keeps merged rows together;
cell metadata retains colSpan, headerRefs, units and noteRefs. Cell slices form
explicit multi-span table representations. Repeated physical headers remain real
source spans; header reuse is marked as separate search context. Empty or
unlocated required structure, an excessive span count (64), or an indivisible
unit that cannot fit produces an exact `read_requirement`, never a truncated
quotation. Requirements contain no source text and bind exact revision, block,
spans and optional half-open row range. Each requirement is at most 4096 UTF-8
bytes. When a span list/path/provenance would exceed that bound, those optional
details are null: the exact revision/block/row selector still selects the whole
structural unit, with no truncated list masquerading as complete. Consumers enforce their own bounded
read and fresh disclosure checks. ChunkSet may be empty with nonempty followups.

Identity algorithm version `structural-chunkset:1` uses the approved
`ChunkSetManifest` hash domain. Set ID hashes `{representationVersion,revisionRef,
documentId,chunkerSignature,identityKind:"chunkset"}`. Chunk ID hashes
`{identityKind:"chunk",chunkSetId,entry}`, where entry includes ordinal, raw text
digest, spans and all metadata. IDs encode the first 128 digest bits in the
canonical 26-character ULID alphabet; no clock or randomness participates.
The full manifest digest hashes the identity, set ID, ordered entries (with IDs),
followups and unchanged IR coverage. The full engine name/version/options digest
participates, even when a changed profile emits identical text. Display profile
ID does not alter semantics. Persistence compares full immutable payloads, so a
truncated-ID collision fails rather than overwriting earlier content.

`PdoChunkSetStore` rebuilds the result from exact inputs under the caller's guarded
transaction and delegates immutable inserts/resource checks to the existing
catalog. Accepted canonical resource IDs must match the complete chunk inventory.
It additionally verifies the exact persisted document/revision binding. Context
text is removed from JSONB metadata; its locators and roles remain. It performs
no blob I/O, publication or disclosure. The owning ingest workflow retains the
canonical result manifest and its digest as stage evidence, including followups;
SQL stores the digest and per-chunk metadata using the approved existing tables.

Fixtures and independent Python literal-slice/identity oracles are versioned under
`tests/fixtures/chunking`. Source qualification is staged at
`tests/ingest/chunking/evidence/P2.6-reuse.json`; the coordinator registers
`@plan/artifacts/implementation/P2.6-reuse.json`. The executor does not mutate plans.
