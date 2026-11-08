"""Project-authored v1 source oracle, not a claim of qualified recognition.

Never rewrite an accepted digest: create a new fixture directory/version with
a semantic rationale when changing this corpus.
"""

from copy import deepcopy
from io import BytesIO
import json
from pathlib import Path

from PIL import Image
from reportlab.pdfgen.canvas import Canvas

from knowkit_worker.profiles import ParsedDocument
from knowkit_worker.protocol import Envelope, canonical, sha256
from knowkit_worker.structure.formulas import retain_formula
from knowkit_worker.structure.tables import Cell, retain_table
from knowkit_worker.structure.visuals import bind_caption, retain_description, retain_visual

ROOT = Path(__file__).resolve().parents[4]
CORPUS = ROOT / "tests/fixtures/documents/structure/v1"
REGION = dict(space="page-millionths-top-left-v1", origin="top-left", view="rotated-page",
              x0=100000, y0=200000, x1=900000, y1=600000)


def locator(ir, block_id, page_no, text, occurrence=0, region=None):
    page = ir["pages"][page_no - 1]
    offset = -1
    for _ in range(occurrence + 1):
        offset = page["text"].index(text, offset + 1)
    start = len(page["text"][:offset].encode())
    return dict(kind="page", blockId=block_id, pageId=page["pageId"], physicalNo=page_no,
                printedLabel=page["printedLabel"], byteStart=start, byteEnd=start + len(text.encode()),
                bbox=deepcopy(region or REGION))


def source_oracle():
    texts = ["Measurements\nLeft column: calibration.\nRight column: observations.\n"
             "Dry mass (kg)\nItem Mass\nA 12\n12.5\n¹ Dry mass at 20 C.\nE = m � c²\nFigure 1. Red apparatus.\n",
             "Measurements (continued)\nItem Mass\nB 13\n² Same calibration.\n"]
    ir = dict(schemaVersion="ir-1.0", tenantId="01J00000000000000000000001",
              sourceVersionId="01J00000000000000000000003",
              parserSignature=dict(name="structure-source-oracle", version="1", profileDigest=sha256(b"source-reported-structure-v1")),
              pages=[dict(pageId=f"p{i + 1}", physicalNo=i + 1, printedLabel=str(i + 1),
                          text=t, textDigest=sha256(t.encode()), status="ok") for i, t in enumerate(texts)],
              segments=[], blocks=[], assets=[], coverage=dict(state="complete",
                  counts=dict(expected=2, processed=2, empty=0, failed=0, skipped=0),
                  missingFeatures=[], diagnostics=[]))
    definitions = [("heading", "heading", None, [(1, "Measurements")]),
                   ("left", "text", "heading", [(1, "Left column: calibration.")]),
                   ("right", "text", "heading", [(1, "Right column: observations.")]),
                   ("table", "text", "heading", [(1, "Dry mass (kg)\nItem Mass\nA 12\n12.5"), (2, "Item Mass\nB 13")]),
                   ("note-1", "text", "heading", [(1, "¹ Dry mass at 20 C.")]),
                   ("equation", "text", "heading", [(1, "E = m � c²")]),
                   ("caption", "text", "heading", [(1, "Figure 1. Red apparatus.")]),
                   ("note-2", "text", "heading", [(2, "² Same calibration.")])]
    for block_id, kind, parent, parts in definitions:
        ir["blocks"].append(dict(blockId=block_id, kind=kind, parentId=parent, order=len(ir["blocks"]),
            origin="source", spans=[locator(ir, block_id, page, text) for page, text in parts],
            provenance=dict(kind="verbatim", supportingRefs=[])))
    # Explicit source-reported associations, including the second physical header.
    definitions = [("merged-title", 0, 0, 1, 2, True, [], None, [], 1, "Dry mass (kg)", 0),
                   ("h-item-1", 1, 0, 1, 1, True, [], None, [], 1, "Item", 0),
                   ("h-mass-1", 1, 1, 1, 1, True, [], "kg", [], 1, "Mass", 0),
                   ("a", 2, 0, 2, 1, False, ["h-item-1"], None, [], 1, "A", 0),
                   ("a-mass-1", 2, 1, 1, 1, False, ["h-mass-1"], "kg", ["note-1"], 1, "12", 0),
                   ("a-mass-2", 3, 1, 1, 1, False, ["h-mass-1"], "kg", ["note-1"], 1, "12.5", 0),
                   ("h-item-2", 4, 0, 1, 1, True, [], None, [], 2, "Item", 0),
                   ("h-mass-2", 4, 1, 1, 1, True, [], "kg", [], 2, "Mass", 0),
                   ("b", 5, 0, 1, 1, False, ["h-item-2"], None, [], 2, "B", 0),
                   ("b-mass", 5, 1, 1, 1, False, ["h-mass-2"], "kg", ["note-2"], 2, "13", 0)]
    cells = []
    for cell_id, row, col, rs, cs, header, refs, unit, notes, page, text, occurrence in definitions:
        cells.append(dict(cellId=cell_id, row=row, column=col, rowSpan=rs, colSpan=cs,
                          header=header, headerRefs=refs, unit=unit, noteRefs=notes,
                          spans=[locator(ir, "table", page, text, occurrence)]))
    return dict(schemaVersion="knowkit-structure-source-oracle-1.0", ir=ir,
                table=dict(rows=6, columns=2, cells=cells), formulaConfidence=250)


