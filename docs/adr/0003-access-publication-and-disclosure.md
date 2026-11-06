# ADR-0003: Access, publication and disclosure guarantees

Status: accepted

Decision source: D1 and D2; approved consolidated Opus 5.5 architecture, with exact normative refinements. This ADR records the accepted design and makes no new product choice or implementation approval.

Record authoritative current access and lifecycle checks, bounded local transactions and the precise limits of local serialization. Owner freshness and external effects remain separate contracts.

The following normative anchors govern this decision:

- [ARCH-INGESTION-REGISTER — Register source](../../specs/architecture/architecture.md#arch-ingestion-register)
- [ARCH-INGESTION-VERSION — Add source version](../../specs/architecture/architecture.md#arch-ingestion-version)
- [ARCH-BUILD-GENERATION — Build generation](../../specs/architecture/architecture.md#arch-build-generation)
- [ARCH-SEAL — Seal generation](../../specs/architecture/architecture.md#arch-seal)
- [ARCH-PUBLISH — Publish snapshot](../../specs/architecture/architecture.md#arch-publish)
- [ARCH-RETRIEVE — Retrieve selection](../../specs/architecture/architecture.md#arch-retrieve)
- [ARCH-SELECTION — Reference-only selection](../../specs/architecture/architecture.md#arch-selection)
- [ARCH-DISCLOSE — Disclose evidence](../../specs/architecture/architecture.md#arch-disclose)
- [ARCH-DISCLOSURE-COMMIT — Disclosure linearization](../../specs/architecture/architecture.md#arch-disclosure-commit)
- [ARCH-DISCLOSURE-REPLAY — Attempt replay and uncertain commit](../../specs/architecture/architecture.md#arch-disclosure-replay)
- [ARCH-SCOPE — Trusted scope](../../specs/architecture/architecture.md#arch-scope)
- [ARCH-OPERATIONS — Operation is separate from purpose](../../specs/architecture/architecture.md#arch-operations)
- [ARCH-POLICY — Authoritative intersected access](../../specs/architecture/architecture.md#arch-policy)
- [ARCH-POLICY-EPOCHS — Policy binding and changes](../../specs/architecture/architecture.md#arch-policy-epochs)
- [ARCH-EXTERNAL-WINDOW — External-owner limitation](../../specs/architecture/architecture.md#arch-external-window)
- [ARCH-EGRESS — Complete classified egress](../../specs/architecture/architecture.md#arch-egress)
- [ARCH-LOCKS — Global lock order](../../specs/architecture/architecture.md#arch-locks)
- [ARCH-TENANT-BARRIER — Unseen source and membership barrier](../../specs/architecture/architecture.md#arch-tenant-barrier)
- [ARCH-NO-IO-IN-TX — Bounded local transactions](../../specs/architecture/architecture.md#arch-no-io-in-tx)
- [ARCH-LIFECYCLE — Correction, revoke and hold](../../specs/architecture/architecture.md#arch-lifecycle)
- [ARCH-PURGE — Request purge](../../specs/architecture/architecture.md#arch-purge)
- [ARCH-RESTORE — Restore and reconcile](../../specs/architecture/architecture.md#arch-restore)
- [ARCH-JOBS — Durable jobs and fencing](../../specs/architecture/architecture.md#arch-jobs)
- [ARCH-OUTBOX — Committed events](../../specs/architecture/architecture.md#arch-outbox)
- [ARCH-CATALOG — Catalog services](../../specs/architecture/architecture.md#arch-catalog)
- [ARCH-INGEST-JOBS — Ingest job services](../../specs/architecture/architecture.md#arch-ingest-jobs)
- [ARCH-READING — Citation and preview reading](../../specs/architecture/architecture.md#arch-reading)
- [ARCH-USAGE — Usage and acknowledgments](../../specs/architecture/architecture.md#arch-usage)
- [ARCH-DIAGNOSTICS — Diagnostics services](../../specs/architecture/architecture.md#arch-diagnostics)
- [ARCH-OUTCOMES — Six typed outcomes](../../specs/architecture/architecture.md#arch-outcomes)
- [ARCH-RETRIEVAL-INTEGRITY — Candidates, fusion and rerank](../../specs/architecture/architecture.md#arch-retrieval-integrity)
- [ARCH-BUDGETS — Bounded disclosure budgets](../../specs/architecture/architecture.md#arch-budgets)
- [ARCH-MUTATIONS — Safe commands](../../specs/architecture/architecture.md#arch-mutations)
- [ARCH-UNTRUSTED — Untrusted evidence and logs](../../specs/architecture/architecture.md#arch-untrusted)

Consequences: adapters and future implementations must preserve these boundaries. Architecture checking is executable without Composer; runtime, host/provider and real PostgreSQL qualification remain with their owning implementation items. No unresolved blocking decision is introduced.

See [architecture authority](../architecture/README.md) and [threat model](../architecture/threat-model.md).
