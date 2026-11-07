"""Author the initial synthetic corpus; no PHP/backend outputs are used as expectations.

Changing an expected outcome requires a fixture version/rationale change, not a snapshot update.
"""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
HERE = Path(__file__).resolve().parent


def digest(value):
    return hashlib.sha256(value.encode()).hexdigest()


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def uid(n):
    return "01J" + str(n).zfill(23)


signature = {"name": "synthetic-lexical", "version": "1", "profileDigest": "0" * 64}
texts = [
    (1, 1, "allowed", "gravity."),
    (1, 2, "denied", "gravity " * 40),
    (1, 3, "other-snapshot", "gravity " * 60),
    (1, 4, "exact", "DOC-2026/0042 uses foo_bar.v2. KB-2026-001 revision 7. Гравитация притягивает массы. Quick brown fox."),
    (1, 5, "neighbor", "DOC-2026/0043 uses foo_bar.v20. KB-2026-0010 revision 8. Гравитации. Quick brown-fox."),
    (1, 6, "tie", "gravity."),
    (1, 7, "revision-seven", "Инструкция foo_bar.v2 ревизия 7."),
    (1, 8, "revision-eight", "Инструкция foo_bar.v2 ревизия 8."),
    (1, 9, "sql", "literal x'; DROP TABLE lexical_documents; --"),
    (1, 10, "cjk", "固定資料庫測試。量子力学。"),
    (1, 11, "english", "running runners"),
    (2, 1, "foreign", "gravity " * 80),
]
documents = []
for tenant, n, name, text in texts:
    td = digest(text)
    locator = {"kind": "segment", "segmentId": "s1", "blockId": "b1", "byteStart": 0, "byteEnd": len(text.encode())}
    coverage = {"state": "complete", "counts": {"expected": 1, "processed": 1, "empty": 0, "failed": 0, "skipped": 0}, "missingFeatures": [], "diagnostics": []}
    ir = {"schemaVersion": "ir-1.0", "tenantId": uid(tenant), "sourceVersionId": uid(2000+n),
          "parserSignature": signature, "pages": [],
          "segments": [{"segmentId": "s1", "text": text, "textDigest": td, "status": "ok"}],
          "blocks": [{"blockId": "b1", "kind": "text", "parentId": None, "order": 0, "origin": "source", "spans": [locator],
                      "provenance": {"kind": "verbatim", "supportingRefs": []}}], "assets": [], "coverage": coverage}
    revision = {"tenantId": uid(tenant), "sourceId": uid(1000+n), "sourceVersionId": uid(2000+n), "sourceDigest": td,
                "documentRevisionId": uid(3000+n), "irDigest": digest(canonical(ir)), "parserSignature": signature}
    entry = {"chunkId": uid(6000+n), "chunkDigest": td, "byteLength": len(text.encode()), "ordinal": 0,
             "spans": [dict(locator, textDigest=td)], "origin": "source", "kind": "verbatim", "supportingRefs": [],
             "joiner": None, "structurePath": [], "blockIds": ["b1"], "context": [], "table": None}
    manifest = {"representationVersion": "structural-chunkset:1", "revisionRef": revision, "documentId": uid(4000+n),
                "chunkerSignature": signature, "chunkSetId": uid(5000+n), "chunks": [entry], "followups": []}
    documents.append({"tenant": tenant, "number": n, "name": name, "text": text, "textDigest": td,
                      "ir": ir, "chunkSetManifest": manifest,
                      "chunkSetDigest": digest("knowkit:chunkset-manifest:v1\0"+canonical(manifest))})

