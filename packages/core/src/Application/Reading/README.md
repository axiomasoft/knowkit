# Exact citations and controlled reads

`Readings::read` accepts an authenticated host `AccessContext` and a typed
`ReadRequest`. Request data cannot supply host identity, `hostScopeHash`, policy
receipts or a storage locator. The host must invoke it at each actual delivery
boundary; a retained `ReadResponse` is historical evidence, not renewed authority.

`PdoUsages` writes each immutable citation resolver row in the same transaction
as its Usage, dependency lineage and outbox event. `PdoCitations` resolves only
the tenant-qualified citation ID, exact source version/digest, revision/IR digest,
document and ordered page/segment spans. The stored joiner is explicit. There is
no latest lookup, normalized quote search or source-path fallback. A correction
may deny an old version; it never rewrites the citation. Removing payload retains
the resolver and returns `unavailable(payload_purged)`.

Historical reads do not reuse Selection expiry or its old policy decision.
Current source/ancestor/collection operands and policy epochs are discovered,
canonical bytes are preloaded and verified within an aggregate 8 MiB budget,
and fresh owner receipts are prepared outside SQL guards. A short transaction
locks tenant, KB and all ancestor sources in disclosure order, repeats exact
identity, membership, lifecycle/tombstone, resource state, policy binding and
DB-clock freshness checks, and writes a new Usage and text-free read receipt.
The buffer leaves the gateway only after confirmed COMMIT. Ambiguous COMMIT
returns `unknown` without bytes. Attempt replay returns metadata only; changed
request operands on the same attempt are denied. Reads and ordinary disclosure
share the tenant-qualified attempt uniqueness constraint.

Targets are the exact quotation, a complete canonical page, a complete table
with cell spans/headers/units/notes, an original source or a retained IR asset.
Page selectors distinguish local ID, 1-based physical number and printed label;
the existing ingestion conversion maps page index 0 to physical number 1.
Ambiguous labels or exact regions return explicit alternatives. Region reads
require an exact retained asset bbox and preserve that bbox/coordinate transform
and the produced-byte digest. They do not render or approximate arbitrary crops.
Original source, IR, page text and produced preview digests are separate.

This is a controlled-buffer boundary, with no public blob URL, filesystem path
or bearer object credential. A host HTTP adapter can send the committed buffer
through an authenticated endpoint. Request TTL is bounded to 300 seconds; expiry
and optional etag are checked anew. Oversized originals require a separate
bounded-range implementation and are refused here. No streaming or optional
external adapter is claimed qualified by the tests.

The selected named profile and caller reductions apply independently to whole
objects, text bytes, per-object bytes, assets and exact configured token counts.
Caller `maxBytes` and hard caps also apply to binary bodies. Tables that exceed a
limit return `incompatible(table_read_required)` without a partial row. No
truncation or omission is silently introduced. The response exposes the selected
profile, exact citation/target metadata, coverage and complete-table information.

P4.4 cache integration must cache references only, preserve all exact identity
and host bindings, and invoke this gateway for every new delivery. A cached
Usage, ReadResponse, receipt or historical allow never authorizes a new read.
