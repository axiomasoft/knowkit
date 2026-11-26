from io import BytesIO
from types import SimpleNamespace

import pytest
from PIL import Image
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen.canvas import Canvas

from knowkit_worker.ocr.engine import parse, regions
from knowkit_worker.protocol import Refusal
from knowkit_worker.rendering.pdf import open_pdf

from .conftest import CORPUS, MODELS, NATIVE, job_for


def test_low_confidence_retains_ocr_origin_and_blocks_complete_coverage(monkeypatch):
    from docling.models.stages.ocr.tesseract_ocr_cli_model import TesseractOcrCliModel

    original = TesseractOcrCliModel._run_tesseract

    def low_confidence(self, filename, osd):
        # Still execute the mandatory real engine; only simulate its confidence
        # signal to prove it changes coverage rather than becoming truth evidence.
        rows = original(self, filename, osd)
        rows["conf"] = 25
        return rows

    monkeypatch.setattr(TesseractOcrCliModel, "_run_tesseract", low_confidence)
    source = (CORPUS / "approved-eng.pdf").read_bytes()
    parsed = parse(source, job_for(source), language="eng", native_root=NATIVE, model_root=MODELS)
    assert parsed.ir["pages"][0]["text"]
    assert parsed.ir["coverage"]["state"] == "partial"
    assert "ocr_low_confidence" in {d["code"] for d in parsed.ir["coverage"]["diagnostics"]}
    assert all(block["blockId"].endswith("conf-250") for block in parsed.ir["blocks"] if block["origin"] == "ocr")


def test_sparse_native_text_lost_by_layout_is_failed_not_empty_or_guessed():
    source = (CORPUS / "sparse-native.pdf").read_bytes()
    parsed = parse(source, job_for(source), language="eng", native_root=NATIVE, model_root=MODELS)
    assert parsed.ir["pages"][0]["status"] == "failed"
    assert parsed.ir["pages"][0]["text"] == ""
    assert parsed.ir["coverage"]["state"] == "partial"
    assert "text_extraction" in parsed.ir["coverage"]["missingFeatures"]


def test_clipped_raster_geometry_never_silently_claims_covered_original_region():
    buffer = BytesIO()
    canvas = Canvas(buffer, pagesize=(100, 100), invariant=1)
    canvas.drawImage(ImageReader(Image.new("RGB", (40, 40), "black")), -20, 0, 40, 40)
    canvas.showPage()
    canvas.save()
    with open_pdf(buffer.getvalue(), 1) as pdf:
        page = pdf[0]
        try:
            with pytest.raises(Refusal, match="invalid_geometry"):
                regions(page)
        finally:
            page.close()


def test_ocr_temp_and_region_budgets_are_enforced_before_engine_output():
    source = (CORPUS / "approved-eng.pdf").read_bytes()
    job = job_for(source)
    job.value["limits"]["tempBytes"] = "1"
    with pytest.raises(Refusal, match="ocr_temp_limit"):
        parse(source, job, language="eng", native_root=NATIVE, model_root=MODELS)
    buffer = BytesIO()
    canvas = Canvas(buffer, pagesize=(500, 500), invariant=1)
    image = ImageReader(Image.new("RGB", (4, 4), "black"))
    for index in range(129):
        canvas.drawImage(image, 5 + (index % 20) * 20, 5 + (index // 20) * 20, 4, 4)
    canvas.showPage()
    canvas.save()
    source = buffer.getvalue()
    with pytest.raises(Refusal, match="ocr_region_limit"):
        parse(source, job_for(source), language="eng", native_root=NATIVE, model_root=MODELS)


def test_fractional_crop_restores_actual_pixel_offset_before_bbox_rounding(monkeypatch):
    import knowkit_worker.ocr.engine as engine

    row = SimpleNamespace(left=0, top=0, width=3, height=3, text="word", conf=95)
    monkeypatch.setattr(engine, "recognize", lambda *args: ([row], 0, None))
    ir = {"blocks": [], "coverage": {"diagnostics": []}}
    page = {"pageId": "page-1", "physicalNo": 1, "printedLabel": None, "text": "", "status": "skipped"}
    image = Image.new("RGB", (300, 300), "white")
    try:
        engine.process_region(ir, page, image, (0.2, 0.2, 10.2, 10.2), (100, 100), None, "eng", 1024, "0" * 64)
    finally:
        image.close()
    box = ir["blocks"][0]["spans"][0]["bbox"]
    assert [box[key] for key in ("x0", "y0", "x1", "y1")] == [3333, 3333, 13333, 13333]


def test_nested_form_raster_is_detected_before_claiming_vector_only_coverage():
    from knowkit_worker.parsers.pdf.engine import preflight

    buffer = BytesIO()
    canvas = Canvas(buffer, pagesize=(100, 100), invariant=1)
    canvas.beginForm("raster")
    canvas.drawImage(ImageReader(Image.new("RGB", (20, 20), "black")), 10, 10, 20, 20)
    canvas.endForm()
    for index in range(4):
        canvas.beginForm(f"nested-{index}")
        canvas.doForm("raster" if index == 0 else f"nested-{index - 1}")
        canvas.endForm()
    canvas.doForm("nested-3")
    canvas.showPage()
    canvas.save()
    with open_pdf(buffer.getvalue(), 1) as document:
        metadata = preflight(document)
    assert metadata[0]["raster"] is True
    assert metadata[0]["nativeText"] is False
