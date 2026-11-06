# ADR-0002: Immutable identity and canonical authority

Status: accepted

Decision source: D1 and D2; approved consolidated Opus 5.5 architecture, with exact normative refinements. This ADR records the accepted design and makes no new product choice or implementation approval.

Record the approved distinction between immutable original, processing revision, chunkset and index generation. Search projections and mutable active pointers cannot redefine historical citation identity.

The following normative anchors govern this decision:

- [ARCH-TENANT — Tenant persistence boundary](../../specs/architecture/architecture.md#arch-tenant)
- [ARCH-VERSION-SOURCE — Source version axis](../../specs/architecture/architecture.md#arch-version-source)
- [ARCH-VERSION-DOCUMENT — Document revision axis](../../specs/architecture/architecture.md#arch-version-document)
- [ARCH-VERSION-CHUNKSET — Chunkset axis](../../specs/architecture/architecture.md#arch-version-chunkset)
- [ARCH-VERSION-GENERATION — Index generation axis](../../specs/architecture/architecture.md#arch-version-generation)
- [ARCH-CITATION — Immutable citations](../../specs/architecture/architecture.md#arch-citation)
- [ARCH-CANONICAL — Canonical authority](../../specs/architecture/architecture.md#arch-canonical)
- [ARCH-SNAPSHOT — Exact publication snapshot](../../specs/architecture/architecture.md#arch-snapshot)
- [ARCH-LINEAGE — Exact derivative parents](../../specs/architecture/architecture.md#arch-lineage)
- [ARCH-IR — IR and evidence semantics](../../specs/architecture/architecture.md#arch-ir)
- [ARCH-PROCESSING — Exact processing identity](../../specs/architecture/architecture.md#arch-processing)
- [ARCH-MIGRATION — History and migration limits](../../specs/architecture/architecture.md#arch-migration)

Consequences: adapters and future implementations must preserve these boundaries. Architecture checking is executable without Composer; runtime, host/provider and real PostgreSQL qualification remain with their owning implementation items. No unresolved blocking decision is introduced.

See [architecture authority](../architecture/README.md) and [threat model](../architecture/threat-model.md).
