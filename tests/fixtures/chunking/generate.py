"""Project-authored synthetic corpus v1. No parser/provider output or donor text.

Golden quote digests below are calculated from literal source slices in Python,
independently of the PHP chunker. Changing an oracle requires a new fixture version
and a substantive rationale, not a snapshot update.
"""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
HERE = ROOT / "tests/fixtures/chunking"


def digest(text):
    return hashlib.sha256(text.encode()).hexdigest()


def jcs(value):
    # This corpus has ASCII member names and no floats; Python ordering equals RFC8785 here.
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def typed(domain, value):
    return digest("knowkit:" + domain + ":v1\0" + jcs(value))


def object_id(value):
    number = int(typed("chunkset-manifest", value)[:32], 16)
    alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
    return "".join(alphabet[(number >> shift) & 31] for shift in range(125, -1, -5))


def cross_page_oracle(doc, text):
    upstream = json.loads((ROOT / "tests/fixtures/ir/manifest.v1.json").read_text())
    source = next(c for c in upstream["cases"] if c["id"] == "IR-UNICODE")["sourcePath"]
    revision = dict(tenantId=doc["tenantId"], sourceId="01J00000000000000000000002",
                    sourceVersionId=doc["sourceVersionId"], sourceDigest=hashlib.sha256((ROOT / source).read_bytes()).hexdigest(),
                    documentRevisionId="01J00000000000000000000004", irDigest=digest(jcs(doc)), parserSignature=doc["parserSignature"])
    identity = dict(representationVersion="structural-chunkset:1", revisionRef=revision,
                    documentId="01J00000000000000000000005",
                    chunkerSignature=dict(name="structural", version="1", profileDigest=typed("chunker-profile", {})))
    set_id = object_id(dict(identity, identityKind="chunkset"))
    spans = [dict(s, textDigest=doc["pages"][n]["textDigest"]) for n, s in enumerate(doc["blocks"][0]["spans"])]
    entry = dict(origin="source", kind="verbatim", supportingRefs=[], joiner="\n", structurePath=["p"], blockIds=["p"],
                 context=[], table=None, spans=spans, chunkDigest=digest(text), byteLength=len(text.encode()), ordinal=0)
    chunk_id = object_id(dict(identityKind="chunk", chunkSetId=set_id, entry=entry))
    manifest = dict(identity, chunkSetId=set_id, chunks=[dict(entry, chunkId=chunk_id)], followups=[], coverage=doc["coverage"])
    return dict(chunkSetId=set_id, chunkId=chunk_id, manifestDigest=typed("chunkset-manifest", manifest))


