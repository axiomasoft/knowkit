# Local text extraction, v1

`TextParser` and `Markdown\MarkdownParser` implement the existing `DocumentParser`
port. Supply an offline `ContractValidator` and an explicit profile from
`ParserSettings::profile('text'|'markdown', ...)`. Unknown engines, versions,
options, omitted options or options exceeding the ceilings are incompatible;
there is no fallback. Both parsers are stateless and perform no I/O, network,
OCR, model calls, registration, publication or persistence. The host owns access
checks and lifecycle authorization.

`parse()` returns immutable IR 1.0. For the complete extraction result, use
`extract()` and retain **both** `document` and `canonicalSidecar()` together.
Q1=a authorizes the `text-extraction-sidecar:1` carrier without changing IR 1.0
or the existing port. This application value is not a new general wire schema
or a persistence adapter. It binds the full tenant/source/version/sourceDigest
reference, parserSignature and SHA256(JCS(IR)); `assertBoundTo()` checks those
exact identities for reuse. No document revision ID is invented: allocation
of DocumentRevision and its irDigest remains the ingestion owner's job.

The sidecar records source media type/byte length, detected UTF-8 encoding, BOM removal,
normalization state, canonical segment digest and the exhaustive ordered byte
transform partition. Runs are `copy`, `bom` (3 source bytes to zero canonical
bytes), `crlf` (2 to 1) and `cr` (1 to 1). Ranges are half-open; unchanged LF and
Unicode bytes belong to copy runs. `normalization->sourceRange()` maps canonical
codepoint-boundary spans to exact original byte ranges, excluding a removed BOM.
The transform table is normalization provenance, **not** a search normalization
map, a rendered-text map, or PDF byte coordinates. There is no Unicode
normalization and no heuristic encoding conversion.

TXT and Markdown each have one logical segment `s1`, including form feeds.
Canonicalization only removes one leading UTF-8 BOM and converts CRLF/CR to LF.
Canonical zero bytes (including BOM-only input) produce confirmed empty coverage
and no blocks. Whitespace-only canonical bytes are preserved as `ok`, since IR
1.0 forbids a nonempty empty-status segment. No trimming or placeholder quote
is performed. Original, canonical, full display and truncated display digests
have different meanings; equal bytes may legitimately have equal digests.

Defaults: 1 MiB source/line, 16384 lines, 4096 blocks and 16384 total table cells.
Configurable source/line ceilings are 4 MiB; the other defaults are also their
ceilings. Every effective limit participates in profileDigest. Limits reject
before lossy truncation; line count includes an empty final line after LF.
Malformed UTF-8 and explicit unsupported encodings have failed segments;
unsupported media and exceeded normalization limits have skipped segments.
Both have partial coverage, safe machine diagnostics and incompatible outcome.
Rejected normalization has no canonical digest or transform map: its empty IR
text is a failed/skipped unit, never a source quote. Complete zero-byte input has
empty outcome; supported nonempty input has success outcome. Markdown structural
gaps retain the complete canonical segment, set partial coverage and incompatible
`incomplete_extraction`; job and publication status are separate concerns.

Markdown supports ATX headings (1–6), blank-line paragraphs, backtick/tilde
fences, single-line ordered/unordered list items (space indentation preserves
item nesting), and rectangular pipe tables with a header separator (at least
three hyphens per column). This is **not CommonMark compatibility**. Block spans
include source delimiters and line endings, not rendered/plaintext positions.
IR kinds/parents/order preserve headings, list-item ancestry, code and tables;
sidecar structure binds heading levels, list grouping/markers/ordinals/indent,
fence/info/content ranges/closed state and table alignment to exact block IDs.
Table cells use trimmed source spans, empty cells have no spans, data cells
reference column headers, and the separator stays in the table block span.
Unescaped pipes delimit cells even in inline backticks; escaped pipes remain
literal. Inline emphasis and links remain literal. List continuation paragraphs,
setext headings, blockquotes, raw HTML and other detected unsupported constructs
remain literal paragraphs with diagnostics. Unclosed fences and malformed or
over-budget tables are marked incomplete, not silently dropped. A block budget
may leave an unparsed suffix, explicitly flagged by `block_count_limit`.

`display()` escapes the original canonical syntax inside `<pre>` and may append
a separate presentation truncation marker. It executes no HTML and resolves no
remote assets. Its bytes/offsets/suffix must never be used for canonical quotes.
Debug views omit document text; callers must not log result serialization.

Reuse qualification is staged at `tests/ingest/text/evidence/P2.4-reuse.json` for
coordinator registration in the plan. Audit PDF/OCR/search tests are labelled
contract-boundary regression examples; no external adapter is implemented or
qualified by this local text package.
