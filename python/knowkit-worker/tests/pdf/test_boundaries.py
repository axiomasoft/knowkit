import copy
import json
from pathlib import Path
from types import SimpleNamespace

import pytest

from knowkit_worker.parsers.pdf import engine, qualification
from knowkit_worker.profiles import DOCLING, PROFILES, lookup, pdf_profile
from knowkit_worker.protocol import Contracts, Refusal, canonical, sha256
from knowkit_worker.rendering.pdf import bbox, dimensions, open_pdf, render_page

from conftest import CORPUS, MODELS, ROOT, job_for


def test_malicious_and_malformed_pdf_fail_closed():
    for name, code, outcome in [("malicious", "active_pdf_denied", "denied"),
                                 ("malformed", "invalid_pdf", "incompatible")]:
        with pytest.raises(Refusal, match=code) as error:
            with open_pdf((CORPUS / f"{name}.pdf").read_bytes(), 500):
                pytest.fail("unsafe input accepted")
        assert error.value.outcome == outcome


def test_huge_geometry_and_render_scale_are_bounded_before_allocation(monkeypatch):
    import pypdfium2
    monkeypatch.setattr(pypdfium2.PdfPage, "render", lambda *_a, **_kw: pytest.fail("allocated bitmap"))
    with pytest.raises(Refusal, match="render_limit"):
        with open_pdf((CORPUS / "huge.pdf").read_bytes(), 500):
            pytest.fail("oversize geometry accepted")
    for values in [(600, 800, 1000), (float("inf"), 10, 1), (float("nan"), 10, 1), (0, 10, 1)]:
        with pytest.raises(Refusal):
            dimensions(*values)
    with pytest.raises(Refusal, match="page_limit"):
        with open_pdf((CORPUS / "coverage.pdf").read_bytes(), 2):
            pytest.fail("truncated to requested pages")


def test_exact_profile_options_and_schema_never_default_fallback(monkeypatch):
    from docling.datamodel.pipeline_options import PdfPipelineOptions
    from docling.document_converter import DocumentConverter

    original = PdfPipelineOptions.model_dump

    def changed(self, *args, **kwargs):
        result = original(self, *args, **kwargs)
        result["do_ocr"] = True
        return result

    monkeypatch.setattr(PdfPipelineOptions, "model_dump", changed)
    monkeypatch.setattr(DocumentConverter, "__init__", lambda *_a, **_kw: pytest.fail("fallback initialized"))
    with pytest.raises(Refusal, match="effective_profile_mismatch") as error:
        engine.converter(MODELS, 600)
    assert error.value.outcome == "incompatible"
    with pytest.raises(Refusal, match="unsupported_profile"):
        lookup("docling-default", DOCLING.signature, PROFILES)
    with pytest.raises(Refusal, match="profile_mismatch"):
        lookup(DOCLING.id, dict(DOCLING.signature, profileDigest="0" * 64), PROFILES)
    contracts = Contracts(ROOT / "schemas")
    with pytest.raises(Refusal, match="unsupported_schema_version"):
        contracts.validate({"schemaVersion": "ir-2.0"}, "document-ir")


def test_code_and_weights_qualification_has_no_unknown_or_implicit_activation(tmp_path, monkeypatch):
    qualification.verify_dependencies()
    qualification.verify_models(MODELS)
    record = qualification.record()
    assert record["enabledProfiles"] == [DOCLING.id]
    assert record["verdict"] == "restricted"
    assert all(c["licenseFileDigest"] != sha256(b"") and c["notices"] for c in record["transitiveComponents"])
    assert (ROOT / "licenses/qualifications/pdf.json").read_bytes() == (
        qualification.ROOT / "qualification.json").read_bytes()
    assert record["lockDigest"] == sha256((ROOT / "python/knowkit-worker/uv.lock").read_bytes())
    with pytest.raises(Refusal, match="model_assets_missing"):
        pdf_profile(tmp_path)
    asset = record["modelAssets"][0]
    path = tmp_path / asset["path"]
    path.parent.mkdir(parents=True)
    path.write_bytes(b"tampered")
    with pytest.raises(Refusal, match="model_assets_mismatch"):
        pdf_profile(tmp_path)
    path.unlink()
    path.symlink_to(MODELS / asset["path"])
    with pytest.raises(Refusal, match="model_assets_invalid"):
        pdf_profile(tmp_path)
    original = qualification.importlib.metadata.version
    monkeypatch.setattr(qualification.importlib.metadata, "version", lambda name: "0" if name == "docling" else original(name))
    with pytest.raises(Refusal, match="backend_version_mismatch"):
        qualification.verify_dependencies()


