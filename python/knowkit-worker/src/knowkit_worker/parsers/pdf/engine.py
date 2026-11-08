"""Native Docling IR mapping, not the donor's Markdown export.

Adapts DeepTutor Docling readiness/options (Apache-2.0, revision
f7aed0744c36c5afef16a4261c31ebee1208cac2). Its catch-and-default converter
fallback is deliberately rejected: unsupported effective settings are incompatible.
"""

from contextlib import redirect_stdout, redirect_stderr
from io import BytesIO
import logging
import os
from pathlib import Path

from ...profiles import Asset, ParsedDocument
from ...protocol import Envelope, Refusal, canonical, sha256
from ...rendering.pdf import bbox, open_pdf, page_png
from ...structure.docling import map_table
from ...structure.formulas import retain_formula
from .qualification import ROOT, verify_dependencies, verify_models

MODEL_REVISION = "8f39ad3c0b4c58e9c2d2c84a38465abf757272d8"


def converter(model_root: Path, wall_seconds: int):
    from docling.datamodel.accelerator_options import AcceleratorDevice, AcceleratorOptions
    from docling.datamodel.base_models import InputFormat
    from docling.datamodel.pipeline_options import LayoutObjectDetectionOptions, PdfPipelineOptions
    from docling.document_converter import DocumentConverter, PdfFormatOption
    from docling.backend.pypdfium2_backend import PyPdfiumDocumentBackend

    layout = LayoutObjectDetectionOptions.from_preset("layout_heron_default")
    layout.model_spec.revision = MODEL_REVISION
    layout.model_spec.engine_overrides = {}
    layout.keep_empty_clusters = True
    expected_layout = layout.model_dump(mode="json")
    options = PdfPipelineOptions(
        artifacts_path=model_root, do_ocr=False, do_table_structure=False,
        do_code_enrichment=False, do_formula_enrichment=False,
        do_picture_classification=False, do_picture_description=False,
        enable_remote_services=False, allow_external_plugins=False,
        force_backend_text=False, generate_page_images=False, generate_picture_images=False,
        images_scale=1, layout_options=layout, document_timeout=wall_seconds,
        accelerator_options=AcceleratorOptions(device=AcceleratorDevice.CPU, num_threads=2),
    )
    effective = options.model_dump(mode="json", serialize_as_any=True)
    required = dict(do_ocr=False, do_table_structure=False, do_code_enrichment=False,
                    do_formula_enrichment=False, do_picture_description=False,
                    do_picture_classification=False, enable_remote_services=False,
                    allow_external_plugins=False, force_backend_text=False,
                    generate_page_images=False, generate_picture_images=False, images_scale=1)
    if (any(effective.get(k) != v for k, v in required.items())
            or options.layout_options.model_dump(mode="json") != expected_layout):
        raise Refusal("effective_profile_mismatch")
    return DocumentConverter(allowed_formats=[InputFormat.PDF], format_options={
        InputFormat.PDF: PdfFormatOption(pipeline_options=options, backend=PyPdfiumDocumentBackend),
    })


def diagnostic(code: str, severity: str = "warning") -> dict:
    return {"code": code, "severity": severity}


def preflight(document) -> list[dict]:
    import pypdfium2 as pdfium

    pages = []
    for index in range(len(document)):
        page = document[index]
        try:
            objects = list(page.get_objects(max_depth=32))
            raster = any(obj.type == pdfium.raw.FPDF_PAGEOBJ_IMAGE for obj in objects)
            textpage = page.get_textpage()
            try:
                native_text = textpage.get_text_range()
            finally:
                textpage.close()
            label = document.get_page_label(index) or None
            annotations = pdfium.raw.FPDFPage_GetAnnotCount(page) > 0
            if label is not None and len(label) > 64:
                raise Refusal("invalid_page_label")
            pages.append(dict(size=page.get_size(), rotation=page.get_rotation(),
                              objects=bool(objects) or annotations, raster=raster,
                              visuals=annotations or any(obj.type != pdfium.raw.FPDF_PAGEOBJ_TEXT for obj in objects),
                              nativeText=bool(native_text.strip()),
                              label=label))
        finally:
            page.close()
    return pages


