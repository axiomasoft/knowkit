from copy import deepcopy
from dataclasses import replace
import json

import pytest

from knowkit_worker.protocol import Contracts, Refusal, canonical, sha256
from knowkit_worker.structure.formulas import FormulaMetadata, retain_formula
from knowkit_worker.structure.retention import text_for, unavailable
from knowkit_worker.structure.tables import Cell, retain_table
from knowkit_worker.structure.visuals import retain_description, retain_visual

from conftest import CORPUS, ROOT, job_for


def test_source_reported_multipage_table_keeps_cells_headers_units_notes_and_regions(oracle, retained):
    ir = retained.ir
    Contracts(ROOT / "schemas").validate(ir, "document-ir")
    assert canonical(ir) == (CORPUS / "structured.ir.json").read_bytes()
    manifest = json.loads((CORPUS / "manifest.json").read_bytes())
    assert sha256(canonical(ir)) == manifest["expected"]["irDigest"]
    assert ir["pages"] == oracle["ir"]["pages"]  # canonical source bytes are untouched
    table = next(b for b in ir["blocks"] if b["kind"] == "table")
    assert table["blockId"] == "table" and table["parentId"] == "heading"
    assert [s["physicalNo"] for s in table["spans"]] == [1, 2]
    assert table["table"] == oracle["table"]
    cells = {c["cellId"]: c for c in table["table"]["cells"]}
    assert cells["merged-title"]["colSpan"] == 2
    assert cells["a"]["rowSpan"] == 2
    assert cells["b-mass"]["headerRefs"] == ["h-mass-2"]
    assert cells["b-mass"]["unit"] == "kg"
    assert cells["b-mass"]["noteRefs"] == ["note-2"]
    assert text_for(ir, cells["a-mass-2"]["spans"][0], "table") == "12.5"
    assert cells["h-mass-1"]["spans"][0]["pageId"] != cells["h-mass-2"]["spans"][0]["pageId"]


def test_uncertain_equation_keeps_unknown_symbol_accessible_text_region_and_confidence(oracle, retained):
    metadata = FormulaMetadata.from_ir(retained.ir, "equation")
    assert metadata.confidence == 250
    assert metadata.representation == "E = m � c²"
    assert metadata.regions[0]["bbox"]["space"] == "page-millionths-top-left-v1"
    assert "formula_transcription" in retained.ir["coverage"]["missingFeatures"]
    for confidence in (None, 0, 1000):
        ir = deepcopy(oracle["ir"])
        retain_formula(ir, "equation", confidence)
        assert FormulaMetadata.from_ir(ir, "equation").confidence == confidence
        assert ir["coverage"]["state"] == "partial"  # unknown symbol is never repaired by confidence
        assert FormulaMetadata.from_ir(ir, "equation").representation == metadata.representation
    for confidence in (-1, 1001, True):
        with pytest.raises(Refusal, match="formula_metadata_invalid"):
            retain_formula(deepcopy(oracle["ir"]), "equation", confidence)


def test_formula_metadata_requires_one_confidence_and_an_original_region(oracle, retained):
    ir = deepcopy(retained.ir)
    diagnostic = next(d for d in ir["coverage"]["diagnostics"] if d["code"].startswith("formula_v1_"))
    ir["coverage"]["diagnostics"].append(deepcopy(diagnostic))
    with pytest.raises(Refusal, match="formula_metadata_invalid"):
        FormulaMetadata.from_ir(ir, "equation")
    ir = deepcopy(oracle["ir"])
    next(b for b in ir["blocks"] if b["blockId"] == "equation")["spans"][0].pop("bbox")
    with pytest.raises(Refusal, match="formula_region_unavailable"):
        retain_formula(ir, "equation")


def test_table_rejects_overlap_bad_refs_shape_and_noncanonical_source_spans(oracle):
    cells = [Cell(**c) for c in oracle["table"]["cells"]]
    cases = [([*cells, replace(cells[-1], cellId="duplicate-position")], "table_cell_overlap"),
             ([*cells, cells[-1]], "table_cell_duplicate"),
             ([replace(cells[0], rowSpan=7), *cells[1:]], "table_cell_out_of_range"),
             ([*cells[:-1], replace(cells[-1], headerRefs=["b"])], "table_header_unknown"),
             ([*cells[:-1], replace(cells[-1], noteRefs=["absent"])], "structure_block_unknown"),
             ([*cells[:-1], replace(cells[-1], unit="")], "table_unit_invalid")]
    for candidate, code in cases:
        ir = deepcopy(oracle["ir"])
        with pytest.raises(Refusal, match=code):
            retain_table(ir, "table", 6, 2, candidate)
        assert next(b for b in ir["blocks"] if b["blockId"] == "table")["kind"] == "text"
    ir = deepcopy(oracle["ir"])
    bad = deepcopy(cells[0].spans[0])
    bad["byteStart"] = len(ir["pages"][0]["text"].split("¹")[0].encode()) + 1
    bad["byteEnd"] = bad["byteStart"] + 1
    with pytest.raises(Refusal, match="structure_utf8_boundary"):
        retain_table(ir, "table", 6, 2, [replace(cells[0], spans=[bad]), *cells[1:]])
    ir["pages"][0]["textDigest"] = "0" * 64
    with pytest.raises(Refusal, match="structure_text_mismatch"):
        retain_table(ir, "table", 6, 2, cells)


