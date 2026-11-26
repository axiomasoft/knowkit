"""Explicit v1 authoring tool; digest updates require a new version and rationale."""

import json
from pathlib import Path

from reportlab.pdfgen.canvas import Canvas

from knowkit_worker.profiles import DOCLING, STRUCTURE, PROFILES
from knowkit_worker.protocol import canonical, sha256

ROOT = Path(__file__).resolve().parents[4]
FIXTURES = ROOT / "tests/worker/e2e/fixtures/v1"


def main():
    FIXTURES.mkdir(parents=True, exist_ok=True)
    pdf = Canvas(str(FIXTURES / "ten-pages.pdf"), invariant=1, pagesize=(300, 300))
    pages, blocks = [], []
    for number in range(1, 11):
        text = f"Lifecycle page {number}."
        pdf.drawString(30, 250, text)
        pdf.showPage()
        page_id, block_id = f"page-{number}", f"block-{number}"
        # A deterministic parser failure oracle, not a golden Docling recognition claim.
        content = text if number < 10 else ""
        pages.append(dict(pageId=page_id, physicalNo=number, printedLabel=None,
                          text=content, textDigest=sha256(content.encode()),
                          status="ok" if number < 10 else "failed"))
        if number < 10:
            blocks.append(dict(blockId=block_id, kind="text", parentId=None, order=number - 1,
                               origin="source", spans=[dict(kind="page", pageId=page_id,
                               physicalNo=number, printedLabel=None, blockId=block_id,
                               byteStart=0, byteEnd=len(content))],
                               provenance=dict(kind="verbatim", supportingRefs=[])))
    pdf.save()
    ir = dict(schemaVersion="ir-1.0", tenantId=f"{1:026d}", sourceVersionId=f"{30:026d}",
              parserSignature=DOCLING.signature, pages=pages, segments=[], blocks=blocks, assets=[],
              coverage=dict(state="partial", counts=dict(expected=10, processed=9, empty=0, failed=1, skipped=0),
                            missingFeatures=["page_extraction"], diagnostics=[dict(segmentId="page-10",
                            code="page_extraction_failed", severity="error")]))
    (FIXTURES / "nine-of-ten.ir.json").write_bytes(canonical(ir))
    profiles = {p.id: dict(name=p.name, version=p.version, options=p.options, signature=p.signature)
                for p in [DOCLING, STRUCTURE, PROFILES["docling-pdf-ocr-eng-v1"]]}
    (FIXTURES / "profiles.json").write_bytes(canonical(profiles))
    files = [
        ("tests/worker/e2e/fixtures/v1/ten-pages.pdf", "ten-page-source"),
        ("tests/worker/e2e/fixtures/v1/nine-of-ten.ir.json", "partial-9-of-10-failed-page-10"),
        ("tests/worker/e2e/fixtures/v1/profiles.json", "exact-qualified-profile-identities"),
        ("tests/fixtures/documents/scans/v1/approved-eng.pdf", "complete-ocr-with-preview"),
        ("tests/fixtures/documents/scans/v1/approved-eng.ir.json", "qualified-ocr-oracle"),
    ]
    manifest = dict(schemaVersion="worker-e2e-fixtures-1.0", fixtureVersion="1.2.0",
                    versionRationale='Requalification of the exact offline CPU closure: torch 2.13.0+cpu, torchvision 0.28.0+cpu and pypdfium2 5.15.2. Independent real extraction compared all IR fields except parserSignature with the accepted corpus: source text, geometry, ordering, coverage, OCR metadata and preview digests are unchanged. Only the dependency-bound parser identity and its enclosing digests change; source PDFs, model/native assets and expected semantics are preserved. The 9/10-page failure oracle retains the same content and coverage; E2E profile and revision/chunk digests are rebound to the exact qualified identities.',
                    rights=dict(license="LicenseRef-KnowKit-Private", origin="Project-authored synthetic PDFs and parser-failure oracle; qualified OCR corpus reused unchanged."),
                    fixtures=[dict(path=p, sha256=sha256((ROOT / p).read_bytes()), expected=dict(outcome=o)) for p, o in files],
                    expected=dict(missingPages=[10], processed=9, failed=1, strict="blocked", partial="published-partial", batch="source-results-separated",
                                  freshIrDigest="90bb60d0cdc6f5c1112b3caec82a155e3c57e4d1e851df47097407e4de0204bd",
                                  freshChunkManifestDigest="f68678437168b67f26cce9275e0e2db0b9875c526db9408b3ed47c6993ade822"))
    (FIXTURES / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")


if __name__ == "__main__":
    main()
