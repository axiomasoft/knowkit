from copy import deepcopy
import os
from pathlib import Path
import socket
from unittest.mock import patch

import pytest

from docling_core.types.doc import BoundingBox, CoordOrigin, Size
from docling_core.types.doc.document import DoclingDocument, ProvenanceItem, TableCell, TableData, TableItem

from knowkit_worker.parsers.pdf.engine import map_document, parse, preflight
from knowkit_worker.profiles import DOCLING, STRUCTURE, lookup, pdf_profile
from knowkit_worker.protocol import Contracts, Envelope, Refusal, sha256
from knowkit_worker.rendering.pdf import open_pdf
from knowkit_worker.structure.docling import map_table
from knowkit_worker.structure.formulas import FormulaMetadata

from .conftest import CORPUS, ROOT, job_for


def native_table():
    cells = [TableCell(text="Mass (kg)", start_row_offset_idx=0, end_row_offset_idx=1,
                       start_col_offset_idx=0, end_col_offset_idx=2, col_span=2, column_header=True,
                       bbox=BoundingBox(l=10, t=10, r=90, b=20, coord_origin=CoordOrigin.TOPLEFT)),
             TableCell(text="A", start_row_offset_idx=1, end_row_offset_idx=2,
                       start_col_offset_idx=0, end_col_offset_idx=1,
                       bbox=BoundingBox(l=10, t=20, r=50, b=30, coord_origin=CoordOrigin.TOPLEFT)),
             TableCell(text="12", start_row_offset_idx=1, end_row_offset_idx=2,
                       start_col_offset_idx=1, end_col_offset_idx=2,
                       bbox=BoundingBox(l=50, t=20, r=90, b=30, coord_origin=CoordOrigin.TOPLEFT))]
    return TableItem(self_ref="#/tables/0", data=TableData(num_rows=2, num_cols=2, table_cells=cells),
                     prov=[ProvenanceItem(page_no=1, charspan=(0, 0),
                         bbox=BoundingBox(l=10, t=10, r=90, b=30, coord_origin=CoordOrigin.TOPLEFT))])


def test_pinned_native_table_api_retains_available_cells_without_qualifying_recognition(oracle):
    pages = deepcopy(oracle["ir"]["pages"])
    blocks = []
    before = pages[0]["text"]
    assert map_table(native_table(), pages, [{"size": (100, 100)}] * 2, blocks, 100000)
    table = blocks[0]["table"]
    assert table["cells"][0]["colSpan"] == 2
    assert table["cells"][0]["header"] is True
    assert table["cells"][0]["spans"][0]["bbox"]["x0"] == 100000
    assert all(c["unit"] is None and c["headerRefs"] == [] and c["noteRefs"] == [] for c in table["cells"])
    assert pages[0]["text"] == before + "Mass (kg)\nA\n12\n"
    # This is a real pinned datamodel mapping test, not an extraction qualification.
    assert DOCLING.options["tables"] is False
    assert DOCLING.options["formulas"] is False


def test_native_ambiguous_multipage_or_missing_geometry_never_guesses_a_cell_page(oracle):
    for missing_geometry in (False, True):
        item = native_table()
        if missing_geometry:
            item.data.table_cells[0].bbox = None
        else:
            second = item.prov[0].model_copy(update={"page_no": 2})
            item.prov.append(second)
        pages = deepcopy(oracle["ir"]["pages"])
        blocks = []
        assert not map_table(item, pages, [{"size": (100, 100)}] * 2, blocks, 100000)
        assert pages == oracle["ir"]["pages"] and blocks == []


def test_native_entrypoint_retains_table_and_formula_and_reports_missing_associations(oracle):
    native = DoclingDocument(name="native-api-contract")
    native.add_page(page_no=1, size=Size(width=600, height=800))
    native.add_page(page_no=2, size=Size(width=600, height=800))
    item = native_table()
    native.add_table(data=item.data, prov=item.prov[0])
    text = "E = m � c²"
    native.add_formula(text=text, prov=ProvenanceItem(page_no=1, charspan=(0, len(text)),
                       bbox=BoundingBox(l=10, t=40, r=90, b=50, coord_origin=CoordOrigin.TOPLEFT)))
    native.add_formula(text="", prov=ProvenanceItem(page_no=1, charspan=(0, 0),
                       bbox=BoundingBox(l=10, t=55, r=90, b=65, coord_origin=CoordOrigin.TOPLEFT)))
    source = (CORPUS / "structured.pdf").read_bytes()
    with open_pdf(source, 2) as document:
        parsed = map_document(native, document, preflight(document), job_for(oracle["ir"]).value, structured=True)
    Contracts(ROOT / "schemas").validate(parsed.ir, "document-ir")
    assert len([b for b in parsed.ir["blocks"] if b["kind"] == "table"]) == 1
    formula = next(b for b in parsed.ir["blocks"] if b["kind"] == "formula")
    metadata = FormulaMetadata.from_ir(parsed.ir, formula["blockId"])
    assert metadata.representation == text and metadata.confidence is None
    assert any(d["code"] == "formula_representation_unavailable" for d in parsed.ir["coverage"]["diagnostics"])
    assert {"table_header_associations", "table_units", "table_notes", "table_continuity"} <= set(parsed.ir["coverage"]["missingFeatures"])
    assert parsed.ir["coverage"]["state"] == "partial"


