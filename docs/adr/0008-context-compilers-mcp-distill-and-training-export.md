# ADR-0008: Context compilers, MCP bridge, distill, and training export

Status: accepted

Decision source: KnowKit generalized context architecture (W0.1); extends D1/D2 with optional `context-compilers`, `mcp-bridge`, `distill`, and `training-export` packages while preserving vendor-free `knowkit/core`.

Prompt/context compilation is factored into `knowkit/context-compilers` so `answer`, `agentools-bridge`, `mcp-bridge`, and `training-export` share a single compiler boundary without introducing package cycles. External MCP servers and tools cross an untrusted boundary (`UntrustedEvidence`), MCP Server `Scope` is derived strictly from transport authentication, and training data export (`training_export`) is fail-closed on `training_allowed`, PII redaction, and a cryptographic export manifest.

The following normative anchors govern this decision:

- [ARCH-MCP-BOUNDARY — MCP server scope and untrusted external MCP evidence](../../specs/architecture/architecture.md#arch-mcp-boundary)
- [ARCH-TRAINING-RIGHTS — Fail-closed training export rights and manifest](../../specs/architecture/architecture.md#arch-training-rights)

Consequences: any package importing `KnowKit\ContextCompilers\*` MUST declare `context-compilers` in `dependsOn`; `knowkit/core` remains zero-vendor and independent of all optional packages.

See [architecture authority](../architecture/README.md) and [threat model](../architecture/threat-model.md).