def map_document(native, document, metadata: list[dict], job: dict, structured: bool = False) -> ParsedDocument:
    pages = [dict(pageId=f"page-{i + 1}", physicalNo=i + 1, printedLabel=m["label"],
                  text="", textDigest=sha256(b""), status="ok") for i, m in enumerate(metadata)]
    blocks, assets, refs, diagnostics = [], [], [], []
    asset_bytes = 0
    text_bytes = 0
    missing = set()
    seen_pages = set(native.pages)
    for item, _level in native.iterate_items():
        if structured and str(item.label) == "table" and map_table(
                item, pages, metadata, blocks, int(job["limits"]["maxOutputBytes"]), missing, diagnostics):
            # Pinned Docling cells do not report explicit unit/note/header
            # associations or cross-item continuity. Do not invent them.
            missing.update(("table_header_associations", "table_units", "table_notes", "table_continuity"))
            diagnostics.append(diagnostic("table_associations_unavailable"))
            continue
        text = getattr(item, "text", "")
        if not text:
            if str(item.label) == "table":
                missing.add("table_structure")
                if structured:
                    diagnostics.append(diagnostic("table_structure_unavailable"))
            if structured and str(item.label) == "formula":
                missing.add("formula_transcription")
                diagnostics.append(diagnostic("formula_representation_unavailable"))
            continue
        if str(item.label) in ("table", "formula"):
            missing.add("table_structure" if str(item.label) == "table" else "formula_structure")
        if not item.prov:
            missing.add("native_locator")
            continue
        for prov in item.prov:
            if not 1 <= prov.page_no <= len(pages):
                raise Refusal("native_page_mismatch")
            page = pages[prov.page_no - 1]
            width, height = metadata[prov.page_no - 1]["size"]
            coordinates = prov.bbox.to_top_left_origin(height).as_tuple()
            geometry = bbox(coordinates, width, height)
            lo, hi = prov.charspan
            if not 0 <= lo < hi <= len(text):
                raise Refusal("native_span_mismatch")
            piece = text[lo:hi]
            text_bytes += len(piece.encode("utf-8")) + 1
            if text_bytes > int(job["limits"]["maxOutputBytes"]):
                raise Refusal("output_limit")
            start = len(page["text"].encode("utf-8"))
            page["text"] += piece
            block_id = f"block-{len(blocks) + 1}"
            span = dict(kind="page", blockId=block_id, pageId=page["pageId"],
                        physicalNo=page["physicalNo"], printedLabel=page["printedLabel"],
                        byteStart=start, byteEnd=start + len(piece.encode("utf-8")), bbox=geometry)
            blocks.append(dict(blockId=block_id, kind="heading" if str(item.label) in
                               ("title", "section_header") else "formula" if structured and str(item.label) == "formula" else "text", parentId=None,
                               order=len(blocks), origin="source", spans=[span],
                               provenance=dict(kind="verbatim", supportingRefs=[])))
            page["text"] += "\n"
    for index, (page, meta) in enumerate(zip(pages, metadata)):
        if meta["raster"] and page["text"]:
            missing.add("ocr")
            diagnostics.append(diagnostic("raster_text_coverage_unknown"))
        if index + 1 not in seen_pages:
            page["status"] = "failed"
            missing.add("page_extraction")
        elif not page["text"]:
            if meta["nativeText"]:
                page["status"] = "failed"
                missing.add("text_extraction")
            elif not meta["objects"]:
                page["status"] = "empty"
                diagnostics.append(diagnostic("blank_page", "info"))
            elif meta["raster"]:
                page["status"] = "skipped"
                missing.add("ocr")
                diagnostics.append(diagnostic("image_only_ocr_unknown"))
            else:
                page["status"] = "empty"
                diagnostics.append(diagnostic("vector_only_page", "info"))
        if meta["rotation"]:
            missing.add("rotated_reading_order")
            diagnostics.append(diagnostic("rotated_reading_order_incomplete"))
        # A full-page preview retains vector visuals too; it is never represented
        # as the original embedded image, and no zero-images inference is made.
        if meta["objects"] and (meta["visuals"] or not page["text"]):
            native_page = document[index]
            try:
                png = page_png(native_page)
            finally:
                native_page.close()
            asset_id = f"page-preview-{index + 1}"
            asset_bytes += len(png)
            if len(assets) >= job["limits"]["maxAssets"] or asset_bytes > int(job["limits"]["maxOutputBytes"]):
                raise Refusal("output_limit")
            assets.append(Asset(f"{asset_id}.png", png, "image/png"))
            refs.append(dict(assetId=asset_id, mediaType="image/png", digest=sha256(png),
                             byteLength=str(len(png)), pageId=page["pageId"]))
            if structured:
                refs[-1]["bbox"] = bbox((0, 0, *meta["size"]), *meta["size"])
                missing.add("original_visual_bytes")
                diagnostics.append(dict(code="rendered_preview_not_original", severity="warning",
                                        segmentId=page["pageId"]))
            blocks.append(dict(blockId=f"image-{index + 1}", kind="image", parentId=None,
                               order=len(blocks), origin="generated" if structured else "source",
                               spans=[], assetRefs=[asset_id], provenance=dict(
                                   kind="description" if structured else "verbatim",
                                   supportingRefs=[asset_id] if structured else [])))
        page["textDigest"] = sha256(page["text"].encode("utf-8"))
    asset_pages = {a["assetId"]: int(a["pageId"].removeprefix("page-")) for a in refs}
    blocks.sort(key=lambda b: b["spans"][0]["physicalNo"] if b["spans"] else asset_pages[b["assetRefs"][0]])
    for order, block in enumerate(blocks):
        block["order"] = order
    counts = dict(expected=len(pages), processed=sum(p["status"] in ("ok", "empty") for p in pages),
                  empty=sum(p["status"] == "empty" for p in pages),
                  failed=sum(p["status"] == "failed" for p in pages),
                  skipped=sum(p["status"] == "skipped" for p in pages))
    coverage = dict(state="partial" if missing or counts["failed"] else "complete", counts=counts,
                    missingFeatures=sorted(missing), diagnostics=diagnostics[:1024])
    ir = dict(schemaVersion="ir-1.0", tenantId=job["tenantId"], sourceVersionId=job["sourceVersionId"],
              parserSignature=job["parserSignature"], pages=pages, segments=[], blocks=blocks,
              assets=refs, coverage=coverage)
    for block in blocks:
        if block["kind"] == "formula":
            retain_formula(ir, block["blockId"])
    if len(assets) > job["limits"]["maxAssets"] or len(canonical(ir)) + sum(len(a.data) for a in assets) > int(job["limits"]["maxOutputBytes"]):
        raise Refusal("output_limit")
    return ParsedDocument(ir, tuple(assets))


