# Reference-only selection

`Normalizer` retains the original query and typed filters. Its optional rewrite
preserves exact identifiers, quoted phrases, numbers and textual filter tokens.
`QueryBinding` uses the approved query-binding hash domain and includes the exact
effective profile, evidence limits and embedding space fingerprint.

The host resolves latest once to a `SnapshotRef` before calling `Selections`.
`PolicyChecks::authorizeQuery` intersects host eligibility, current grants and
typed filters before the backend top-k. `PdoSelectionCatalog` verifies the sealed
snapshot digest, generation, memberships, manifests and canonical span buffers.
`BoundedSelector` deduplicates exact refs and canonical span overlap in rank/ID
order, then accepts whole units within all limits. Context headings, table
headers, units and notes count against byte and exact tokenizer limits. Binary
assets are counted, with their bounded binary read handled separately. Omitted
table groups have explicit exact read refs; no row or UTF-8 slicing occurs.

The result contains a schema-bound `Selection`, warnings, separate-read refs and
an opaque CSPRNG256 token. Only its raw-byte SHA256 and immutable private record
are stored. `PdoSelections` uses tenant/host-qualified SQL and verifies duplicated
identity columns and exact snapshot identity on each persistence boundary.
Migration 008 adds the private record alongside the existing public references.
Legacy rows without an original record reject reuse. `InMemorySelections` is a
process-local reference adapter, not a shared or persistent deployment store.

`requireBound` validates the token, original/normalized query binding, host
identity/audience/grants, policy epoch, run, exact snapshot and expiry. It returns
references only and grants no disclosure right. The disclosure caller must still
perform current lifecycle/ACL checks under guards and commit a new usage before
returning canonical evidence. The selection regressions exercise this separation
with the existing `PolicyChecks`. The PostgreSQL integration consumer also
commits exact usage refs/check times before returning canonical evidence and
denies reuse after an authoritative source revoke. Selection does not implement
the production disclosure transaction or treat historical refs as authorization.

The authored v1 corpus includes a Python-derived binding golden vector. Expected
digest changes require a new fixture version with a behavioral rationale.
Reuse decisions and the six verified donor hashes are in
`tests/retrieval/selection/evidence/P4.1-reuse.json`.
