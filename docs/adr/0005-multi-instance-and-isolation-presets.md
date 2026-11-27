# ADR-0005: Multi-instance isolation and isolation presets

Status: accepted

Decision source: KnowKit generalized context architecture (W0.1); extends D1/D2 with multi-instance process isolation and tenant isolation presets without breaking core independence or single-instance defaults.

Multiple named KnowKit instances (`InstanceId`) may coexist inside a single PHP process (for example, separate knowledge planes, shared catalogs, or isolated workspaces) without sharing mutable state, connection pools, registries, caches, or policy epochs across instance boundaries.

The following normative anchors govern this decision:

- [ARCH-INSTANCES — Multi-instance process isolation](../../specs/architecture/architecture.md#arch-instances)

Consequences: registries, runtime configuration, and storage bindings MUST be scoped by `InstanceId` rather than static globals or process-wide singletons. Existing single-instance hosts remain valid with a default `InstanceId`.

See [architecture authority](../architecture/README.md) and [threat model](../architecture/threat-model.md).