def test_missing_cell_locator_is_unavailable_not_invented(oracle):
    cells = [Cell(**c) for c in oracle["table"]["cells"]]
    cells[-1] = replace(cells[-1], spans=[])
    retain_table(oracle["ir"], "table", 6, 2, cells)
    assert "table_cell_locators" in oracle["ir"]["coverage"]["missingFeatures"]
    assert oracle["ir"]["coverage"]["state"] == "partial"


def test_original_preview_caption_and_generated_description_have_separate_provenance(retained):
    ir = retained.ir
    assets = {a["assetId"]: a for a in ir["assets"]}
    blocks = {b["blockId"]: b for b in ir["blocks"]}
    assert assets["original"]["digest"] != assets["preview"]["digest"]
    assert assets["original"]["bbox"] == assets["preview"]["bbox"]
    for descriptor, asset in zip(ir["assets"], retained.assets):
        assert descriptor["digest"] == sha256(asset.data)
        assert int(descriptor["byteLength"]) == len(asset.data)
    assert blocks["preview"]["origin"] == "generated"
    assert blocks["preview"]["provenance"]["supportingRefs"] == ["original"]
    assert blocks["caption"]["origin"] == "source"
    assert blocks["caption"]["provenance"]["kind"] == "verbatim"
    assert blocks["description"]["origin"] == "generated"
    assert blocks["description"]["provenance"] == dict(kind="description", supportingRefs=["original", "caption"])
    assert blocks["description"]["spans"][0]["kind"] == "segment"
    assert "blue" not in "".join(p["text"] for p in ir["pages"])
    assert "Red" in text_for(ir, blocks["caption"]["spans"][0], "caption")


def test_visual_retention_enforces_identity_dependencies_geometry_and_byte_budgets(retained):
    job = job_for(retained.ir)
    original = retained.ir["assets"][0]
    before = canonical(retained.ir)
    job.value["tenantId"] = "01J00000000000000000000009"
    with pytest.raises(Refusal, match="structure_identity_mismatch"):
        retain_visual(retained, job, "new", b"bytes", "image/png", "p1")
    job = job_for(retained.ir)
    with pytest.raises(Refusal, match="visual_dependency_mismatch"):
        retain_visual(retained, job, "new", b"bytes", "image/png", "p2", original["bbox"], "original")
    with pytest.raises(Refusal, match="structure_id_invalid"):
        retain_visual(retained, job, "../escape", b"bytes", "image/png", "p1")
    job.value["limits"]["maxAssets"] = 2
    with pytest.raises(Refusal, match="output_limit"):
        retain_visual(retained, job, "new", b"bytes", "image/png", "p1")
    job.value["limits"]["maxOutputBytes"] = "1"
    with pytest.raises(Refusal, match="output_limit"):
        retain_description(retained, job, "new", "derived text", "original")
    assert canonical(retained.ir) == before


def test_no_assets_never_means_no_visuals_and_all_exclusion_reasons_remain_explicit(oracle):
    ir = oracle["ir"]
    for code in ("visual_extraction_failed", "visual_unsupported", "visual_skipped", "visual_budget_exhausted"):
        unavailable(ir, "visual_coverage", code, "p2")
    assert ir["assets"] == []
    assert ir["coverage"]["state"] == "partial"
    assert {d["code"] for d in ir["coverage"]["diagnostics"]} == {
        "visual_extraction_failed", "visual_unsupported", "visual_skipped", "visual_budget_exhausted"}
    Contracts(ROOT / "schemas").validate(ir, "document-ir")


def test_unpaginated_structure_keeps_segments_without_fake_pages():
    ir = json.loads((CORPUS / "unpaginated.ir.json").read_bytes())
    Contracts(ROOT / "schemas").validate(ir, "document-ir")
    assert ir["pages"] == [] and len(ir["segments"]) == 3
    for block in ir["blocks"]:
        locators = block["spans"] + [s for c in block.get("table", {}).get("cells", []) for s in c["spans"]]
        for span in locators:
            assert span["kind"] == "segment"
            assert not {"pageId", "physicalNo", "printedLabel", "bbox"} & span.keys()
            assert text_for(ir, span, block["blockId"])
    assert all(a["pageId"] is None and "bbox" not in a for a in ir["assets"])
    assert FormulaMetadata.from_ir(ir, "equation").representation == "E = m � c²"
