# KnowKit HTTP delivery

Requires PHP 8.3+ and an explicit host integration. Install using this package's
Composer project and lock for its tests; no HTTP dependency enters root/core.
Loading the package installs no routes, migrations, workers or global hooks.

The local contract is [OpenAPI 3.1](../../specs/http/openapi.yaml), with `/v1`
routes. It uses JSON-compatible YAML. Run `php packages/http/bin/generate.php`
after editing it to regenerate the installable `resources/openapi.json`.
`Server\Contract` uses that document for route matching and closed request
schemas. Runtime never resolves schema URLs or accepts an input-selected file.

Construct `Server\Server` with:

- `Authenticator`: the host validates its session/token and returns request-local
  `HostIdentity`, or null. Authentication runs before reading JSON. Neither body
  nor route supplies tenant, actor, grants, purpose, epochs or hostScopeHash.
- `HostApplication`: dispatches the validated `Command` to the existing core
  use cases. This is the same explicit composition seam used by the Laravel
  package. `CoreCommands` maps catalog/source-registration/lifecycle commands;
  `CoreReads` maps `Retrieval`, `Disclosure` (for example `FreshDisclosure`) and
  `Reading\Readings`. Decorate the remaining host application with these when
  those core implementations are used.
- `CommandFactory`: an offline core `ContractValidator` and a host clock.
- `IdGenerator`: server-generated ULIDs for missing/unsafe Request-ID values.

`handle(Request)` returns a buffered `Response`. An explicit front controller
can call `Sapi::serve($server)`. Framework adapters can supply method, request
target, headers and the input stream directly. Install the authenticated catch-all
under `/v1` explicitly; no request should bypass `handle`.

Host composition implements each supplementary operation (`startIngestJob`,
`cancelIngestJob`, `reprocess`, `ingestJobStatus`, `validatePublication`, `publish`,
`purgeStatus`, `registerProviderAcceptance`, `usageInvalidations`, `capabilities`,
`readiness`, `coverage`) and `addVersion` by calling its existing application
services. Upload commands contain verified `SourceBytes`; the host obtains a
durable `BlobWriteReservation` before constructing `SourceUpload`. The host must
pass `Command::mutation` intact to its durable command boundary, including both
expected values and Request-ID. It must check expected state under its existing
locks and include it in idempotency fingerprints. HTTP keeps no idempotency cache.
Unknown backend or policy state must remain unknown/unavailable; a successful
placeholder, empty fallback or uncommitted job receipt is invalid.

Creation of a KB uses the host-selected Scope.kbId. Source, collection, generation
and attempt IDs are opaque ULIDs. Nested immutable tenant refs must match the
authenticated tenant. Search filters are typed source-version/collection lists;
they narrow the host eligibility universe before top-k in the core service.
Source origins are opaque catalog refs (`upload:fixture`); URLs, filesystem paths,
redirect targets, SQL, credentials and trusted usageContext are rejected. Resolving
an opaque connector ref remains a host operation using core OriginBoundary on
every network redirect/DNS answer, never an HTTP transport operation.

Mutation routes require Idempotency-Key, and the route-specific Expected-Epoch
and Expected-Revision headers listed in OpenAPI. An exact replay returns the
accepted result after current authorization. Incompatible payload/expected state
returns 409. Core's Denied/POLICY_CHANGED is retained in the envelope and maps to
409 on mutations as the optimistic-conflict exception; on disclosure it remains
403. Missing exact catalog objects return 404 without metadata. Unknown and
unavailable remain distinct 503 outcomes and `retryable` follows the host result.
An HTTP host quota can throw `InputFailure(429, 'rate_quota')`.

Disclosure accepts the full approved DisclosureRequest with no trusted
usageContext. The host owns stored query/token/snapshot bindings. A fresh send
uses a new attempt ID and current checks. `CoreReads` serializes only committed
EvidenceSet/ReadResponse or metadata-only DisclosureReplay. Evidence retains its
unchanged schema (`usageId`); preview responses include `usageRef`, immutable
citation identity, digest, checkedAt and base64 bytes. A citation GET is also a
fresh Usage-backed quote read, using Request-ID as its attempt ID. Use a new
Request-ID for a new GET disclosure; replay cannot recover plaintext.

All responses carry safe Request-ID, no-store and JSON content type. Denied and
error envelopes contain no data; accidental host output and exception messages
are discarded. Do not log requests, Authorization, bytes, document text or
provider inputs. Host credential middleware and infrastructure logging remain
the host's responsibility.

JSON bodies are limited to 262144 bytes. Upload JSON is limited to 1403000 bytes
and decoded bytes to 1048576. Actual stream length is checked even without
Content-Length; inconsistent length, duplicate inspected headers, duplicate JSON
keys and unknown properties fail. Uploads are base64 JSON, never paths/URLs.
These limits apply before application invocation; preview limits additionally
pass to the core read request. The host configures request timeouts and rate
quotas at its HTTP server.

P7.3 can reuse Request, Response, Contract, versioned fixtures and the envelope.
The client should preserve opaque IDs and exact citation/IR digests, pass mutation
headers verbatim, and distinguish every outcome/status. It must never replay
stored evidence as a fresh disclosure.
