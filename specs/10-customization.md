# Adapter customization and conformance — P1.6

Hosts MUST compose the vendor-free PHP core through explicit ports and registry
bindings. They MUST NOT fork Domain, scan arbitrary packages, silently select a
default provider, or infer permission from credential presence. PHP remains
>=8.3; this SDK adds neither a Composer package nor a runtime PHPUnit dependency.
The normative baseline is [ARCH-REGISTRY / ARCH-PROFILE](architecture/architecture.md#arch-registry)
and the [P1.5 port appendix](../docs/architecture/ports-contract.md).

## Registration and immutable profiles

`AdapterRegistry::register(AdapterDescriptor, Factory)` precedes `boot()`.
`describe(AdapterId)` returns static metadata; `resolve(AdapterId, EffectiveProfile)`
constructs only the exact registered factory and verifies the declared port.
Duplicate/unknown IDs, unbound configurations, wrong port kinds and missing or
cyclic local capability dependencies fail explicitly. Dependencies never select
another adapter. Existing `Domain\CapabilityDeclaration` is reused unchanged.

`AdapterDescriptor::toWire()` conforms to the bundled
[`adapter-descriptor.schema.json`](../packages/core/src/Registry/adapter-descriptor.schema.json).
Descriptors include explicit version, closed offline config schema, capabilities,
required extensions, boolean feature details and optional package/weights
fingerprints. An absent or false feature forbids a corresponding profile claim.
For example, declaring text support does not imply OCR Russian, geometry, tables
or storage deletion. A tokenizer descriptor additionally pins its complete
`TokenizerIdentity`. Hosts MUST fingerprint all relevant package/config/weights
changes; an adapter version alone is not an asset fingerprint.

`Registry\HostProfile` validates the bundled
[`host-profile.schema.json`](../packages/core/src/Registry/host-profile.schema.json)
and all bindings before any factory invocation. Its frozen export includes:

- Profile ID/version; exact port bindings, validated config and capability/feature claims.
- Named parser/search profile versions and signatures, coverage policy and quotas.
- Optional pinned tokenizer and external-owner freshness contract.
- Static adapter versions, capabilities, feature support, config-schema digest,
  required extensions, package and weights fingerprints and tokenizer identities.

Its identity is **untagged SHA256(JCS(strict redacted export))**, using the
existing core JCS implementation. No alternate JSON encoder is used at runtime.
Raw secrets and machine-path settings reject. A JSON config credential is exactly
`{"credentialRef":"host:model","present":true}`: an opaque host handle, never
the credential value. The export records only presence. The host resolves secrets
separately; do not put provider inputs or document bodies in profile config.

`effectiveProfile()` preserves the P1.5 `Factory::create(Domain\EffectiveProfile)`
signature. Its ID is `host:<full HostProfile digest>`, so its own digest also
changes with every host semantic input. The two digests are distinct. Hosts MUST
use `HostProfile::digest()` as the complete usage/selection/cache profile binding;
the projected ID prevents legacy factories from accidentally reusing a narrower
identity. Legacy direct EffectiveProfile callers remain supported. `toWire()`
returns fresh JSON values; `diff()` sorts JSON pointers and never mutates either
profile, including additions of an explicitly null optional field.

`readiness()` returns independent installed/enabled/configured/verified/ready/
failed/unknown diagnostics. It does not call a factory or provider. Verification
requires an explicit, current HostProfile fingerprint supplied by the host's
verification workflow. Changed config, package or weights invalidates an older
approval. Even a verified ready adapter still needs current processing and
egress authorization at the actual external boundary.

## Tokenizer technical contract (D5)

`Port\Tokenizer::capabilities(): TokenizerCapabilities` and
`count(string $utf8, TokenizerIdentity $identity): int` perform exact counting
for the complete ID/version/vocabulary digest. Static descriptor identity,
profile identity and resolved tokenizer capabilities MUST agree. Invalid UTF-8,
unknown ID, mismatched identity or nonexact capability rejects without network
fallback. No bytes/4 estimate is permitted.

`Registry\TokenBudget::require()` always enforces the byte budget. With an
explicit token budget it requires an exact pinned tokenizer, otherwise returns
`incompatible(tokenizer_required)`. With no token budget, byte limits still
apply. This helper does not authorize disclosure or implement the disclosure
transaction.

## Shared plain-PHP corpus

Hosts explicitly load `packages/core/conformance/autoload.php` in test/dev setup.
Production Composer autoload does not load `KnowKit\Conformance`.
`SchemaLoader` loads the host's offline schema bundle; Domain receives the
resulting validator and performs no file access.

Implement `Conformance\AdapterHost::registry()` and `prepare(Fixture, caseId)`.
Every prepare MUST create/reset an isolated test backend. `AdapterRig` supplies
the real port implementations plus fixture controls and observations. Assertions
are owned by the SDK's `AdapterContract`, `CorpusContract` and `RegistryCorpus`;
hosts do not supply passing verdicts. RegistryCorpus cases are SDK self-checks,
separate from target adapter behavior.

| Contract ID | Required fixture behavior |
| --- | --- |
| policy | Current QueryGrant before top-k; Deny/Unknown distinct; tenant and fresh actual disclose boundary checks |
| blob | Durable reservation precedes PUT; immutable digest/length/content; foreign tenant access rejects |
| parser | Named profile, exact canonical UTF-8 IR, source/version/signature and locator provenance; changed profile rejects |
| search | Seed higher-ranked excluded and foreign rows before eligible rows; prefilter before top-k; full vector space mismatch rejects |
| tokenizer | Independently authored vectors for the pinned identity, exact UTF-8 counting, no fallback, retained byte budget |
| jobs | Seed a current fixture lease; reject stale fence, token, tenant, source epoch and expiry for all conditional writes |
| outbox | Append inside a host fixture transaction; duplicate envelope dedupes; expired delivery retries with a new fence; stale/duplicate ack rejects |
| deletion | Unknown/unsupported preserve uncertainty; deleted matches physical absence, independently observed through the backend |
| egress | Denied/unknown outbound query causes zero provider sends; an old Allow never authorizes a later send |
| dependencies | Register an exact primary parent, revoke it, synchronously reject generated supporting evidence |

Controls are `policy`, `egress`, `now`, `deletion`, and `storeArtifacts`.
`storeArtifacts` receives `[Scope, SnapshotRef, schema-valid packet, profileDigest]`.
Observations are `sends`, `blobCount`, and `artifacts` (in insertion order).
The host MUST wire observations to actual effects, not guessed receipts.
The lexical-only scenario MUST omit embedding and graph/dependency extensions.

`manifest.v1.json` pins the fixture bytes, version, rationale, rights, expected
values and required case IDs. The SDK independently retains the v1 mandatory
IDs: a host manifest cannot remove baseline cases. A requested unsupported
contract fails; false optional features are reported as unsupported, never as
skipped baseline tests. `assertComplete()` rejects zero, missing, duplicate,
unexpected, failed and skipped executions. Reports contain IDs, safe typed
failure codes, profile/corpus fingerprints and counts, never exception text,
document content, credentials or raw provider inputs.

The example tokenizer counts Unicode scalar values and is explicitly synthetic;
it is not a model tokenizer. For a different vocabulary, a host can pass a
reviewed versioned manifest via `--manifest` (or AdapterHarness's constructor),
retaining every mandatory case and providing independently authored identity and
token vectors in its digest-bound `fixtures/corpus.v1.json`. Expected digest
changes require a new fixture version and a behavioral rationale. The checked-in
`verify-goldens.py` verifies independently authored preimages and never rewrites
expectations. No external adapter is qualified merely by a correct contract double.

## Project isolation and evidence limits

Projects A/B have the same query and source/version/chunk/selection/usage local
IDs, but different tenant, actor, purpose, snapshot and trusted hostScopeHash.
The corpus also varies actor, purpose, hostScopeHash, snapshot and profile
individually within a tenant. Stored selection/cache/citation/usage/evidence
packets MUST survive without collision or mutation; cross-scope replay rejects.
Packets use the existing schema-bound DTOs, immutable source/version/revision/IR
identity and canonical text spans. Historical citations never resolve to latest.

`hostScopeHash` is supplied verbatim by the trusted host. QueryGrant MUST NOT
widen host eligibility; actual disclose checks remain current. Schema validity,
fingerprints and corpus success do not replace authoritative lifecycle/ancestor
checks, epoch locks or confirmed Usage commit immediately before real disclosure.

The executable [custom adapter example](../examples/custom-adapter/README.md)
is a local fake-host integration. Existing Retrieval/Disclosure interfaces remain
interfaces; this item does not implement their production transactions, a live
Edufy integration, PostgreSQL qualification or a provider. The reuse receipt is
staged at `tests/conformance/adapter-sdk/evidence/P1.6-reuse.json` for coordinator
registration; no native plan state is written by the executor.

Source qualification precedes implementation: the staged receipt records all
five pinned candidates, verified SHA256 values, selected symbols and decisions.
DeepTutor metadata/readiness and discovery-test concepts (copyright 2025 Data
Intelligence Lab, The University of Hong Kong, Apache-2.0) are adapted to explicit
PHP registration and value-free diagnostics. AgenTools exact-space concepts
(copyright 2026 Dmitry Vostrikov, proprietary private local reuse authority) reuse
the already approved eight-axis KnowKit space implementation; its six-axis donor
wire shape is rejected. Existing license texts/notices are retained. No donor
runtime, package installer, Python dependency, weights or dataset is transferred.
