import json

from knowkit_worker.ocr.metadata import OcrMetadata
from knowkit_worker.ocr.qualification import model_fingerprint
from knowkit_worker.protocol import Contracts, canonical, sha256

from conftest import CORPUS, ROOT


def assert_ir(parsed):
    ir = parsed.ir
    Contracts(ROOT / "schemas").validate(ir, "document-ir")
    refs = {asset["assetId"]: asset for asset in ir["assets"]}
    for page in ir["pages"]:
        assert page["textDigest"] == sha256(page["text"].encode())
    for block in ir["blocks"]:
        if block["origin"] != "ocr":
            continue
        metadata = OcrMetadata.from_block_id(block["blockId"])
        assert metadata.model_fingerprint == model_fingerprint(metadata.language)
        assert block["provenance"]["kind"] == "verbatim"
        assert block["provenance"]["supportingRefs"] == [f"page-preview-{metadata.physical_no}"]
        assert refs[block["provenance"]["supportingRefs"][0]]["pageId"] == f"page-{metadata.physical_no}"
        assert 0 <= metadata.confidence <= 1000
        for span in block["spans"]:
            page = ir["pages"][metadata.physical_no - 1]
            assert span["physicalNo"] == metadata.physical_no
            assert span["pageId"] == page["pageId"]
            assert page["text"].encode()[span["byteStart"]:span["byteEnd"]].decode().strip()
            box = span["bbox"]
            assert box["space"] == "page-millionths-top-left-v1"
            assert box["view"] == "rotated-page"
            assert 0 <= box["x0"] < box["x1"] <= 1_000_000
            assert 0 <= box["y0"] < box["y1"] <= 1_000_000
    assert [block["order"] for block in ir["blocks"]] == list(range(len(ir["blocks"])))
    for descriptor, asset in zip(ir["assets"], parsed.assets):
        assert descriptor["digest"] == sha256(asset.data)
        assert descriptor["byteLength"] == str(len(asset.data))


def assert_golden(name, parsed):
    expected = json.loads((CORPUS / "manifest.json").read_text())["expected"][name]
    assert sha256(canonical(parsed.ir)) == expected["irDigest"]
    assert [page["textDigest"] for page in parsed.ir["pages"]] == expected["pageTextDigests"]


def test_approved_english_and_russian_scans_use_exact_models_and_geometry(extracted):
    for language, tokens in (("eng", "Approved English scan with original page references."),
                             ("rus", "Проверенный русский текст исходной страницы.")):
        name = f"approved-{language}"
        parsed = extracted(name, language)
        assert_ir(parsed)
        assert_golden(name, parsed)
        text = " ".join(parsed.ir["pages"][0]["text"].split())
        assert tokens in text
        assert parsed.ir["coverage"]["state"] == "complete"
        assert parsed.ir["pages"][0]["status"] == "ok"
        assert all(OcrMetadata.from_block_id(block["blockId"]).language == language
                   for block in parsed.ir["blocks"] if block["origin"] == "ocr")


def test_mixed_pages_and_regions_preserve_source_and_original_page_numbers(extracted):
    parsed = extracted("mixed")
    assert_ir(parsed)
    assert_golden("mixed", parsed)
    ir = parsed.ir
    assert [page["physicalNo"] for page in ir["pages"]] == list(range(1, 7))
    assert ir["pages"][0]["text"].startswith("NATIVE FIRST PAGE\n")
    assert "Native source text remains separate from OCR content." in ir["pages"][0]["text"]
    assert ir["pages"][2]["text"].startswith("NATIVE CAPTION OUTSIDE RASTER\n")
    source = [block for block in ir["blocks"] if block["origin"] == "source" and block["spans"]]
    assert {span["physicalNo"] for block in source for span in block["spans"]} == {1, 3}
    ocr = [block for block in ir["blocks"] if block["origin"] == "ocr"]
    assert {OcrMetadata.from_block_id(block["blockId"]).physical_no for block in ocr} == {2, 3}
    for block in ocr:
        if block["spans"][0]["physicalNo"] == 3:
            box = block["spans"][0]["bbox"]
            assert box["y0"] > 120_000  # crop origin is restored to full original-page geometry
            assert box["x0"] >= 66_000
    assert [page["status"] for page in ir["pages"]][3:] == ["empty", "empty", "failed"]
    assert all(page["text"] == "" for page in ir["pages"][3:])
    assert ir["coverage"]["state"] == "partial"


def test_rotated_unreadable_and_wrong_language_never_become_blank_or_dummy_text(extracted):
    for name, language, issue in (("rotated", "eng", "ocr_rotated_region_incomplete"),
                                  ("unreadable", "eng", "ocr_unreadable_region"),
                                  ("approved-eng", "rus", "ocr_language_mismatch")):
        parsed = extracted(name, language)
        assert_ir(parsed)
        golden = "wrong-language" if name == "approved-eng" else name
        assert_golden(golden, parsed)
        assert parsed.ir["coverage"]["state"] == "partial"
        assert parsed.ir["pages"][0]["status"] == "failed"
        assert parsed.ir["pages"][0]["text"] == ""
        assert issue in {d["code"] for d in parsed.ir["coverage"]["diagnostics"]}
        assert not any(block["origin"] == "ocr" for block in parsed.ir["blocks"])


def test_max_pages_preserves_unprocessed_original_page_refs_and_partial_coverage(extracted):
    parsed = extracted("mixed", max_pages=2)
    assert_ir(parsed)
    assert len(parsed.ir["pages"]) == 6
    assert [page["status"] for page in parsed.ir["pages"]][2:] == ["skipped"] * 4
    assert all(page["text"] == "" for page in parsed.ir["pages"][2:])
    assert parsed.ir["coverage"]["counts"]["expected"] == 6
    assert parsed.ir["coverage"]["counts"]["processed"] == 2
    assert "page_limit" in parsed.ir["coverage"]["missingFeatures"]
    assert parsed.ir["coverage"]["state"] == "partial"