queries = [
    {"id": "crowding", "query": "gravity", "expected": ["allowed", "tie"]},
    {"id": "identifier", "query": "DOC-2026/0042", "expected": ["exact"]},
    {"id": "function", "query": "foo_bar.v2", "expected": ["exact", "revision-seven", "revision-eight"]},
    {"id": "kb-id", "query": "KB-2026-001", "expected": ["exact"]},
    {"id": "phrase", "query": '"Quick brown fox"', "expected": ["exact"]},
    {"id": "cyrillic", "query": "гравитация", "expected": ["exact"]},
    {"id": "cjk", "query": "資料庫", "expected": ["cjk"]},
    {"id": "japanese", "query": "量子力学", "expected": ["cjk"]},
    {"id": "mixed", "query": "Инструкция foo_bar.v2 ревизия 7", "expected": ["revision-seven"]},
    {"id": "sql-like", "query": "'; DROP TABLE lexical_documents; --", "expected": ["sql"]},
    {"id": "no-match", "query": "absentterm", "expected": []},
    {"id": "cjk-no-match", "query": "不存在的量子答案", "expected": []},
    {"id": "simple-not-morphology", "query": "run", "expected": []},
    {"id": "punctuation", "query": "!!!", "expected": []},
    {"id": "negative-only", "query": "-gravity", "expected": []},
]
corpus = {"schemaVersion": "lexical-corpus:1", "documents": documents, "queries": queries}
HERE.mkdir(parents=True, exist_ok=True)
(HERE / "corpus.v1.json").write_text(json.dumps(corpus, ensure_ascii=False, indent=2)+"\n")
rights = {"license": "LicenseRef-KnowKit-Private", "origin": "Project-authored synthetic corpus; independent Python SHA256/JCS-compatible literal oracles.", "redistributionAllowed": False}
manifest = {"schemaVersion": "lexical-fixtures:1", "fixtureVersion": "1.0.0",
            "versionRationale": "Initial authored tenant/snapshot/crowding, exact ID/phrase, SQL-like, Cyrillic/CJK, no-match and rogue-ID expectations; no backend snapshots.",
            "rights": rights, "files": [{"path": "tests/fixtures/retrieval/lexical/corpus.v1.json", "sha256": hashlib.sha256((HERE / "corpus.v1.json").read_bytes()).hexdigest(),
                                        "expected": "exact immutable references and positive-only scoped matches"}]}
(HERE / "manifest.v1.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2)+"\n")
methods = {
    "TENANT-ISOLATION": "testTenantSnapshotAndDeniedCompetitionArePrefilteredWithExplain",
    "SNAPSHOT-ISOLATION": "testTenantSnapshotAndDeniedCompetitionArePrefilteredWithExplain",
    "DENIED-TOP-K": "testTenantSnapshotAndDeniedCompetitionArePrefilteredWithExplain",
    "R20-A01": "testTenantSnapshotAndDeniedCompetitionArePrefilteredWithExplain",
    "R20-A02": "testUnsupportedPrefilterProfileRejectsBeforeFactoryAndTransmission",
    "R24-A01": "testExactIdentifiersPhrasesAndKbIdDoNotBroaden",
    "KB-2026-001": "testExactIdentifiersPhrasesAndKbIdDoNotBroaden",
    "R24-A02": "testCyrillicCjkMixedAndNoMatchHaveLiteralExpectedEvidence",
    "R24-A03": "testOriginalRevisionAndTypedFiltersRemainExact",
    "CYRILLIC-CJK": "testCyrillicCjkMixedAndNoMatchHaveLiteralExpectedEvidence",
    "NO-MATCH": "testCyrillicCjkMixedAndNoMatchHaveLiteralExpectedEvidence",
    "SQL-LIKE-INPUT": "testSqlLikeInputIsDataAndCannotChangeTheCorpus",
    "ROGUE-CANDIDATE-ID": "testRogueCandidateOutsideManifestCannotCrowdTopK",
    "INDEX-BEFORE-SEAL": "testIndexReceiptReplayAndSealedWriteRejection",
    "CANONICAL-BOUNDARY": "testCanonicalAndManifestTamperingFailClosed",
    "MANDATORY-BACKEND": "testBackendFailureIsUnavailableAndNeverEmpty",
    "FIXTURE-INTEGRITY": "FixtureTest::testIndependentDigestsVersionAndRequiredCases",
    "RUNNER-NEGATIVES": "FixtureTest::testMissingSkippedAndEmptyCasesAreRejected",
}
mapping = {}
for case, method in methods.items():
    name = method if "::" in method else "PostgresTest::"+method
    mapping[case] = ["KnowKit\\Tests\\Retrieval\\Lexical\\"+name]
group = {"schemaVersion": "check-group:1", "group": "retrieval:lexical", "fixtureVersion": "1.0.0",
         "versionRationale": manifest["versionRationale"], "rights": rights, "requiredCaseIds": list(mapping), "testCaseMap": mapping,
         "fixtures": manifest["files"]+[{"path": "tests/fixtures/retrieval/lexical/manifest.v1.json", "sha256": hashlib.sha256((HERE / "manifest.v1.json").read_bytes()).hexdigest(), "expected": "valid versioned expectations and provenance"}]}
(ROOT / "tests/fixtures/check-groups/retrieval-lexical.json").write_text(json.dumps(group, ensure_ascii=False, indent=2)+"\n")
