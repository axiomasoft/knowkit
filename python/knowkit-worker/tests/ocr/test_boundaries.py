import copy
from dataclasses import replace
import json
from pathlib import Path
import shutil

import pytest

from knowkit_worker.ocr.engine import cli_model, parse
from knowkit_worker.ocr.metadata import OcrMetadata
from knowkit_worker.ocr.profile import descriptor, ocr_profile
from knowkit_worker.ocr.qualification import model_fingerprint, record, verify_native
from knowkit_worker.profiles import DOCLING, lookup
from knowkit_worker.protocol import Refusal, canonical, sha256

from .conftest import CORPUS, MODELS, NATIVE, ROOT, job_for


def test_missing_ocr_and_language_weights_leave_partial_without_fallback(tmp_path):
    source = (CORPUS / "mixed.pdf").read_bytes()
    parsed = parse(source, job_for(source), language="eng", native_root=tmp_path, model_root=MODELS)
    assert parsed.ir["pages"][0]["text"].startswith("NATIVE FIRST PAGE\n")
    assert parsed.ir["pages"][1]["text"] == ""
    assert parsed.ir["pages"][1]["status"] == "skipped"
    assert parsed.ir["coverage"]["state"] == "partial"
    assert "ocr_assets_missing" in {d["code"] for d in parsed.ir["coverage"]["diagnostics"]}
    assert not any(block["origin"] == "ocr" for block in parsed.ir["blocks"])
    shutil.copytree(NATIVE, tmp_path / "native")
    (tmp_path / "native/tessdata/eng.traineddata").unlink()
    with pytest.raises(Refusal, match="ocr_assets_missing") as failure:
        verify_native(tmp_path / "native")
    assert failure.value.outcome == "unavailable"


def test_model_change_invalidates_profile_and_tampered_weights_are_incompatible(tmp_path, monkeypatch):
    from knowkit_worker.ocr import qualification

    changed = copy.deepcopy(record())
    changed["modelAssets"][0]["sha256"] = "a" * 64
    original = descriptor()
    monkeypatch.setattr(qualification, "record", lambda: changed)
    assert model_fingerprint("eng") != original.options["modelFingerprint"]
    assert descriptor().signature != original.signature
    with pytest.raises(Refusal, match="profile_mismatch"):
        lookup(original.id, original.signature, {original.id: descriptor()})
    monkeypatch.undo()
    shutil.copytree(NATIVE, tmp_path / "native")
    path = tmp_path / "native/tessdata/eng.traineddata"
    data = bytearray(path.read_bytes())
    data[-1] ^= 1
    path.write_bytes(data)
    with pytest.raises(Refusal, match="ocr_assets_mismatch") as failure:
        verify_native(tmp_path / "native")
    assert failure.value.outcome == "incompatible"


def test_explicit_options_and_languages_never_use_upstream_defaults(monkeypatch):
    import docling.datamodel.pipeline_options as options

    original = options.TesseractCliOcrOptions

    def ignores_psm(**kwargs):
        return original(**(kwargs | {"psm": 6}))

    with pytest.raises(Refusal, match="ocr_language_not_approved"):
        descriptor("deu")
    monkeypatch.setattr(options, "TesseractCliOcrOptions", ignores_psm)
    with pytest.raises(Refusal, match="ocr_options_incompatible"):
        cli_model("eng", NATIVE)
    assert DOCLING.options["ocr"] is False
    assert "parser.ocr" not in DOCLING.capabilities


def test_qualification_pins_and_native_inventory_are_exact(tmp_path):
    qualification = record()
    assert qualification == json.loads((ROOT / "licenses/qualifications/ocr.json").read_text())
    assert qualification["engine"]["sourceRevision"] == "7e548e6eff172f38f97664afe7f7ce40db36c8d6"
    assert qualification["engine"]["tessdataRevision"] == "65727574dfcd264acbb0c3e07860e4e9e9b22185"
    assert qualification["languages"] == ["eng", "rus"]
    bom = ROOT / "python/knowkit-worker/third_party/ocr/build-bom.json"
    assert qualification["engine"]["nativeBuildDigest"] == sha256(bom.read_bytes())
    assert json.loads(bom.read_text())["buildRecipeSha256"] == sha256((ROOT / "python/knowkit-worker/build_ocr_native.sh").read_bytes())
    assert {asset["id"] for asset in qualification["modelAssets"]} == {"eng", "rus", "osd"}
    verify_native(NATIVE)
    profile = ocr_profile(native_root=NATIVE, model_root=MODELS)
    assert "parser.ocr" in profile.capabilities
    for asset in qualification["modelAssets"]:
        assert sha256((NATIVE / asset["path"]).read_bytes()) == asset["sha256"]
    shutil.copytree(NATIVE, tmp_path / "native")
    (tmp_path / "native/tessdata/deu.traineddata").write_bytes(b"unapproved")
    with pytest.raises(Refusal, match="ocr_assets_invalid"):
        verify_native(tmp_path / "native")


def test_metadata_is_typed_and_rejects_false_confidence_or_truncated_fingerprint():
    metadata = OcrMetadata(3, 4, "eng", model_fingerprint("eng"), 940)
    assert OcrMetadata.from_block_id(metadata.block_id()) == metadata
    for bad in (metadata.block_id().replace("conf-940", "conf-1001"),
                metadata.block_id().replace("conf-940", "conf-0940"),
                metadata.block_id().replace(metadata.model_fingerprint, "7e548e6e")):
        with pytest.raises(Refusal, match="invalid_ocr_metadata"):
            OcrMetadata.from_block_id(bad)


def test_rotated_multicolumn_and_cross_page_table_stay_explicitly_incomplete():
    source = (ROOT / "tests/fixtures/documents/pdf/v1/rotated-table.pdf").read_bytes()
    parsed = parse(source, job_for(source), language="eng", native_root=NATIVE, model_root=MODELS)
    assert [page["physicalNo"] for page in parsed.ir["pages"]] == [1, 2]
    assert all(page["text"] for page in parsed.ir["pages"])
    assert "rotated_reading_order" in parsed.ir["coverage"]["missingFeatures"]
    assert "table_structure" in parsed.ir["coverage"]["missingFeatures"]
    assert parsed.ir["coverage"]["state"] == "partial"


def test_foreign_input_and_profile_fail_before_extraction():
    source = (CORPUS / "approved-eng.pdf").read_bytes()
    job = job_for(source)
    job.value["tenantId"] = "01JC0000000000000000000002"
    # Identity is supplied by the trusted envelope, never read from the PDF.
    job.value["inputDigest"] = "a" * 64
    with pytest.raises(Refusal, match="input_digest_mismatch") as failure:
        parse(source, job, language="eng", native_root=NATIVE, model_root=MODELS)
    assert failure.value.outcome == "denied"