def test_render_refuses_foreign_changed_historical_and_wrong_page():
    source = (CORPUS / "unicode-label.pdf").read_bytes()
    job = job_for(source)
    ir = dict(tenantId=job.value["tenantId"], sourceVersionId=job.value["sourceVersionId"],
              parserSignature=job.value["parserSignature"], pages=[{"physicalNo": 1}])
    revision = {k: job.value[k] for k in ("tenantId", "sourceId", "sourceVersionId", "parserSignature")}
    revision.update(sourceDigest=sha256(source), irDigest=sha256(canonical(ir)),
                    documentRevisionId="01JC000000000000000000000A")
    for key in ("tenantId", "sourceId", "sourceVersionId", "sourceDigest", "irDigest"):
        changed = dict(revision, **{key: "0" * 64})
        with pytest.raises(Refusal, match="revision_mismatch") as error:
            render_page(source, job, changed, ir, 1)
        assert error.value.outcome == "denied"
    with pytest.raises(Refusal, match="revision_mismatch"):
        render_page(source + b"changed", job, revision, ir, 1)
    with pytest.raises(Refusal, match="page_not_found"):
        render_page(source, job, revision, ir, 0)


def test_nonpaginated_locators_never_gain_pdf_geometry():
    ir = json.loads((ROOT / "tests/worker/protocol/fixtures/v1/ir.json").read_text())
    block = dict(blockId="p1", kind="text", parentId=None, order=0, origin="source",
                 spans=[dict(kind="segment", blockId="p1", segmentId="s1", byteStart=0, byteEnd=7)],
                 provenance=dict(kind="verbatim", supportingRefs=[]))
    ir["blocks"] = [block]
    contracts = Contracts(ROOT / "schemas")
    contracts.validate(ir, "document-ir")
    for key, value in [("physicalNo", 1), ("bbox", bbox((1, 1, 2, 2), 10, 10))]:
        changed = copy.deepcopy(ir)
        changed["blocks"][0]["spans"][0][key] = value
        with pytest.raises(Refusal, match="schema_mismatch"):
            contracts.validate(changed, "document-ir")
    assert ir["pages"] == []


def test_failed_page_is_not_blank_or_complete():
    from knowkit_worker.parsers.pdf.engine import map_document
    source = (CORPUS / "coverage.pdf").read_bytes()
    with open_pdf(source, 500) as document:
        metadata = engine.preflight(document)
        # A missing native page is an explicit failure, even for an empty source
        # page. This failure-injection assertion complements the real corpus.
        native = SimpleNamespace(pages={}, iterate_items=lambda: iter(()))
        ir = map_document(native, document, metadata, job_for(source).value).ir
    assert [p["status"] for p in ir["pages"]] == ["failed"] * 3
    assert ir["coverage"]["counts"]["failed"] == 3
    assert ir["coverage"]["counts"]["empty"] == 0
    assert ir["coverage"]["state"] == "partial"


def test_geometry_rounds_half_even_once():
    box = bbox((0.0000005, 0.0000015, 0.5, 0.75), 1, 1)
    assert box["x0"] == 0 and box["y0"] == 2
    assert box["x1"] == 500_000 and box["y1"] == 750_000
    with pytest.raises(Refusal):
        bbox((0.5, 0.5, 0.4, 0.6), 1, 1)


def test_decoded_source_image_is_bounded_before_render(monkeypatch):
    import pypdfium2
    monkeypatch.setattr(pypdfium2.PdfImage, "get_px_size", lambda _self: (1_000_000, 1_000_000))
    monkeypatch.setattr(pypdfium2.PdfPage, "render", lambda *_a, **_kw: pytest.fail("decoded oversize image"))
    with pytest.raises(Refusal, match="render_limit"):
        with open_pdf((CORPUS / "coverage.pdf").read_bytes(), 500):
            pytest.fail("unbounded decoded source image")


def test_preview_asset_budget_cannot_accumulate_unbounded_output():
    source = (CORPUS / "coverage.pdf").read_bytes()
    job = job_for(source).value
    job["limits"]["maxAssets"] = 0
    with open_pdf(source, 500) as document:
        metadata = engine.preflight(document)
        native = SimpleNamespace(pages={1: None, 2: None, 3: None}, iterate_items=lambda: iter(()))
        with pytest.raises(Refusal, match="output_limit"):
            engine.map_document(native, document, metadata, job)


def test_native_caption_does_not_prove_text_inside_raster_is_covered():
    from docling_core.types.doc import BoundingBox, CoordOrigin

    source = (CORPUS / "coverage.pdf").read_bytes()
    prov = SimpleNamespace(page_no=3, charspan=(0, 7), bbox=BoundingBox(
        l=10, t=10, r=100, b=30, coord_origin=CoordOrigin.TOPLEFT))
    item = SimpleNamespace(text="caption", label="text", prov=[prov])
    native = SimpleNamespace(pages={1: None, 2: None, 3: None}, iterate_items=lambda: iter([(item, 0)]))
    with open_pdf(source, 500) as document:
        ir = engine.map_document(native, document, engine.preflight(document), job_for(source).value).ir
    assert ir["pages"][2]["status"] == "ok"
    assert ir["coverage"]["state"] == "partial"
    assert "ocr" in ir["coverage"]["missingFeatures"]
    assert "raster_text_coverage_unknown" in {d["code"] for d in ir["coverage"]["diagnostics"]}