def job_for(ir):
    job = json.loads((ROOT / "tests/worker/protocol/fixtures/v1/envelope.json").read_text())
    job.update(tenantId=ir["tenantId"], sourceVersionId=ir["sourceVersionId"], parserSignature=ir["parserSignature"])
    return Envelope(job)


def retain(oracle, original, preview):
    ir = deepcopy(oracle["ir"])
    table = oracle["table"]
    retain_table(ir, "table", table["rows"], table["columns"], [Cell(**c) for c in table["cells"]])
    retain_formula(ir, "equation", oracle["formulaConfidence"])
    job = job_for(ir)
    parsed = retain_visual(ParsedDocument(ir), job, "original", original, "image/png", "p1", REGION)
    parsed = retain_visual(parsed, job, "preview", preview, "image/jpeg", "p1", REGION, "original")
    bind_caption(parsed.ir, "caption", "original")
    return retain_description(parsed, job, "description", "A blue apparatus on a bench.", "original", "caption")


def main():
    if (CORPUS / "manifest.json").exists():
        raise SystemExit("Accepted fixture exists: choose a new version and semantic rationale")
    CORPUS.mkdir(parents=True, exist_ok=True)
    oracle = source_oracle()
    (CORPUS / "source.json").write_bytes(canonical(oracle))
    image = Image.new("RGB", (24, 16), "red")
    original, preview = BytesIO(), BytesIO()
    image.save(original, format="PNG")
    image.resize((12, 8)).save(preview, format="JPEG")
    (CORPUS / "original.png").write_bytes(original.getvalue())
    (CORPUS / "preview.jpg").write_bytes(preview.getvalue())
    parsed = retain(oracle, original.getvalue(), preview.getvalue())
    (CORPUS / "structured.ir.json").write_bytes(canonical(parsed.ir))
    unpaginated = deepcopy(parsed.ir)
    unpaginated["segments"] += [dict(segmentId=p["pageId"], text=p["text"], textDigest=p["textDigest"], status=p["status"])
                                 for p in unpaginated["pages"]]
    unpaginated["pages"] = []
    for block in unpaginated["blocks"]:
        locators = block["spans"] + [s for c in block.get("table", {}).get("cells", []) for s in c["spans"]]
        for span in locators:
            if span["kind"] == "page":
                span.update(kind="segment", segmentId=span.pop("pageId"))
                for key in ("physicalNo", "printedLabel", "bbox"):
                    span.pop(key)
    for asset in unpaginated["assets"]:
        asset["pageId"] = None
        asset.pop("bbox", None)
    (CORPUS / "unpaginated.ir.json").write_bytes(canonical(unpaginated))
    ocr = deepcopy(oracle["ir"])
    text = "rnass = 12"  # digest-consistent recognition error: intended source says mass
    ocr["pages"][0].update(text=text, textDigest=sha256(text.encode()))
    ocr["pages"] = ocr["pages"][:1]
    ocr["blocks"] = [dict(blockId="ocr-error", kind="text", parentId=None, order=0, origin="ocr",
                         spans=[locator(ocr, "ocr-error", 1, text)],
                         provenance=dict(kind="verbatim", supportingRefs=[]))]
    ocr["coverage"] = dict(state="partial", counts=dict(expected=1, processed=1, empty=0, failed=0, skipped=0),
                           missingFeatures=["ocr_quality"], diagnostics=[dict(code="ocr_low_confidence", severity="warning")])
    (CORPUS / "uncertain-ocr.ir.json").write_bytes(canonical(ocr))
    canvas = Canvas(str(CORPUS / "structured.pdf"), pagesize=(600, 800), invariant=1)
    for page in oracle["ir"]["pages"]:
        y = 760
        for line in page["text"].splitlines():
            canvas.drawString(40, y, line.replace("�", "[unknown]").replace("²", "^2").replace("¹", "1"))
            y -= 24
        canvas.rect(35, 520, 300, 130)
        canvas.line(160, 520, 160, 630)
        canvas.line(35, 630, 335, 630)
        canvas.drawImage(str(CORPUS / "original.png"), 350, 200, width=120, height=80)
        canvas.showPage()
    canvas.save()
    entries = [dict(path=str(p.relative_to(ROOT)), sha256=sha256(p.read_bytes()),
                    expected=dict(outcome="source-oracle" if p.name == "source.json" else "retained-structure-or-explicit-unavailable"))
               for p in sorted(CORPUS.iterdir()) if p.is_file()]
    manifest = dict(schemaVersion="knowkit-structure-fixtures-1.0", fixtureVersion="1.0.0",
                    versionRationale="Initial retention oracle: one multi-page table with actual repeated headers, kg units, per-page notes and merged spans; uncertain symbol stays U+FFFD; intentionally contradictory generated description retains its own provenance. This is a source-reported mapping corpus, not qualification of table/formula recognition.",
                    rights=dict(license="LicenseRef-KnowKit-Private", origin="Project-authored text, geometry, PNG/JPEG and ReportLab PDF; no external corpus or model assets."),
                    expected=dict(irDigest=sha256(canonical(parsed.ir)), pages=2, segments=1,
                                  originalDigest=sha256(original.getvalue()), previewDigest=sha256(preview.getvalue()),
                                  formulaRepresentation="E = m � c²", formulaConfidence=250), fixtures=entries)
    (CORPUS / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")


if __name__ == "__main__":
    main()
