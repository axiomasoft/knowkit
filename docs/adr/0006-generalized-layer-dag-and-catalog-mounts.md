# ADR-0006: Generalized layer DAG, catalog mounts, and directive promotion

Status: accepted

Decision source: KnowKit generalized context architecture (W0.1); extends D1/D2 with domain-independent layer composition, unidirectional read-only catalog mounts, and explicit directive promotion gates.

Context resolution may compose multiple ordered context layers as a domain-independent directed acyclic graph (DAG) with deterministic topological ordering, monotonic `lockedKeys` protection, and `ExclusionDeny` precedence. Shared read-only catalogs may be mounted across tenants only from explicitly designated `shared_catalog_readonly` tenants, and untrusted documents or worker outputs MUST NOT be auto-promoted into `TrustedDirective`.

The following normative anchors govern this decision:

- [ARCH-LAYER-DAG — Domain-independent layer DAG and monotonic locks](../../specs/architecture/architecture.md#arch-layer-dag)
- [ARCH-CATALOG-MOUNT — Unidirectional read-only catalog mounts](../../specs/architecture/architecture.md#arch-catalog-mount)
- [ARCH-DIRECTIVE-PROMOTION — Explicit directive promotion gate](../../specs/architecture/architecture.md#arch-directive-promotion)

Consequences: layer resolution, catalog mounting, and directive promotion are explicit audited operations (`layer_resolve`, `catalog_mount`, `directive_promote`) governed by `AccessPolicy` and lineage tracking (`derived_from`).

See [architecture authority](../architecture/README.md) and [threat model](../architecture/threat-model.md).
