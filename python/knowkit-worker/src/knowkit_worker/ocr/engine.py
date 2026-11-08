"""Page/region orchestration around the frozen Docling Tesseract CLI engine.

Native layout uses the qualified text mapper unchanged. Raster-region OCR calls
Docling's pinned TSV/OSD implementation; no OCR engine is reimplemented here.
"""

from contextlib import redirect_stderr, redirect_stdout
from decimal import Decimal, ROUND_HALF_EVEN
from io import BytesIO
import logging
import os
from pathlib import Path
import subprocess
import tempfile

from ..protocol import Envelope, Refusal, canonical, sha256
from ..rendering.pdf import bbox, dimensions, open_pdf, page_png
from ..parsers.pdf.engine import converter, map_document, preflight
from ..parsers.pdf.qualification import verify_dependencies, verify_models
from .metadata import OcrMetadata
from .qualification import NATIVE, model_fingerprint, verify_native


def diagnostic(ir: dict, page_id: str, code: str, severity: str = "warning") -> None:
    ir["coverage"]["diagnostics"].append(dict(segmentId=page_id, code=code, severity=severity))


def regions(page) -> list[tuple]:
    """PDFium object coordinates -> displayed top-left coordinates, once."""
    import pypdfium2 as pdfium

    width, height = page.get_size()
    rotation = page.get_rotation()
    result = []
    for obj in page.get_objects(max_depth=32):
        if obj.type != pdfium.raw.FPDF_PAGEOBJ_IMAGE:
            continue
        left, bottom, right, top = obj.get_bounds()
        coordinates = {
            0: (left, height - top, right, height - bottom),
            90: (bottom, left, top, right),
            180: (width - right, bottom, width - left, top),
            270: (width - top, height - right, width - bottom, height - left),
        }[rotation]
        # A clipped/out-of-page raster cannot certify full region coverage.
        bbox(coordinates, width, height)
        if coordinates not in result:
            result.append(coordinates)
    return sorted(result, key=lambda box: (box[1], box[0], box[3], box[2]))


def cli_model(language: str, root: Path):
    try:
        from docling.datamodel.accelerator_options import AcceleratorDevice, AcceleratorOptions
        from docling.datamodel.pipeline_options import OcrMode, TesseractCliOcrOptions
        from docling.models.stages.ocr.tesseract_ocr_cli_model import TesseractOcrCliModel

        options = TesseractCliOcrOptions(lang=[language], path=str(root / "tessdata"),
                                         tesseract_cmd=str(root / "bin/tesseract"), psm=3,
                                         scale=3, mode=OcrMode.DEFAULT, replace_broken_text_layer=False)
        if (options.lang != [language] or options.psm != 3 or options.scale != 3
                or options.mode != OcrMode.DEFAULT or options.replace_broken_text_layer
                or options.path != str(root / "tessdata")
                or options.tesseract_cmd != str(root / "bin/tesseract")):
            raise Refusal("ocr_options_incompatible")
        return TesseractOcrCliModel(True, None, options,
                                   AcceleratorOptions(device=AcceleratorDevice.CPU, num_threads=2))
    except (ImportError, AttributeError, TypeError, ValueError):
        raise Refusal("ocr_options_incompatible") from None


def recognize(model, image, language: str, temp_bytes: int):
    """OSD checks script/orientation, never chooses an engine or language."""
    from docling.models.stages.ocr.tesseract_ocr_cli_model import _parse_orientation

    orientation, verified = 0, False
    encoded = BytesIO()
    image.save(encoded, format="PNG", dpi=(216, 216))
    if encoded.tell() > temp_bytes:
        raise Refusal("ocr_temp_limit")
    with tempfile.NamedTemporaryFile(suffix=".png") as temporary:
        temporary.write(encoded.getvalue())
        temporary.flush()
        try:
            osd = model._perform_osd(temporary.name)
            script = osd.loc[osd["key"] == "Script", "value"].tolist()
            orientation = _parse_orientation(osd)
            verified = bool(script) and script[0].strip() == {"eng": "Latin", "rus": "Cyrillic"}[language]
            if script and not verified:
                return [], orientation, "ocr_language_mismatch"
        except subprocess.CalledProcessError:
            pass
        if orientation:
            return [], orientation, "ocr_rotated_region_incomplete"
        rows = model._run_tesseract(temporary.name, None)
        return list(rows.itertuples(index=False)), 0, None if verified else "ocr_language_unverified"


