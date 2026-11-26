import copy
import json
from pathlib import Path

from knowkit_worker.protocol import Contracts, canonical, sha256
from knowkit_worker.rendering.pdf import render_page

from .conftest import CORPUS, ROOT, job_for


def assert_ir(ir):
    Contracts(ROOT / "schemas").validate(ir, "document-ir")
    assert ir["segments"] == []
    for page in ir["pages"]:
        assert page["textDigest"] == sha256(page["text"].encode())
        for block in ir["blocks"]:
            for span in block["spans"]:
                if span["pageId"] != page["pageId"]:
                    continue
                piece = page["text"].encode()[span["byteStart"]:span["byteEnd"]].decode()
                assert piece
                assert span["physicalNo"] == page["physicalNo"]
                assert span["printedLabel"] == page["printedLabel"]
                box = span["bbox"]
                assert box["view"] == "rotated-page"
                assert 0 <= box["x0"] < box["x1"] <= 1_000_000
                assert 0 <= box["y0"] < box["y1"] <= 1_000_000


def assert_expected(name, ir):
    manifest = json.loads((CORPUS / "manifest.json").read_text())
    assert sha256(canonical(ir)) == manifest["expected"][name]["irDigest"]


def test_two_columns_follow_layout_not_operator_order(extracted):
    ir = extracted("two-columns").ir
    assert_ir(ir)
    assert_expected("two-columns", ir)
    text = ir["pages"][0]["text"]
    tokens = [f"LEFT {i:02d}" for i in range(12)] + [f"RIGHT {i:02d}" for i in range(12)]
    offsets = [text.index(t) for t in tokens]
    assert offsets == sorted(offsets)
    assert ir["coverage"]["state"] == "complete"
    assert [b["order"] for b in ir["blocks"]] == list(range(len(ir["blocks"])))


def test_blank_illustration_scan_are_distinct_without_dummy_text(extracted):
    result = extracted("coverage")
    ir = result.ir
    assert_ir(ir)
    assert_expected("coverage", ir)
    assert [p["physicalNo"] for p in ir["pages"]] == [1, 2, 3]
    assert [p["status"] for p in ir["pages"]] == ["empty", "empty", "skipped"]
    assert [p["text"] for p in ir["pages"]] == ["", "", ""]
    assert {d["code"] for d in ir["coverage"]["diagnostics"]} == {
        "blank_page", "vector_only_page", "image_only_ocr_unknown"}
    assert ir["coverage"]["missingFeatures"] == ["ocr"]
    assert ir["coverage"]["state"] == "partial"
    assert [a["pageId"] for a in ir["assets"]] == ["page-2", "page-3"]
    assert len(result.assets) == 2
    for descriptor, asset in zip(ir["assets"], result.assets):
        assert descriptor["digest"] == sha256(asset.data)
        assert descriptor["byteLength"] == str(len(asset.data))
        assert asset.data.startswith(b"\x89PNG\r\n")


def test_embedded_fonts_unicode_and_printed_xii(extracted):
    ir = extracted("unicode-label").ir
    assert_ir(ir)
    assert_expected("unicode-label", ir)
    page = ir["pages"][0]
    assert page["physicalNo"] == 1 and page["printedLabel"] == "xii"
    assert page["text"] == "Привет мир — e\u0301 中文 日本語 数学\n"
    assert "e\u0301" in page["text"]  # no NFC rewrite of immutable source text
    assert all(s["printedLabel"] == "xii" for b in ir["blocks"] for s in b["spans"])


def test_rotated_columns_and_cross_page_table_are_explicitly_incomplete(extracted):
    ir = extracted("rotated-table").ir
    assert_ir(ir)
    assert_expected("rotated-table", ir)
    assert [p["physicalNo"] for p in ir["pages"]] == [1, 2]
    assert all(p["text"] for p in ir["pages"])
    assert ir["coverage"]["state"] == "partial"
    assert "rotated_reading_order" in ir["coverage"]["missingFeatures"]
    assert "table_structure" in ir["coverage"]["missingFeatures"]
    assert any(s["physicalNo"] == 2 for b in ir["blocks"] for s in b["spans"])
    assert any(d["code"] == "rotated_reading_order_incomplete" for d in ir["coverage"]["diagnostics"])


def test_render_keeps_exact_revision_and_bounded_png(extracted):
    ir = extracted("unicode-label").ir
    source = (CORPUS / "unicode-label.pdf").read_bytes()
    job = job_for(source)
    revision = {k: job.value[k] for k in ("tenantId", "sourceId", "sourceVersionId", "parserSignature")}
    revision.update(sourceDigest=sha256(source), irDigest=sha256(canonical(ir)),
                    documentRevisionId="01JC000000000000000000000A")
    rendered = render_page(source, job, revision, ir, 1)
    assert rendered.revision == revision
    assert rendered.physical_no == 1
    assert rendered.png.startswith(b"\x89PNG\r\n")
    expected = json.loads((CORPUS / "manifest.json").read_text())["render"]
    assert rendered.digest == expected["unicode-label-page-1"]
