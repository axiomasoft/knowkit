# Trusted context and policy boundaries

The host authenticates each request and implements `HostIdentity::current()`.
It supplies the existing six-field Scope and PolicyContext audience, grants and
egress eligibility. These values never grant access by themselves. Scope remains
the approved public immutable composition DTO; possession of a constructed PHP
object is not authentication. Only trusted host wiring constructs AccessContext.
There is no ambient context fallback, JSON context deserializer or donor ACL engine.
RequestBoundary rejects privileged body fields before application metadata work.
Optional HTTP/Laravel adapters must call this boundary with their authenticated
request-local host identity; those transports are outside this implementation.

Use DenyAll for both default policy ports. StaticAllowList exists only in tests.
Production AccessPolicy and EgressPolicy implementations belong to the host.
The host intersects tenant, KB, applicable collection, source, business purpose
and operation rights. PolicyChecks adds a mandatory KB operand; callers supply
the complete trusted canonical collection/source resource intersection. Query
authorization materializes the host grant against host eligibility and typed
filters before passing any universe to a lexical/vector backend. Membership is
never permission and QueryGrant cannot remove a host exclusion.

Admission, processing, egress, publication and disclosure require their own
current decisions. PolicyChecks.prepare fetches current receipts outside SQL
transactions and pins the entire host PolicyContext. PreparedDecision consumes
one attempt under ordered shared guards, comparing authoritative binding, scope,
operation, expiry and DB time without host callbacks. PdoPolicyEpoch supplies
tenant-qualified epochs and exact source-version/lifecycle evidence, not ACLs.
Its callback is restricted to bounded local work; hosts must not call an owner,
provider, subprocess or blob store there. Existing catalog collection/membership
mutations and KB removal advance KB epochs in the mutation transaction. Hosts
use explicit CAS methods for tenant, KB/collection ACL and source ACL changes.

PolicyChecks.transmit checks the complete manifest against actual payload
digests, rechecks processing, then independently calls EgressPolicy immediately
before transmission. Its currentBinding callback must load authoritative state
and check every exact source reference from the request, outside any open
transaction. It runs again after the owner call to reject local epoch/lifecycle
drift before provider bytes. Destination and manifest classification are trusted
host responsibilities; the provider closure must send exactly that destination
and those payloads. No content, raw owner errors or credentials are logged.

ExternalOwnerPolicy always consults the current host owner. Its revision/expiry
projection is observational; a deny, unknown, error or expired observation never
falls back to a previous Allow. OwnerFreshness explicitly names the host-admitted
remote consistency contract and a window of one to five seconds. Unknown
freshness is Unknown, provider errors are Unavailable, refusal is Denied and
identity/format incompatibility remains Incompatible. The remote owner can change
rights after its response and before local commit. This bounded window remains;
local epochs do not prove remote freshness or offer distributed atomicity.

These are reusable authorization boundaries. Complete snapshot publication,
ancestor traversal, canonical buffer checks, selection/snapshot binding and the
confirmed Usage/prepared-disclosure commit remain mandatory in their owning
application workflows. A successful PreparedDecision alone never authorizes
returning bytes without those checks. No complete disclosure workflow, optional
external owner/provider adapter or HTTP transport is claimed here.

Private local adaptation: AgenTools (c) 2026 Dmitry Vostrikov, all rights reserved,
pinned host-context/current-owner patterns; DeepTutor membership bypasses inform
negative tests only. Exact-file decisions, provenance and retained license
references are in tests/security/policy/evidence/P2.3-reuse.json. The coordinator
registers that receipt at the native plan artifact path; the executor does not
mutate plan state.
