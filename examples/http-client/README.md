# HTTP client

Install `knowkit/http` explicitly in the host. Construct
`Client\Client(transport: new Client\StreamTransport(...))` with a base origin
without a trailing slash and host-owned `Authorization` or `Cookie` headers.
Load the package Composer autoloader. Credentials come from the host's secret
configuration; never put them into URLs, command arguments, payloads or logs.

`SearchAndDisclose` accepts a client, the core schema validator, a host ID
generator, a typed query and an optional `Limit` (default top-k 8). It searches,
retains exact candidate references and uses a fresh disclosure attempt. A success
response without a selection is returned as-is; it is not disclosed. Check the returned typed outcome before using
data. Evidence remains `untrusted`, with `memoryActivation=false`; receiving it
does not establish local policy authority or provider acceptance.

For all other operations construct `Client\Call::operation()` using the exact
OpenAPI operation ID, a `JsonObject` body, typed route IDs and Request-ID. Mutations
also take a core `MutationContext` with the same Request-ID, an Idempotency-Key
and the required expected epoch/revision. `Client::execute()` freezes the request
once and retains it verbatim across retries. An explicit repeat with the same
key still goes to the server for current authorization and durable replay.

The default deadline is 5000 ms for all attempts and waits combined, at most
three attempts. Network failure and timeout may retry safe discovery operations
and keyed mutations. A timeout leaves commit state unknown. Retry uses the same
key/body/preconditions; do not generate a new key to recover an uncertain write.
Quota 429 and retryable 503 may retry within the same budget. Numeric Retry-After
is respected up to 60 seconds; unsupported or excessive delays return the
response. Quota remains a transport error (`outcome=null`, status 429), separate
from the six domain outcomes. Version and protocol failures never retry.

Disclosure, citation resolution and reads never retry automatically. A lost
disclosure response can only be explicitly replayed with the same attempt for
metadata. A new plaintext handoff requires a new attempt and current policy.
Use `resolveCitation()` with the immutable citation ID and a fresh Request-ID
for a new quote read; it never resolves a historical citation through latest.

The client requires schema 1.0 acknowledgment and validates the closed envelope,
status, correlation and content type. It refuses redirects, ambiguous headers
and responses above 8 MiB. Native PHP streams enforce one exchange deadline with
verified TLS defaults. Inject `Client\Time` for deterministic retry tests.

The shared corpus in `tests/conformance/http` runs the same operations against
in-process core services and a persistent loopback HTTP host. PostgreSQL guards,
cache hits, committed Usage, metadata replay and revocation use real stores.
Optional vector failure is a contract fixture exercising the real hybrid policy;
it does not qualify an external vector or model adapter.
