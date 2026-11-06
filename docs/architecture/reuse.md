# Source reuse qualification for P1.1

Qualification precedes new checker code. All six assigned blobs were inspected at the exact commits below and their SHA256 values matched the execution-input. AgenTools blobs were read from pinned local Git objects; DeepTutor blobs were read through the GitHub connector. No donor repository was modified.

| Source | Pin | Decision | Applied contract |
| --- | --- | --- | --- |
| AgenTools `extensions/knowledge/composer.json` | `2054acda51eb69eca1579c78900858c5ff3de01e` | adapt | PHP >=8.3, no vendor runtime dependencies, explicit host wiring |
| AgenTools `extensions/knowledge/tests/suite.py::run` | same pin | adapt | mandatory case inventory, child failures, input stability |
| DeepTutor `.importlinter` | `f7aed0744c36c5afef16a4261c31ebee1208cac2` | adapt | forbidden imports and adapter dependency direction |
| DeepTutor `tests/runtime/test_import_layering.py` | same pin | adapt | fresh-process regressions independent of import cache |
| DeepTutor `LICENSE` | same pin | reuse as-is | [pinned license](third-party/DeepTutor-LICENSE.txt) |
| DeepTutor `THIRD_PARTY_NOTICES.md` | same pin | reuse as-is | [pinned notices](third-party/DeepTutor-THIRD_PARTY_NOTICES.md) |

AgenTools code is proprietary. The owner authorized local source adaptation; this does not grant public relicensing or release permission. The checker adapts the suite's control flow rather than importing its workspace, platform_gate, unit/no_dev helpers or Composer dependencies. Donor suites were not executed or claimed compatible.

DeepTutor's root license is Apache-2.0, copyright 2025 Data Intelligence Lab, The University of Hong Kong. The forbidden-dependency and fresh-process patterns are adapted to a new PHP architecture manifest and stdlib unittest harness. The modified targets are `tools/check-spec-contract.py` and `tests/contract/architecture/test_checker.py`. Pinned license and notices are retained above; OAuth, messaging and web assets mentioned in those notices are not imported.

New glue is necessary because neither donor defines KnowKit's approved package graph, mandatory operation/invariant anchors, ADR links, four independent version axes or the distinction between local serialization and external-owner freshness. Contract model fixtures are synthetic project-authored data. Their provenance, digest rationale and expected outcomes live in the versioned fixture manifest.

The execution receipt `P1.1-reuse.json` records exact source digests, qualification limits and final target digest. No source gap remains. This qualification does not establish PostgreSQL concurrency, clean-install runtime behavior or host/provider readiness.