def append_word(ir: dict, page: dict, row, coordinates: tuple, size: tuple,
                image_size: tuple, language: str) -> bool:
    confidence = Decimal(str(row.conf))
    if not confidence.is_finite() or not 0 <= confidence <= 100:
        raise Refusal("ocr_confidence_invalid")
    confidence = int((confidence * 10).to_integral_value(rounding=ROUND_HALF_EVEN))
    left, top, right, bottom = coordinates
    width, height = image_size
    box = bbox((left + float(row.left) * (right - left) / width,
                top + float(row.top) * (bottom - top) / height,
                left + (float(row.left) + float(row.width)) * (right - left) / width,
                top + (float(row.top) + float(row.height)) * (bottom - top) / height), *size)
    for block in ir["blocks"]:
        if block["origin"] != "source":
            continue
        for span in block["spans"]:
            if span["pageId"] == page["pageId"] and overlaps(box, span["bbox"]):
                return False
    metadata = OcrMetadata(page["physicalNo"], len(ir["blocks"]) + 1, language,
                           model_fingerprint(language), confidence)
    block_id = metadata.block_id()
    text = str(row.text)
    if not text.strip():
        return False
    start = len(page["text"].encode())
    page["text"] += text + "\n"
    ir["blocks"].append(dict(blockId=block_id, kind="text", parentId=None,
        order=len(ir["blocks"]), origin="ocr", spans=[dict(kind="page", blockId=block_id,
        pageId=page["pageId"], physicalNo=page["physicalNo"], printedLabel=page["printedLabel"],
        byteStart=start, byteEnd=start + len(text.encode()), bbox=box)],
        provenance=dict(kind="verbatim", supportingRefs=[f"page-preview-{page['physicalNo']}"])))
    return confidence < 700


def overlaps(a: dict, b: dict) -> bool:
    intersection = max(0, min(a["x1"], b["x1"]) - max(a["x0"], b["x0"])) * max(
        0, min(a["y1"], b["y1"]) - max(a["y0"], b["y0"]))
    return intersection * 2 > (a["x1"] - a["x0"]) * (a["y1"] - a["y0"])


def process_regions(ir: dict, document, metadata: list, language: str, root: Path, job: dict) -> None:
    from PIL import Image

    try:
        verify_native(root)
    except Refusal as refusal:
        if refusal.outcome != "unavailable":
            raise
        for page, meta in zip(ir["pages"], metadata):
            if meta["raster"]:
                diagnostic(ir, page["pageId"], "ocr_assets_missing")
        return
    model = cli_model(language, root)
    for index, (page, meta) in enumerate(zip(ir["pages"], metadata)):
        if not meta["raster"] or index >= job["limits"]["maxPages"]:
            continue
        native = document[index]
        try:
            boxes = regions(native)
            if len(boxes) > 128:
                raise Refusal("ocr_region_limit")
            if len(boxes) > 1:
                diagnostic(ir, page["pageId"], "ocr_region_order_unverified")
            dimensions(*native.get_size(), 3)
            image = Image.open(BytesIO(page_png(native, 3)))
            try:
                for coordinates in boxes:
                    process_region(ir, page, image, coordinates, native.get_size(), model, language,
                                   int(job["limits"]["tempBytes"]))
                    if len(canonical(ir)) > int(job["limits"]["maxOutputBytes"]):
                        raise Refusal("output_limit")
            finally:
                image.close()
        finally:
            native.close()


def process_region(ir: dict, page: dict, image, coordinates: tuple, size: tuple, model, language: str,
                   temp_bytes: int) -> None:
    crop_box = tuple(round(value * image.size[i % 2] / size[i % 2]) for i, value in enumerate(coordinates))
    crop = image.crop(crop_box)
    try:
        rows, _orientation, issue = recognize(model, crop, language, temp_bytes)
        low_confidence = False
        for row in rows:
            # The crop rounds to real rendered pixels. Restore that actual
            # offset, not the ideal PDF region, before one bbox normalization.
            low_confidence |= append_word(ir, page, row, crop_box, image.size, crop.size, language)
    except subprocess.CalledProcessError:
        rows, issue = [], "ocr_backend_failed"
        low_confidence = False
    finally:
        crop.close()
    if issue:
        diagnostic(ir, page["pageId"], issue)
    if low_confidence:
        diagnostic(ir, page["pageId"], "ocr_low_confidence")
    if not rows:
        diagnostic(ir, page["pageId"], "ocr_unreadable_region")
        if not page["text"]:
            page["status"] = "failed"
    elif page["text"]:
        page["status"] = "ok"
    diagnostic(ir, page["pageId"], f"ocr_lang_{language}", "info")


