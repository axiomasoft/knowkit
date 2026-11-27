# ADR-0007: Multi-resolution artifacts, pruning integrity, and disclosure modes

Status: accepted

Decision source: KnowKit generalized context architecture (W0.1); extends D1/D2 with immutable multi-resolution derived artifacts (`R0`–`R2`), verifiable span-preserving pruning, and physical R3 blocking under restricted disclosure modes.

Derived representations (`R0`–`R2`) are immutable artifacts bound to exact `DocumentRevisionRef` and `DependencyStore` lineage, protected by `AtomicSpanGuard` so atomic spans cannot be split. Disclosure modes (`derived_only`, `summary_only`, `metadata_only`, `index_only`) physically block raw `R3` text in both `FreshDisclosure` and `SourceNavigation`, while `ContextPruner` may only select verifiable UTF-8 sub-spans without rewriting text.

The following normative anchors govern this decision:

- [ARCH-MULTI-RES — Immutable multi-resolution derived artifacts](../../specs/architecture/architecture.md#arch-multi-res)
- [ARCH-DISCLOSURE-MODES — Physical R3 blocking in restricted disclosure modes](../../specs/architecture/architecture.md#arch-disclosure-modes)
- [ARCH-PRUNING-INTEGRITY — Verifiable span-preserving context pruning](../../specs/architecture/architecture.md#arch-pruning-integrity)

Consequences: derived artifacts never substitute for canonical `R3` authority when `R3` is required, and restricted disclosure modes fail closed against `R3` leakage across both retrieval/disclosure and navigation paths.

See [architecture authority](../architecture/README.md) and [threat model](../architecture/threat-model.md).