def write(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n")


def span(block, text, page="p1", start=0, end=None):
    return dict(kind="page", pageId=page, physicalNo=int(page[1:]), printedLabel=None,
                blockId=block, byteStart=start, byteEnd=len(text.encode()) if end is None else end)


def block(id, kind, spans, parent=None):
    return dict(blockId=id, kind=kind, parentId=parent, order=0, origin="source", spans=spans,
                provenance=dict(kind="verbatim", supportingRefs=[]))


def ir(texts, blocks):
    for n, b in enumerate(blocks):
        b["order"] = n
    return dict(schemaVersion="ir-1.0", tenantId="01J00000000000000000000001",
                sourceVersionId="01J00000000000000000000003",
                parserSignature=dict(name="synthetic-ir", version="1", profileDigest="0" * 64),
                pages=[dict(pageId=f"p{n}", physicalNo=n, printedLabel=None, text=t,
                            textDigest=digest(t), status="ok" if t else "empty") for n, t in enumerate(texts, 1)],
                segments=[], blocks=blocks, assets=[],
                coverage=dict(state="complete", counts=dict(expected=len(texts), processed=len(texts),
                    empty=sum(t == "" for t in texts), failed=0, skipped=0), missingFeatures=[], diagnostics=[]))


def generate():
    docs = {}
    a, b = "Начало абзаца", "続き é."
    docs["cross-page"] = ir([a, b], [block("p", "text", [span("p", a), span("p", b, "p2")])])
    code = "```php\n" + "$x = '猫';\n" * 200 + "```"
    text = "# API\n" + code
    docs["code"] = ir([text], [block("h", "heading", [span("h", text, end=5)]),
                             block("code", "code", [span("code", text, start=6)], "h")])
    text = "猫🙂е́" * 250
    docs["multibyte"] = ir([text], [block("p", "text", [span("p", text)])])
    text = "One sentence. Two sentences. Three sentences."
    docs["sentences"] = ir([text], [block("p", "text", [span("p", text)])])
    text = "aaaaaa\nbbbbbb\ncccccc"
    docs["overlap"] = ir([text], [block(f"p{n}", "text", [span(f"p{n}", text, start=i, end=i + 6)]) for n, i in enumerate([0, 7, 14], 1)])
    p1, p2 = "Item Mass\nA 12", "Item Mass\nB 13\n¹ Dry mass."
    table = block("t", "table", [span("t", p1), span("t", p2, "p2", end=14)])
    cells = []
    for row in range(4):
        page = "p1" if row < 2 else "p2"
        for col in range(2):
            start, end = ((0, 4), (5, 9))[col] if row % 2 == 0 else ((10, 11), (12, 14))[col]
            cells.append(dict(cellId=f"c{row}{col}", row=row, column=col, rowSpan=1, colSpan=1,
                header=row % 2 == 0, headerRefs=[] if row % 2 == 0 else [f"c{row-1}{col}"],
                unit="kg" if row % 2 == 1 and col == 1 else None,
                noteRefs=["fn"] if row == 3 and col == 1 else [],
                spans=[span("t", p1 if page == "p1" else p2, page, start, end)]))
    table["table"] = dict(rows=4, columns=2, cells=cells)
    docs["repeated-table"] = ir([p1, p2], [table, block("fn", "text", [span("fn", p2, "p2", start=15)])])
    for name in ["structured", "ocr"]:
        docs[name] = json.loads((ROOT / f"tests/fixtures/ir/v1/{name}.json").read_text())
    cases = []
    for name, doc in docs.items():
        relative = f"tests/fixtures/chunking/v1/{name}.json"
        write(ROOT / relative, doc)
        expected = dict(outcome="bounded_chunks_or_explicit_followup")
        if name == "cross-page":
            expected = dict(outcome="multi_span", quotes=[dict(text=a + "\n" + b, sha256=digest(a + "\n" + b))])
            expected.update(cross_page_oracle(doc, a + "\n" + b))
        elif name == "sentences":
            expected = dict(outcome="sentence_boundaries", maxBytes=20,
                            quotes=[dict(text=t, sha256=digest(t)) for t in ["One sentence. ", "Two sentences. ", "Three sentences."]])
        elif name == "overlap":
            expected = dict(outcome="one_block_overlap", maxBytes=13,
                            quotes=[dict(text=t, sha256=digest(t)) for t in ["aaaaaa\nbbbbbb", "bbbbbb\ncccccc"]])
        elif name == "code":
            expected = dict(outcome="explicit_read_requirement", omittedBlock="code", quoteDigests=[digest("# API")])
        elif name == "repeated-table":
            expected = dict(outcome="whole_rows", quotes=[dict(text=t, sha256=digest(t)) for t in ["Item\nMass", "A\n12", "Item\nMass", "B\n13", "¹ Dry mass."]])
        cases.append(dict(id=name, path=relative, sha256=hashlib.sha256((ROOT / relative).read_bytes()).hexdigest(), expected=expected))
    write(HERE / "manifest.v1.json", dict(schemaVersion="chunking-fixtures:1", fixtureVersion="1.0.0",
        versionRationale="Initial independent literal-slice oracles: ordered cross-page joiner, sentence boundaries, one whole-block overlap, row/cell boundaries and bounded code followup; UTF-8 is never normalized.",
        rights=dict(license="LicenseRef-KnowKit-Private", provenance="Project-authored synthetic IR; structured/OCR cases reuse versioned P1.4/P2.5 fixtures.", redistributionAllowed=False), cases=cases))
    negative = dict(schemaVersion="chunking-negatives:1", fixtureVersion="1.0.0", cases=[
        dict(id="zero-byte-budget", options=dict(maxBytes=0), expected="limit_exceeded"),
        dict(id="wrong-byte-type", options=dict(maxBytes="1200"), expected="limit_exceeded"),
        dict(id="negative-count-budget", options=dict(maxCount=-1), expected="limit_exceeded"),
        dict(id="unbounded-overlap", options=dict(overlapBlocks=2), expected="limit_exceeded"),
        dict(id="unknown-option", options=dict(estimate=True), expected="unknown_field"),
        dict(id="missing-counter", options=dict(maxCount=10), expected="tokenizer_required"),
        dict(id="incomplete-counter", options=dict(maxCount=10, counter=dict(id="fixture.counter")), expected="tokenizer_required")])
    write(HERE / "negative.v1.json", negative)
    prefix = "KnowKit\\Tests\\Ingest\\Chunking\\"
    structural = {
        "CROSS-PAGE": "CrossPageParagraphRetainsExactSeparateSpansAndJoiner",
        "OVERSIZED-CODE": "OversizedCodeAndInheritedHeadingProduceExplicitBoundedReadRequirement",
        "REPEATED-HEADER": "RepeatedHeadersHaveRealContextRefsAndRowsAreNeverCut",
        "MERGED-CELLS": "MergedCellsKeepRowspanClosureUnitsNotesAndExactRanges",
        "UNITS-FOOTNOTES": "MergedCellsKeepRowspanClosureUnitsNotesAndExactRanges",
        "MULTIBYTE-BOUNDARY": "MultibyteSplitsStayOnCodepointsAndKeepEveryByte",
        "NEW-CHUNKER": "NewFullChunkerFingerprintCannotOverwriteHistoricalRefs",
        "SENTENCE-OVERLAP": "SentencesPrecedeByteSplitsAndWholeBlockOverlapProgresses",
        "R05-A01": "ProcessingAxesAndChangedOriginalRemainIndependent",
        "R05-A02": "ProcessingAxesAndChangedOriginalRemainIndependent",
        "R10-A01": "GeneratedDescriptionNeverBecomesVerbatimSource",
        "R10-A02": "DigestConsistentOcrKeepsQualityLimitations",
        "R14-A01": "RepeatedHeadersHaveRealContextRefsAndRowsAreNeverCut",
        "R14-A02": "OversizedCodeAndInheritedHeadingProduceExplicitBoundedReadRequirement",
        "IMMUTABLE-TAMPER": "RevisionTenantDigestAndCanonicalTextTamperingReject",
        "TENANT-BOUNDARY": "RevisionTenantDigestAndCanonicalTextTamperingReject",
        "TOKEN-REQUIRED": "TokenBudgetsRequireExactFullCounterIdentityAndCountContext",
        "TOKEN-IDENTITY": "TokenBudgetsRequireExactFullCounterIdentityAndCountContext",
        "TOKEN-CONTEXT": "TokenBudgetsRequireExactFullCounterIdentityAndCountContext"}
    mapping = {case: [prefix + "StructuralTest::test" + method] for case, method in structural.items()}
    mapping["STORE-IMMUTABLE"] = [prefix + "StoreTest::testHistoricalChunksetsAndMetadataAreImmutableAndTenantQualified"]
    mapping["FIXTURE-INTEGRITY"] = [prefix + "FixtureTest::testVersionedOraclesRequiredCasesAndReceiptFailClosed"]
    mapping["NEGATIVE-PROFILES"] = [prefix + "FixtureTest::testVersionedNegativeProfilesRejectWithoutGuessing"]
    fixture_entries = [dict(path=c["path"], sha256=c["sha256"], expected=c["expected"]["outcome"]) for c in cases]
    for name in ["manifest.v1.json", "negative.v1.json"]:
        relative = "tests/fixtures/chunking/" + name
        fixture_entries.append(dict(path=relative, sha256=hashlib.sha256((ROOT / relative).read_bytes()).hexdigest(), expected="valid_versioned_manifest"))
    write(ROOT / "tests/fixtures/check-groups/ingest-chunking.json", dict(schemaVersion="check-group:1", group="ingest:chunking", fixtureVersion="1.0.0",
        versionRationale="Initial nonempty required-case mapping, with executed audit cases and independent Python literal-slice/identity oracles; no snapshots rewritten to fit PHP output.",
        rights=dict(license="LicenseRef-KnowKit-Private", origin="Project-authored synthetic chunking fixtures and assertions.", redistributionAllowed=False),
        requiredCaseIds=list(mapping), testCaseMap=mapping, fixtures=fixture_entries))


if __name__ == "__main__":
    generate()
