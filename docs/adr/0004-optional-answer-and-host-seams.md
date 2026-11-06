# ADR-0004: Optional answer and host ownership

Status: accepted

Decision source: D2; approved consolidated Opus 5.5 architecture, with exact normative refinements. This ADR records the accepted design and makes no new product choice or implementation approval.

Record D2's optional single-turn answer and Chatom direct route while preserving the existing Agentools loop and host-owned Edufy domain.

The following normative anchors govern this decision:

- [ARCH-ANSWER — Optional single-turn answer](../../specs/architecture/architecture.md#arch-answer)
- [ARCH-MODEL-BINDING — Immutable model binding](../../specs/architecture/architecture.md#arch-model-binding)
- [ARCH-ANSWER-REPLAY — Direct answer replay](../../specs/architecture/architecture.md#arch-answer-replay)
- [ARCH-CHATOM — Chatom history and delivery](../../specs/architecture/architecture.md#arch-chatom)
- [ARCH-ROUTES — Per-binding route guard](../../specs/architecture/architecture.md#arch-routes)
- [ARCH-BRIDGE — Existing Agentools loop](../../specs/architecture/architecture.md#arch-bridge)
- [ARCH-BRIDGE-USAGE — Bridge scope and fresh evidence](../../specs/architecture/architecture.md#arch-bridge-usage)
- [ARCH-EDUFY — Host-owned Edufy](../../specs/architecture/architecture.md#arch-edufy)

Consequences: adapters and future implementations must preserve these boundaries. Architecture checking is executable without Composer; runtime, host/provider and real PostgreSQL qualification remain with their owning implementation items. No unresolved blocking decision is introduced.

See [architecture authority](../architecture/README.md) and [threat model](../architecture/threat-model.md).
