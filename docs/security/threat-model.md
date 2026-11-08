# Connector and resource boundaries

The trusted host creates Scope and owns current access, egress and lifecycle
decisions. Document text and metadata, remote URLs, parser outputs and model
messages never supply tenant identity, grants or policy epochs. Historical
citations retain their source/version/IR/span identities. The existing disclosure
and provider boundaries still require current authority immediately before bytes.

`Application\Origins\FetchRemote` is an explicit opt-in HTTPS fetch primitive.
It is separate from opaque catalog origin references; registering a source never
fetches its origin. An empty exact hostname allowlist denies all fetches. Only
HTTPS on port 443 is supported. Userinfo, fragments, ambiguous hosts, non-public
IPv4/IPv6, metadata addresses, mixed public/private answers and non-allowlisted
redirects fail closed. Every redirect resolves and validates all A/AAAA answers
again. The selected public address is pinned for one connection; the native
transport checks the connected peer before sending HTTP bytes and verifies the
original hostname through TLS 1.2/1.3 and the system trust store.

`Adapter\Origins\NativeHttps` opens a direct numeric TCP socket, so HTTP proxy
environment variables and HTTP wrapper resolution cannot bypass pinning. It has
no caller-header or credential API, cookie jar, automatic redirect or referrer.
Redirect cookies are discarded. Redirects are limited to three, response headers
to 32 KiB, transfer to 64 MiB, connect including TLS to five seconds, and the whole
operation including DNS to sixty seconds. Native DNS runs in a bounded child
PHP CLI process with a five-second ceiling and bounded output. Unsupported DNS
runtime or framing refuses the request. Only identity HTTP framing is accepted;
compressed and chunked responses are refused. Text/plain, text/markdown and PDF
require matching content sniffing. HTML is refused and no remote assets or
scripts are fetched. A host must authorize ingest before calling this primitive.

Uploads are capped at 64 MiB in SourceBytes and worker ingress. The HTTP v1
contract retains its stricter 1 MiB decoded upload, 1,403,000-byte upload JSON and
262,144-byte ordinary JSON limits. Queries are at most 4,096 UTF-8 bytes; HTTP
search limit is at most 100. Catalog metadata keeps its stricter 32-key contract
and now also caps canonical UTF-8 JSON at 16 KiB. Existing title/schema limits
remain in force. Provider budgets have finite input/output byte and token
ceilings; the reference budget defaults to 8,192 input tokens and refuses unknown
token counts when a token ceiling is configured. Hosts can configure finite
ceilings explicitly, retaining the existing explicit byte-only profile option.

The single-host reference profile admits at most 16 searches, four ingest HTTP
operations and two worker jobs concurrently per tenant. PostgreSQL additionally
caps active ingest job leases at four across every KB in a tenant; lease admission
serializes under the existing tenant lock before counting and claiming. Search and ingest rates
default to 60 and 10 per fixed 60-second window. Shared private files and OS locks
coordinate separate PHP processes; a permit stays held until completion or
process death. Host configuration accepts concurrency from 1 to 1,024 per lane
and rates from 1 to 1,000,000 per minute; unsupported limits fail during
configuration. Overflow returns HTTP 429 with Retry-After 60 before application
execution. Exceptions release permits. No document, credential or provider input
is persisted in quota files. Use a private stable runtime directory shared by all
local processes. Quota files retain only hashed tenant keys and counters; hosts
own retirement of inactive tenant files. Multi-host deployments require a shared
authoritative host gate in addition to local HTTP/worker bounds. PostgreSQL job
lease admission is shared across hosts and does not replace process permits.

Private blob reads reject traversal, encoded separators, symlinks, hardlinks and
unsafe file modes. Worker output is read only after process-tree quiescence;
root/path checks and file-descriptor identity checks precede bounded reads.
Existing worker limits bound time, CPU, memory, temporary bytes, pages, output
and assets. PDFs, including active JavaScript/Launch content, go only through the
existing isolated worker. Missing sandbox support is unavailable, with no local
parser fallback. Archives and OOXML are unsupported before parser launch and
decompression. A future explicitly enabled archive adapter must bound member
count, individual/total expanded bytes, ratio and image pixels before heavy read
and while streaming; this work enables no archive adapter.

Prompt injection remains literal untrusted evidence even with approved document
metadata. EvidenceSet reports `trustClass=untrusted`, `memoryActivation=false`,
and rejects schema fields attempting trusted promotion. Only the host can decide
whether to promote information into its own trusted policy; source content never
does so itself. Diagnostics expose machine codes and safe metadata, never bodies,
remote credentials or raw provider inputs.

Versioned inert adversarial fixtures, digests, provenance, expected outcomes and
executed-case mappings are in `tests/security/hardening/fixtures/v1` and
`tests/fixtures/check-groups/security-hardening.json`. R35-A01 uses the real
PostgreSQL catalog and fails when its backend is unavailable. Native framing and
pre-send peer refusal are checked locally; DNS/redirect orchestration uses
explicit doubles. Malicious PDF coverage proves refusal without a sandbox and
checks isolation configuration; it does not claim a new live parser audit. No
optional web/GitHub/archive connector or external provider qualification is
claimed from those doubles. The existing runner refuses missing, failed, skipped
or empty required cases.

Reuse qualification and SHA-256 verification for all seven pinned candidates are
recorded in `tests/security/hardening/evidence/P9.2-reuse.json`. Containment and
adversarial boundary patterns adapt AgenTools (private local reuse authority,
copyright 2026 Dmitry Vostrikov) and DeepTutor (Apache-2.0, copyright 2025 Data
Intelligence Lab, The University of Hong Kong). Existing license/notices remain
retained. Donor ACLs and archive extraction implementations are not imported.
