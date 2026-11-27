#!/usr/bin/env python3
"""P1.1 stdlib architecture gate; no application/backend qualification.

Adapted patterns: AgenTools suite.run (2054acda51eb69eca1579c78900858c5ff3de01e),
DeepTutor .importlinter (f7aed0744c36c5afef16a4261c31ebee1208cac2).
Modified for KnowKit's independent approved DAG, anchors and child case receipts.
Provenance and retained notices: docs/architecture/reuse.md.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile

GROUP = "contract:architecture"
SPEC = "specs/architecture/manifest.v1.json"
CORPUS = "tests/contract/architecture/fixtures/v1/manifest.json"
GROUP_FILE = "tests/fixtures/check-groups/contract-architecture.json"
TEST_DIR = "tests/contract/architecture"
APPROVED_PACKAGES = {
    "core": {
        "path": "packages/core",
        "name": "knowkit/core",
        "namespace": "KnowKit\\",
        "minPhp": "8.3",
        "dependsOn": [],
        "externalRuntime": [],
        "extensions": [
            "ext-pdo",
            "ext-json",
            "ext-mbstring"
        ],
        "adapterExtensions": {
            "Postgres": [
                "ext-pdo_pgsql"
            ]
        }
    },
    "answer": {
        "path": "packages/answer",
        "name": "knowkit/answer",
        "namespace": "KnowKit\\Answer\\",
        "minPhp": "8.3",
        "dependsOn": [
            "core",
            "context-compilers"
        ],
        "externalRuntime": [
            "psr/http-client:^1",
            "psr/http-factory:^1"
        ]
    },
    "context-compilers": {
        "path": "packages/context-compilers",
        "name": "knowkit/context-compilers",
        "namespace": "KnowKit\\ContextCompilers\\",
        "minPhp": "8.3",
        "dependsOn": [
            "core"
        ],
        "externalRuntime": []
    },
    "mcp-bridge": {
        "path": "packages/mcp-bridge",
        "name": "knowkit/mcp-bridge",
        "namespace": "KnowKit\\Bridge\\Mcp\\",
        "minPhp": "8.3",
        "dependsOn": [
            "core",
            "context-compilers"
        ],
        "externalRuntime": []
    },
    "distill": {
        "path": "packages/distill",
        "name": "knowkit/distill",
        "namespace": "KnowKit\\Distill\\",
        "minPhp": "8.3",
        "dependsOn": [
            "core"
        ],
        "externalRuntime": [
            "psr/http-client:^1",
            "psr/http-factory:^1"
        ]
    },
    "training-export": {
        "path": "packages/training-export",
        "name": "knowkit/training-export",
        "namespace": "KnowKit\\TrainingExport\\",
        "minPhp": "8.3",
        "dependsOn": [
            "core",
            "context-compilers"
        ],
        "externalRuntime": []
    },
    "pgvector": {
        "path": "packages/pgvector",
        "name": "knowkit/pgvector",
        "namespace": "KnowKit\\PgVector\\",
        "minPhp": "8.3",
        "dependsOn": [
            "core"
        ],
        "externalRuntime": []
    },
    "http": {
        "path": "packages/http",
        "name": "knowkit/http",
        "namespace": "KnowKit\\Http\\",
        "minPhp": "8.3",
        "dependsOn": [
            "core"
        ],
        "externalRuntime": [
            "PSR-7 interfaces",
            "PSR-15 interfaces"
        ]
    },
    "laravel": {
        "path": "packages/laravel",
        "name": "knowkit/laravel",
        "namespace": "KnowKit\\Laravel\\",
        "minPhp": "8.3",
        "dependsOn": [
            "core"
        ],
        "suggests": [
            "http",
            "answer"
        ],
        "externalRuntime": [
            "explicit Laravel host"
        ]
    },
    "chatom": {
        "path": "packages/chatom",
        "name": "knowkit/chatom",
        "namespace": "KnowKit\\Chatom\\",
        "minPhp": "8.4",
        "dependsOn": [
            "core",
            "answer"
        ],
        "externalRuntime": [
            "explicit Chatom contracts",
            "Laravel/PHP>=8.4 host"
        ]
    },
    "agentools-bridge": {
        "path": "packages/agentools-bridge",
        "name": "knowkit/agentools-bridge",
        "namespace": "KnowKit\\Bridge\\AgenTools\\",
        "minPhp": "8.4",
        "dependsOn": [
            "core",
            "context-compilers"
        ],
        "externalRuntime": [
            "Agentools core",
            "Agentools platform-chatom seam"
        ]
    }
}
APPROVED_LAYERS = {
    "Domain": [],
    "Port": [
        "Domain"
    ],
    "Registry": [
        "Domain",
        "Port"
    ],
    "Application": [
        "Domain",
        "Port",
        "Registry"
    ],
    "Adapter": [
        "Domain",
        "Port"
    ]
}
APPROVED_MILESTONES = {
    "v0.1": [
        "core",
        "hostScopeHash",
        "registry",
        "navigation",
        "connectors-port"
    ],
    "v0.2": [
        "answer",
        "edufy-example"
    ],
    "v0.3": [
        "pgvector"
    ],
    "v0.4": [
        "python-worker",
        "process-worker-supervisor"
    ],
    "v0.5": [
        "http",
        "laravel",
        "chatom-direct"
    ],
    "v0.6": [
        "agentools-bridge"
    ],
    "v1.0": [
        "contract-freeze"
    ]
}
REQUIRED_ANCHORS = {
    "ARCH-AUTHORITY": {
        "kind": "invariant",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "Consolidated",
            "P1.4"
        ]
    },
    "ARCH-CORE": {
        "kind": "boundary",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "knowkit/core",
            "PHP >=8.3",
            "zero vendor",
            "Adapter"
        ]
    },
    "ARCH-LAYERS": {
        "kind": "invariant",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "Domain",
            "Port",
            "Application",
            "Registry",
            "Adapter",
            "PSR HTTP"
        ]
    },
    "ARCH-PACKAGE-DAG": {
        "kind": "invariant",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "manifest.v1.json",
            "agentools-bridge",
            "knowkit/edufy"
        ]
    },
    "ARCH-DELIVERY": {
        "kind": "delivery",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "CLI",
            "HTTP",
            "PHP/PostgreSQL/TXT"
        ]
    },
    "ARCH-MILESTONES": {
        "kind": "delivery",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "v0.4",
            "ProcessWorkerSupervisor",
            "v0.6"
        ]
    },
    "ARCH-INSTALL": {
        "kind": "invariant",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "autodiscovery",
            "explicit",
            "unknown"
        ]
    },
    "ARCH-REGISTRY": {
        "kind": "operation",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "AdapterDescriptor",
            "register(AdapterDescriptor,Factory)",
            "describe",
            "resolve"
        ]
    },
    "ARCH-PROFILE": {
        "kind": "invariant",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "EffectiveProfile",
            "CredentialRef",
            "SHA256",
            "unknown"
        ]
    },
    "ARCH-WORKER": {
        "kind": "boundary",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "subprocess",
            "not HTTP",
            "v0.4",
            "independently",
            "fencing"
        ]
    },
    "ARCH-CONNECTORS": {
        "kind": "invariant",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "SourceConnector",
            "CrawlSnapshot",
            "complete"
        ]
    },
    "ARCH-NAVIGATION": {
        "kind": "invariant",
        "adr": "docs/adr/0001-independent-core-and-delivery.md",
        "tokens": [
            "16",
            "pinned",
            "ext-intl"
        ]
    },
    "ARCH-TENANT": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "tenant",
            "immutable",
            "another tenant"
        ]
    },
    "ARCH-VERSION-SOURCE": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "SourceVersionId",
            "SHA256(bytes)",
            "conflict"
        ]
    },
    "ARCH-VERSION-DOCUMENT": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "DocumentRevisionId",
            "OCR",
            "SHA256(JCS(IR))"
        ]
    },
    "ARCH-VERSION-CHUNKSET": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "ChunkSetId",
            "Rechunking",
            "spans"
        ]
    },
    "ARCH-VERSION-GENERATION": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "GenerationId",
            "sealed",
            "historical"
        ]
    },
    "ARCH-CITATION": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "UTF-8",
            "half-open",
            "latest",
            "IR digest"
        ]
    },
    "ARCH-CANONICAL": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "canonical",
            "projections",
            "fail closed"
        ]
    },
    "ARCH-SNAPSHOT": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "latest",
            "PublicationSnapshot",
            "pointerVersion"
        ]
    },
    "ARCH-LINEAGE": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "same-tenant",
            "parent-set",
            "cycles"
        ]
    },
    "ARCH-IR": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "1-based",
            "generated",
            "entailment"
        ]
    },
    "ARCH-PROCESSING": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "ProcessingIdentity",
            "embedding",
            "credentials"
        ]
    },
    "ARCH-INGESTION-REGISTER": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "registerSource",
            "tenant barrier",
            "tombstones"
        ]
    },
    "ARCH-INGESTION-VERSION": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "addVersion",
            "before PUT",
            "Uncertain"
        ]
    },
    "ARCH-BUILD-GENERATION": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "buildGeneration",
            "staging",
            "readiness"
        ]
    },
    "ARCH-SEAL": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "SealGeneration",
            "IndexReadabilityProbe",
            "outbox"
        ]
    },
    "ARCH-PUBLISH": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "publish",
            "CAS",
            "rollback",
            "unsealed"
        ]
    },
    "ARCH-RETRIEVE": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "authorizeQuery",
            "QueryGrant",
            "ORDER BY/LIMIT",
            "postfilter"
        ]
    },
    "ARCH-SELECTION": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "hostScopeHash",
            "expiry",
            "300",
            "EvidenceSet"
        ]
    },
    "ARCH-DISCLOSE": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "DisclosureRequest",
            "disclosureAttemptId",
            "usageContext",
            "runRef=null"
        ]
    },
    "ARCH-DISCLOSURE-COMMIT": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "preload",
            "shared",
            "prepared_disclosure",
            "COMMIT"
        ]
    },
    "ARCH-DISCLOSURE-REPLAY": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "metadata only",
            "unknown",
            "retract"
        ]
    },
    "ARCH-SCOPE": {
        "kind": "boundary",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "host-created",
            "64hex",
            "verbatim",
            "scope_mismatch"
        ]
    },
    "ARCH-OPERATIONS": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "Operation",
            "model_egress_context",
            "legal_hold_set",
            "provider_acceptance"
        ]
    },
    "ARCH-POLICY": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "AccessPolicy::decide",
            "DenyAll",
            "Unknown"
        ]
    },
    "ARCH-POLICY-EPOCHS": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "tenantPolicyEpoch",
            "kbPolicyEpoch",
            "sourcePolicyEpoch",
            "POLICY_CHANGED"
        ]
    },
    "ARCH-EXTERNAL-WINDOW": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "ownerRevision",
            "freshness",
            "unknown",
            "distributed atomicity"
        ]
    },
    "ARCH-EGRESS": {
        "kind": "boundary",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "EgressRequest",
            "QUESTION",
            "manifestDigest",
            "providerId",
            "Core MUST NOT import answer DTOs"
        ]
    },
    "ARCH-LOCKS": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "tenant",
            "sorted KB",
            "sorted Source",
            "IndexGeneration",
            "Job",
            "READ COMMITTED"
        ]
    },
    "ARCH-TENANT-BARRIER": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "FOR UPDATE",
            "future versions",
            "Tombstones"
        ]
    },
    "ARCH-NO-IO-IN-TX": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "SQL",
            "policy callbacks",
            "remote"
        ]
    },
    "ARCH-LIFECYCLE": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "correct",
            "revoke",
            "placeLegalHold",
            "history hashes"
        ]
    },
    "ARCH-PURGE": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "requestPurge",
            "backup-retention",
            "unknown",
            "Externally"
        ]
    },
    "ARCH-RESTORE": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "ReplayTombstones",
            "complete",
            "ReconcileOrphans"
        ]
    },
    "ARCH-JOBS": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "JobQueue",
            "fencing",
            "exactly-once"
        ]
    },
    "ARCH-OUTBOX": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "Outbox::append",
            "OutboxDelivery",
            "at-least-once"
        ]
    },
    "ARCH-CATALOG": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "Catalog::",
            "getMetadata",
            "separate"
        ]
    },
    "ARCH-INGEST-JOBS": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "IngestJobs::",
            "ProcessDocumentRevision",
            "BuildChunkSet"
        ]
    },
    "ARCH-READING": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "Reading::",
            "readAsset",
            "public blob URL"
        ]
    },
    "ARCH-USAGE": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "Usage::",
            "provider_accepted",
            "hostScopeHash"
        ]
    },
    "ARCH-DIAGNOSTICS": {
        "kind": "operation",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "Diagnostics::",
            "allowPartial",
            "coverage"
        ]
    },
    "ARCH-OUTCOMES": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "success",
            "empty",
            "denied",
            "unknown",
            "unavailable",
            "incompatible",
            "effectiveProfile"
        ]
    },
    "ARCH-RETRIEVAL-INTEGRITY": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "RRF",
            "FTS",
            "BM25",
            "Rerank",
            "EvidenceDiversifier"
        ]
    },
    "ARCH-BUDGETS": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "4096",
            "tokenizer_required",
            "entire payload"
        ]
    },
    "ARCH-MUTATIONS": {
        "kind": "invariant",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "idempotency",
            "fingerprint",
            "SSRF"
        ]
    },
    "ARCH-UNTRUSTED": {
        "kind": "boundary",
        "adr": "docs/adr/0003-access-publication-and-disclosure.md",
        "tokens": [
            "untrusted",
            "system/developer",
            "Logs"
        ]
    },
    "ARCH-ANSWER": {
        "kind": "boundary",
        "adr": "docs/adr/0004-optional-answer-and-host-seams.md",
        "tokens": [
            "GroundedAnswering",
            "ScriptedTransport",
            "unavailable"
        ]
    },
    "ARCH-MODEL-BINDING": {
        "kind": "invariant",
        "adr": "docs/adr/0004-optional-answer-and-host-seams.md",
        "tokens": [
            "first accepted",
            "hostScopeHash",
            "fingerprint"
        ]
    },
    "ARCH-ANSWER-REPLAY": {
        "kind": "invariant",
        "adr": "docs/adr/0004-optional-answer-and-host-seams.md",
        "tokens": [
            "ledger",
            "unknown/sending",
            "BEFORE",
            "Denial"
        ]
    },
    "ARCH-CHATOM": {
        "kind": "boundary",
        "adr": "docs/adr/0004-optional-answer-and-host-seams.md",
        "tokens": [
            "ViewerMessageReader",
            "ConversationBindingResolver",
            "reauthorizes"
        ]
    },
    "ARCH-ROUTES": {
        "kind": "invariant",
        "adr": "docs/adr/0004-optional-answer-and-host-seams.md",
        "tokens": [
            "tenant,space,channel,bot,binding",
            "direct_chatom",
            "agentools_orchestrated"
        ]
    },
    "ARCH-BRIDGE": {
        "kind": "boundary",
        "adr": "docs/adr/0004-optional-answer-and-host-seams.md",
        "tokens": [
            "EvidenceContextContributor",
            "CurrentSourceAuthority",
            "ReadContextCompiler",
            "BEFORE"
        ]
    },
    "ARCH-BRIDGE-USAGE": {
        "kind": "invariant",
        "adr": "docs/adr/0004-optional-answer-and-host-seams.md",
        "tokens": [
            "scopeHash",
            "usageContext",
            "memory_activation=false",
            "invocation/snapshot"
        ]
    },
    "ARCH-EDUFY": {
        "kind": "boundary",
        "adr": "docs/adr/0004-optional-answer-and-host-seams.md",
        "tokens": [
            "KbResolver",
            "eligibility",
            "MaterialPassageReader",
            "typed fakes"
        ]
    },
    "ARCH-MIGRATION": {
        "kind": "invariant",
        "adr": "docs/adr/0002-immutable-identity-and-authority.md",
        "tokens": [
            "wire/semantic/storage/citation",
            "tombstones",
            "legacy"
        ]
    },
    "ARCH-INSTANCES": {
        "kind": "invariant",
        "adr": "docs/adr/0005-multi-instance-and-isolation-presets.md",
        "tokens": [
            "InstanceId",
            "isolated",
            "process",
            "shared_catalog_readonly"
        ]
    },
    "ARCH-LAYER-DAG": {
        "kind": "invariant",
        "adr": "docs/adr/0006-generalized-layer-dag-and-catalog-mounts.md",
        "tokens": [
            "Kahn",
            "acyclic",
            "lockedKeys",
            "ExclusionDeny"
        ]
    },
    "ARCH-CATALOG-MOUNT": {
        "kind": "boundary",
        "adr": "docs/adr/0006-generalized-layer-dag-and-catalog-mounts.md",
        "tokens": [
            "read-only",
            "shared_catalog_readonly",
            "sealed",
            "unidirectional"
        ]
    },
    "ARCH-DIRECTIVE-PROMOTION": {
        "kind": "invariant",
        "adr": "docs/adr/0006-generalized-layer-dag-and-catalog-mounts.md",
        "tokens": [
            "TrustedDirective",
            "derived_from",
            "directive_promote",
            "auto-promotion"
        ]
    },
    "ARCH-MULTI-RES": {
        "kind": "invariant",
        "adr": "docs/adr/0007-multi-resolution-artifacts-pruning-and-disclosure-modes.md",
        "tokens": [
            "R0",
            "R2",
            "DocumentRevisionRef",
            "DependencyStore",
            "AtomicSpanGuard"
        ]
    },
    "ARCH-DISCLOSURE-MODES": {
        "kind": "boundary",
        "adr": "docs/adr/0007-multi-resolution-artifacts-pruning-and-disclosure-modes.md",
        "tokens": [
            "FreshDisclosure",
            "SourceNavigation",
            "derived_only",
            "summary_only",
            "metadata_only",
            "index_only",
            "R3"
        ]
    },
    "ARCH-PRUNING-INTEGRITY": {
        "kind": "invariant",
        "adr": "docs/adr/0007-multi-resolution-artifacts-pruning-and-disclosure-modes.md",
        "tokens": [
            "ContextPruner",
            "UTF-8",
            "sub-spans",
            "atoms"
        ]
    },
    "ARCH-MCP-BOUNDARY": {
        "kind": "boundary",
        "adr": "docs/adr/0008-context-compilers-mcp-distill-and-training-export.md",
        "tokens": [
            "Scope",
            "transport authenticator",
            "UntrustedEvidence",
            "MCP"
        ]
    },
    "ARCH-TRAINING-RIGHTS": {
        "kind": "invariant",
        "adr": "docs/adr/0008-context-compilers-mcp-distill-and-training-export.md",
        "tokens": [
            "training_allowed",
            "PII",
            "manifest",
            "training_export"
        ]
    }
}
TEST_CASES = {
    "test_checker.py": [
        "architecture.manifest-valid",
        "architecture.forbidden-dependency",
        "architecture.dependency-cycle",
        "architecture.core-runtime-dependency",
        "architecture.core-php-floor",
        "architecture.required-anchor",
        "architecture.anchor-obligation",
        "architecture.adr-reference",
        "architecture.adr-pending",
        "architecture.fixture-digest",
        "architecture.fixture-file",
        "architecture.fixture-version-rationale",
        "architecture.unknown-manifest-version",
        "architecture.required-group-cases",
        "architecture.unknown-group",
        "architecture.missing-tests",
        "architecture.zero-cases",
        "architecture.skipped-case",
        "architecture.missing-case",
        "architecture.duplicate-case",
        "architecture.child-failure",
        "architecture.input-stability",
        "architecture.explicit-cli",
        "architecture.specs-directory"
    ],
    "test_fixtures.py": [
        "architecture.core-independent",
        "architecture.version-source",
        "architecture.version-document",
        "architecture.version-chunkset",
        "architecture.version-generation",
        "architecture.immutable-citation",
        "architecture.local-revoke",
        "architecture.disclosure-before-revoke",
        "architecture.unseen-source-revoke",
        "architecture.external-owner-window",
        "architecture.external-owner-unknown",
        "architecture.disclosure-replay-uncertainty"
    ]
}
OPERATIONS = ["retrieve","disclose","publish","model_egress_context","navigate","catalog_create","collection_create","membership_mutate","source_register","source_version_add","ingest","reprocess","job_cancel","lifecycle_correct","lifecycle_revoke","lifecycle_delete","legal_hold_set","purge_status","provider_acceptance","diagnostics","layer_resolve","directive_promote","catalog_mount","training_export"]
OUTCOMES = ["success","empty","denied","unknown","unavailable","incompatible"]


class ContractError(ValueError):
    """A required architecture contract or executed case is absent/inconsistent."""


def require(condition, message):
    if not condition:
        raise ContractError(message)


def unique_pairs(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "duplicate JSON key")
        result[key] = value
    return result


def repo_file(root, relative):
    require(isinstance(relative, str) and bool(relative), "empty file reference")
    parts = PurePosixPath(relative)
    require(not parts.is_absolute() and ".." not in parts.parts
            and str(parts) == relative, "noncanonical file reference")
    path = root / relative
    require(path.resolve().is_relative_to(root.resolve()), "file reference escapes repository")
    require(path.is_file(), "missing file: " + relative)
    return path


def load_json(root, relative):
    try:
        return json.loads(repo_file(root, relative).read_text(encoding="utf-8"),
                          object_pairs_hook=unique_pairs,
                          parse_constant=lambda _: (_ for _ in ()).throw(
                              ContractError("nonfinite JSON number")))
    except (json.JSONDecodeError, UnicodeError):
        raise ContractError("invalid UTF-8 JSON: " + relative) from None


def check_dag(edges, label):
    seen, active = set(), set()

    def visit(node):
        require(node not in active, label + " dependency cycle")
        if node in seen:
            return
        active.add(node)
        for parent in edges[node]:
            require(parent in edges, label + " dependency refers to unknown node")
            visit(parent)
        active.remove(node)
        seen.add(node)

    for node in edges:
        visit(node)


def explicit_anchors(text):
    return re.findall(r'<a id="([a-z0-9-]+)"></a>', text)


def validate_links(root, relative, content):
    for target in re.findall(r"\[[^\]]+\]\(([^)\s]+)\)", content):
        if target.startswith(("https://", "http://")):
            continue
        path_part, _, fragment = target.partition("#")
        path = (root / relative).parent / path_part if path_part else root / relative
        resolved = path.resolve()
        require(resolved.is_relative_to(root.resolve()), "document link escapes repository")
        destination = resolved.relative_to(root.resolve()).as_posix()
        path = repo_file(root, destination)
        if fragment:
            require(fragment in explicit_anchors(path.read_text(encoding="utf-8")),
                    "missing document anchor: " + destination + "#" + fragment)


def validate_fixture_manifest(root):
    corpus = load_json(root, CORPUS)
    require(corpus.get("schemaVersion") == "architecture-fixture-manifest:1",
            "unsupported fixture manifest version")
    require(re.fullmatch(r"\d+\.\d+\.\d+", corpus.get("fixtureVersion", "")),
            "missing fixture version")
    require(bool(corpus.get("versionRationale", "").strip()), "missing fixture version rationale")
    entries = corpus.get("entries", [])
    require(isinstance(entries, list) and len(entries) == 3, "missing fixture entries")
    ids, paths, data = set(), set(), {}
    for entry in entries:
        identity = entry.get("id")
        require(identity and identity not in ids, "duplicate/missing fixture ID")
        ids.add(identity)
        relative = entry.get("path")
        require(relative not in paths, "duplicate fixture path")
        paths.add(relative)
        require(relative == TEST_DIR + "/fixtures/v1/" + identity + ".json",
                "unexpected fixture path")
        rights = entry.get("rights", {})
        require(bool(rights.get("license")) and bool(rights.get("origin"))
                and isinstance(rights.get("redistributionAllowed"), bool),
                "missing fixture license/provenance")
        require(entry.get("schemaId") == "architecture-fixture:1",
                "unknown fixture schema")
        require(entry.get("expected", {}).get("outcome") == "valid"
                and bool(entry["expected"].get("scenarioOutcomes")),
                "missing fixture expected outcomes")
        digest = entry.get("sha256", "")
        require(re.fullmatch(r"[0-9a-f]{64}", digest), "missing expected fixture digest")
        actual = hashlib.sha256(repo_file(root, relative).read_bytes()).hexdigest()
        require(actual == digest, "fixture digest mismatch: " + identity)
        payload = load_json(root, relative)
        require(payload.get("schemaVersion") == entry["schemaId"]
                and payload.get("modelOnly") is True, "fixture version/model scope mismatch")
        data[identity] = payload
    require(ids == {"core-independent", "version-axes", "lifecycle-window"},
            "required architecture fixture absent")
    lifecycle = data["lifecycle-window"]["traces"]
    expected = next(e["expected"]["scenarioOutcomes"] for e in entries
                    if e["id"] == "lifecycle-window")
    require(expected == {t["id"]: t["expected"]["outcome"] for t in lifecycle},
            "fixture expected outcome inventory mismatch")
    return data


def validate_group(root):
    group = load_json(root, GROUP_FILE)
    require(group.get("schemaVersion") == "check-group:1" and group.get("group") == GROUP,
            "unknown architecture case group/version")
    required = group.get("requiredCaseIds")
    expected = sorted(case for cases in TEST_CASES.values() for case in cases)
    require(isinstance(required, list) and required == expected and len(required) > 0,
            "required case inventory differs")
    require(len(required) == len(set(required)), "duplicate required case ID")
    return group


def validate_contract(root):
    manifest = load_json(root, SPEC)
    require(manifest.get("schemaVersion") == "knowkit-architecture:1",
            "unsupported architecture manifest version")
    authority = manifest.get("authority", {})
    require(authority.get("blockingDecisions") == [], "unresolved blocking decision")
    require(authority.get("decisions") == [{"id": "D1", "status": "accepted"},
                                          {"id": "D2", "status": "accepted"}],
            "unaccepted architecture decision")
    packages = manifest.get("packages", {})
    check_dag({k: v.get("dependsOn", []) for k, v in packages.items()}, "package")
    require(packages == APPROVED_PACKAGES, "approved package DAG/runtime contract differs")
    check_dag(manifest.get("coreLayers", {}), "core layer")
    require(manifest.get("coreLayers") == APPROVED_LAYERS, "approved core layer DAG differs")
    require(manifest.get("coreLayout") == ["src/Domain", "src/Application", "src/Port",
                                         "src/Registry", "src/Adapter",
                                         "migrations/postgres", "bin/knowkit"],
            "approved core layout differs")
    require(manifest.get("milestones") == APPROVED_MILESTONES, "approved milestones differ")
    require(manifest.get("worker") == {
        "path": "python/knowkit-worker", "dependsOn": ["schemas"],
        "transport": "subprocess-staged-files", "milestone": "v0.4",
        "supervisor": "KnowKit\\Adapter\\ProcessWorker\\ProcessWorkerSupervisor",
        "authority": "IR result only; independent PHP acceptance",
    }, "approved worker boundary differs")
    require(manifest.get("operations") == OPERATIONS, "required operations differ")
    require(manifest.get("outcomes") == OUTCOMES, "typed outcome semantics differ")
    documents = manifest.get("documents", [])
    required_documents = {"specs/architecture/architecture.md", "docs/architecture/README.md",
                          "docs/architecture/threat-model.md", "docs/architecture/reuse.md"}
    required_documents.update(rule["adr"] for rule in REQUIRED_ANCHORS.values())
    require(len(documents) == len(set(documents)) and set(documents) == required_documents,
            "required normative document inventory differs")
    texts = {}
    for relative in documents:
        texts[relative] = repo_file(root, relative).read_text(encoding="utf-8")
        validate_links(root, relative, texts[relative])
    spec = texts["specs/architecture/architecture.md"]
    identifiers = explicit_anchors(spec)
    require(len(identifiers) == len(set(identifiers)), "duplicate normative anchor")
    anchors = manifest.get("anchors", [])
    require(len(anchors) >= 40 and len(anchors) == len(REQUIRED_ANCHORS),
            "required operation/invariant anchor count differs")
    require(len({a["id"] for a in anchors}) == len(anchors), "duplicate manifest anchor")
    require({a["id"] for a in anchors} == set(REQUIRED_ANCHORS),
            "mandatory architecture anchor absent")
    require(set(identifiers) == {identity.lower() for identity in REQUIRED_ANCHORS},
            "mandatory normative anchor absent")
    for anchor in anchors:
        identity = anchor["id"]
        rule = REQUIRED_ANCHORS[identity]
        require(anchor.get("kind") == rule["kind"] and anchor.get("adr") == rule["adr"],
                "anchor contract/ADR ownership differs: " + identity)
        ref = "specs/architecture/architecture.md#" + identity.lower()
        require(anchor.get("ref") == ref, "anchor reference differs: " + identity)
        section = spec.split('<a id="' + identity.lower() + '"></a>', 1)[1].split("<a id=", 1)[0]
        require(re.search(r"\bMUST\b", section), "anchor has no normative obligation: " + identity)
        for token in rule["tokens"]:
            require(token in section, "mandatory contract marker absent: " + identity)
        adr = texts[rule["adr"]]
        require(re.findall(r"^Status: (\w+)$", adr, re.MULTILINE) == ["accepted"],
                "ADR is not accepted: " + rule["adr"])
        require("../../" + ref in adr, "ADR does not reference governed anchor: " + identity)
        require("../../" + rule["adr"] in section, "anchor does not reference ADR: " + identity)
    validate_fixture_manifest(root)
    validate_group(root)
    return manifest


def validate_case_report(report, required):
    cases = report.get("cases", [])
    require(isinstance(cases, list) and bool(cases), "zero selected test cases")
    require(report.get("count") == len(cases), "case count mismatch")
    identities = [case.get("id") for case in cases]
    require(len(identities) == len(set(identities)), "duplicate executed case ID")
    require(set(identities) == set(required), "missing/unexpected executed case IDs")
    require(all(case.get("status") == "passed" for case in cases),
            "failed/skipped required case")
    require(report.get("failures") == 0 and report.get("skips") == 0,
            "failed/skipped nested check")
    return cases


def input_digests(root):
    paths = {SPEC, CORPUS, GROUP_FILE, "tools/check-spec-contract.py"}
    for prefix in ("docs/architecture", "docs/adr", "specs/architecture", TEST_DIR):
        for path in (root / prefix).rglob("*"):
            if path.is_file() and path.suffix in {".py", ".json", ".md", ".txt"}:
                relative = path.relative_to(root).as_posix()
                if "evidence" not in path.relative_to(root).parts:
                    paths.add(relative)
    return {relative: hashlib.sha256(repo_file(root, relative).read_bytes()).hexdigest()
            for relative in sorted(paths)}


def run_group(root, group=GROUP):
    require(group == GROUP, "unknown architecture group")
    validate_contract(root)
    for name in TEST_CASES:
        repo_file(root, TEST_DIR + "/" + name)
    before = input_digests(root)
    cases = []
    with tempfile.TemporaryDirectory(prefix="knowkit-architecture-") as temporary:
        for name, required in TEST_CASES.items():
            report_path = Path(temporary) / (name + ".json")
            command = [sys.executable, "-I", "-B", str(root / TEST_DIR / name),
                       "--report", str(report_path)]
            try:
                # Bytes, not locale text: a child that prints non-UTF-8 must stay a JSON failure.
                child = subprocess.run(command, cwd=root, capture_output=True, timeout=60, check=False)
            except subprocess.TimeoutExpired:
                raise ContractError("nested check timed out: " + name) from None
            require(child.returncode == 0, "nested check failed: " + name
                    + " (exit " + str(child.returncode) + ")")
            require(report_path.is_file(), "missing nested case report: " + name)
            try:
                report = json.loads(report_path.read_text(encoding="utf-8"),
                                    object_pairs_hook=unique_pairs)
            except (json.JSONDecodeError, UnicodeError):
                raise ContractError("invalid nested case report: " + name) from None
            cases.extend(validate_case_report(report, required))
    require(before == input_digests(root), "architecture inputs changed during check")
    report = {"schemaVersion": "architecture-check-report:1", "group": GROUP,
              "outcome": "success", "mode": "architecture-contract-model",
              "cases": cases, "count": len(cases), "failures": 0, "skips": 0,
              "inputDigests": before,
              "limitations": ["No PHP runtime, real PostgreSQL concurrency, host install or provider qualification."]}
    validate_case_report(report, validate_group(root)["requiredCaseIds"])
    return report


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--specs", type=Path, default=Path("specs"),
                        help="canonical specs directory, relative to --root")
    parser.add_argument("--check", default="architecture", choices=["architecture"])
    parser.add_argument("--group", default=GROUP, choices=[GROUP])
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--report", type=Path)
    args = parser.parse_args(argv)
    root = args.root.resolve()
    try:
        require((root / args.specs).resolve() == (root / "specs").resolve(),
                "--specs must select the specs directory under --root")
        report = run_group(root, args.group)
        if args.report:
            destination = args.report if args.report.is_absolute() else root / args.report
            require(destination.resolve().is_relative_to(root),
                    "report destination escapes repository")
            require(destination.resolve() not in {
                (root / relative).resolve() for relative in report["inputDigests"]
            }, "report would overwrite a contract input")
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
        print(json.dumps(report))
        return 0
    except (ContractError, OSError, KeyError, TypeError, AttributeError) as error:
        # No source text, provider inputs or credentials enter diagnostic output.
        message = str(error) if isinstance(error, ContractError) else "invalid contract input or file I/O"
        print(json.dumps({"schemaVersion": "architecture-check-report:1", "group": GROUP,
                          "outcome": "failed", "cases": [], "error": message}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