def finalize(ir: dict, metadata: list, job: dict) -> None:
    # Remove only the baseline's OCR-unknown diagnosis, after every region on
    # that original page was attempted; table/rotation/layout gaps remain.
    diagnostics = ir["coverage"]["diagnostics"]
    diagnostics[:] = [d for d in diagnostics if d["code"] not in
                      ("raster_text_coverage_unknown", "image_only_ocr_unknown")]
    missing = set(ir["coverage"]["missingFeatures"]) - {"ocr"}
    failures = {d.get("segmentId") for d in diagnostics if d["code"].startswith("ocr_") and d["severity"] != "info"}
    for index, (page, meta) in enumerate(zip(ir["pages"], metadata)):
        if meta["raster"] and meta["nativeText"]:
            missing.add("mixed_reading_order")
            diagnostic(ir, page["pageId"], "mixed_reading_order_incomplete")
        if index >= job["limits"]["maxPages"]:
            page["status"] = "skipped"
            missing.add("page_limit")
            diagnostic(ir, page["pageId"], "ocr_page_limit")
        if meta["raster"] and (page["pageId"] in failures or page["status"] in ("failed", "skipped")):
            missing.add("ocr")
        page["textDigest"] = sha256(page["text"].encode())
    ir["coverage"].update(state="partial" if missing else "complete", missingFeatures=sorted(missing),
        counts=dict(expected=len(ir["pages"]), processed=sum(p["status"] in ("ok", "empty") for p in ir["pages"]),
                    empty=sum(p["status"] == "empty" for p in ir["pages"]),
                    failed=sum(p["status"] == "failed" for p in ir["pages"]),
                    skipped=sum(p["status"] == "skipped" for p in ir["pages"])))
    asset_pages = {a["assetId"]: int(a["pageId"].removeprefix("page-")) for a in ir["assets"]}
    ir["blocks"].sort(key=lambda b: b["spans"][0]["physicalNo"] if b["spans"] else asset_pages[b["assetRefs"][0]])
    for order, block in enumerate(ir["blocks"]):
        block["order"] = order
    if len(diagnostics) > 1024:
        raise Refusal("output_limit")


def parse(source: bytes, envelope: Envelope, *, language: str, native_root: Path, model_root: Path):
    # Docling resolves Path.home() during import in the cleared sandbox.
    os.environ.setdefault("HOME", "/tmp")
    os.environ.update(HF_HUB_OFFLINE="1", TRANSFORMERS_OFFLINE="1")
    for variable in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
        os.environ[variable] = "2"
    from docling.datamodel.base_models import DocumentStream
    from .profile import descriptor

    job = envelope.value
    profile = descriptor(language)
    if job["parserProfile"] != profile.id or job["parserSignature"] != profile.signature:
        raise Refusal("profile_mismatch")
    if sha256(source) != job["inputDigest"]:
        raise Refusal("input_digest_mismatch", "denied")
    verify_dependencies()
    verify_models(model_root)
    previous_data = os.environ.get("TESSDATA_PREFIX")
    os.environ["TESSDATA_PREFIX"] = str(native_root / "tessdata")
    previous_logging = logging.root.manager.disable
    logging.disable(logging.CRITICAL)
    try:
        with open(os.devnull, "w") as sink, redirect_stdout(sink), redirect_stderr(sink):
            with open_pdf(source, 500) as document:
                metadata = preflight(document)
                limit = min(len(metadata), job["limits"]["maxPages"])
                engine = converter(model_root, job["limits"]["wallSeconds"])
                converted = engine.convert(DocumentStream(name="source.pdf", stream=BytesIO(source)),
                    raises_on_error=False, page_range=(1, limit))
                parsed = map_document(converted.document, document, metadata[:limit], job)
                ir = parsed.ir
                for index in range(limit, len(metadata)):
                    ir["pages"].append(dict(pageId=f"page-{index + 1}", physicalNo=index + 1,
                        printedLabel=metadata[index]["label"], text="", textDigest=sha256(b""), status="skipped"))
                process_regions(ir, document, metadata, language, native_root, job)
                finalize(ir, metadata, job)
                if len(canonical(ir)) + sum(len(a.data) for a in parsed.assets) > int(job["limits"]["maxOutputBytes"]):
                    raise Refusal("output_limit")
                return parsed
    except (AttributeError, TypeError, ValueError, ImportError, KeyError, IndexError):
        raise Refusal("ocr_options_incompatible") from None
    finally:
        logging.disable(previous_logging)
        if previous_data is None:
            os.environ.pop("TESSDATA_PREFIX", None)
        else:
            os.environ["TESSDATA_PREFIX"] = previous_data