def parse(source: bytes, envelope: Envelope, model_root: Path = ROOT / "models", structured: bool = False) -> ParsedDocument:
    # The supervisor clears the environment and mounts no host passwd/home.
    # Docling computes Path.home() at import even with explicit local artifacts.
    os.environ.setdefault("HOME", "/tmp")
    os.environ["HF_HUB_OFFLINE"] = "1"
    os.environ["TRANSFORMERS_OFFLINE"] = "1"
    for variable in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
        os.environ[variable] = "2"
    from docling.datamodel.base_models import DocumentStream
    from ...profiles import DOCLING, STRUCTURE

    job = envelope.value
    profile = STRUCTURE if structured else DOCLING
    if job["parserProfile"] != profile.id or job["parserSignature"] != profile.signature:
        raise Refusal("profile_mismatch")
    if sha256(source) != job["inputDigest"]:
        raise Refusal("input_digest_mismatch", "denied")
    verify_dependencies()
    verify_models(model_root)
    if structured:
        from ...structure.qualification import verify
        verify()
    # Library messages/progress/errors must never disclose document content.
    previous_logging = logging.root.manager.disable
    logging.disable(logging.CRITICAL)
    try:
        with open(os.devnull, "w") as sink, redirect_stdout(sink), redirect_stderr(sink):
            with open_pdf(source, job["limits"]["maxPages"]) as document:
                metadata = preflight(document)
                try:
                    engine = converter(model_root, job["limits"]["wallSeconds"])
                    converted = engine.convert(DocumentStream(name="source.pdf", stream=BytesIO(source)),
                                               raises_on_error=False, max_num_pages=job["limits"]["maxPages"])
                    return map_document(converted.document, document, metadata, job, structured=structured)
                except (AttributeError, TypeError, ValueError, ImportError):
                    raise Refusal("docling_api_incompatible") from None
    finally:
        logging.disable(previous_logging)