def test_native_empty_cell_and_invalid_overlap_have_explicit_diagnostics(oracle):
    for overlap in (False, True):
        item = native_table()
        if overlap:
            item.data.table_cells[1].start_row_offset_idx = 0
            item.data.table_cells[1].end_row_offset_idx = 1
        else:
            item.data.table_cells[1].text = ""
        pages = deepcopy(oracle["ir"]["pages"])
        missing, diagnostics = set(), []
        result = map_table(item, pages, [{"size": (100, 100)}] * 2, [], 100000, missing, diagnostics)
        assert result is not overlap
        assert diagnostics[0]["code"] == ("table_cell_overlap" if overlap else "table_cell_locator_unavailable")
        assert missing == ({"table_structure"} if overlap else {"table_cell_locators"})


def test_real_qualified_pdf_preserves_source_locators_or_declares_unavailable_structure(oracle):
    source = (CORPUS / "structured.pdf").read_bytes()
    job = job_for(oracle["ir"]).value
    job.update(parserProfile=STRUCTURE.id, parserSignature=STRUCTURE.signature,
               profileFingerprint=STRUCTURE.signature["profileDigest"], inputDigest=sha256(source))
    job["limits"]["wallSeconds"] = 60
    models = Path(os.environ.get("KNOWKIT_PDF_MODELS", ROOT / "python/knowkit-worker/.local-checks/pdf-models"))
    with patch.object(socket.socket, "connect", side_effect=AssertionError("network attempted")):
        profile = pdf_profile(models, structured=True)
        parsed = lookup(STRUCTURE.id, STRUCTURE.signature, {STRUCTURE.id: profile}).parse(source, Envelope(job))
    assert [p["physicalNo"] for p in parsed.ir["pages"]] == [1, 2]
    assert all(p["text"] for p in parsed.ir["pages"])
    assert "Measurements" in parsed.ir["pages"][0]["text"]
    assert all(s["bbox"]["space"] == "page-millionths-top-left-v1"
               for b in parsed.ir["blocks"] for s in b["spans"])
    assert DOCLING.options["tables"] is False and DOCLING.options["formulas"] is False
    assert "parser.tables" not in DOCLING.capabilities and "parser.formulas" not in DOCLING.capabilities
    assert parsed.ir["coverage"]["state"] == "partial"
    assert parsed.ir["coverage"]["missingFeatures"]
    images = [b for b in parsed.ir["blocks"] if b["kind"] == "image"]
    assert images
    for image in images:
        assert image["origin"] == "generated"
        assert image["provenance"]["kind"] == "description"
        assert image["provenance"]["supportingRefs"] == image["assetRefs"]
    assert "original_visual_bytes" in parsed.ir["coverage"]["missingFeatures"]
    assert any(d["code"] == "rendered_preview_not_original" for d in parsed.ir["coverage"]["diagnostics"])


def test_structure_retention_has_exact_distinct_profile_identity_and_no_fallback(monkeypatch):
    from knowkit_worker.structure import qualification

    assert STRUCTURE.signature != DOCLING.signature
    assert STRUCTURE.options["structureRetention"] == "native-ir-retention-v1"
    record = qualification.record()
    assert record["fixtureManifestDigest"] == sha256((CORPUS / "manifest.json").read_bytes())
    assert record["baseQualificationDigest"] == DOCLING.options["qualificationDigest"]
    qualification.verify()
    with pytest.raises(Refusal, match="profile_mismatch"):
        lookup(STRUCTURE.id, DOCLING.signature, {STRUCTURE.id: STRUCTURE})
    with pytest.raises(Refusal, match="profile_not_qualified"):
        lookup(STRUCTURE.id, STRUCTURE.signature, {STRUCTURE.id: STRUCTURE})
    tampered = dict(record, baseQualificationDigest="0" * 64)
    monkeypatch.setattr(qualification, "record", lambda: tampered)
    with pytest.raises(Refusal, match="structure_qualification_mismatch"):
        qualification.verify()
