# ADR-0001: Independent core and delivery

Status: accepted

Decision source: D1 and D2; approved consolidated Opus 5.5 architecture, with exact normative refinements. This ADR records the accepted design and makes no new product choice or implementation approval.

Record D1 and the final D2 package refinement. One vendor-free core preserves independent library/CLI delivery while optional adapters remain explicit.

The following normative anchors govern this decision:

- [ARCH-AUTHORITY — Authority and scope](../../specs/architecture/architecture.md#arch-authority)
- [ARCH-CORE — Independent core](../../specs/architecture/architecture.md#arch-core)
- [ARCH-LAYERS — Dependency direction](../../specs/architecture/architecture.md#arch-layers)
- [ARCH-PACKAGE-DAG — One package DAG](../../specs/architecture/architecture.md#arch-package-dag)
- [ARCH-DELIVERY — Library and thin transports](../../specs/architecture/architecture.md#arch-delivery)
- [ARCH-MILESTONES — Approved roadmap](../../specs/architecture/architecture.md#arch-milestones)
- [ARCH-INSTALL — Explicit install and registration](../../specs/architecture/architecture.md#arch-install)
- [ARCH-REGISTRY — Adapter registry](../../specs/architecture/architecture.md#arch-registry)
- [ARCH-PROFILE — Immutable effective profiles](../../specs/architecture/architecture.md#arch-profile)
- [ARCH-WORKER — Isolated worker](../../specs/architecture/architecture.md#arch-worker)
- [ARCH-CONNECTORS — Optional source connectors](../../specs/architecture/architecture.md#arch-connectors)
- [ARCH-NAVIGATION — Exact source navigation](../../specs/architecture/architecture.md#arch-navigation)

Consequences: adapters and future implementations must preserve these boundaries. Architecture checking is executable without Composer; runtime, host/provider and real PostgreSQL qualification remain with their owning implementation items. No unresolved blocking decision is introduced.

See [architecture authority](../architecture/README.md) and [threat model](../architecture/threat-model.md).
