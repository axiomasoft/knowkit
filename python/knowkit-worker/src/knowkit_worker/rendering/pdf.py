"""PDFium rendering; no JavaScript environment, network, or current-version lookup.

Adapted from DeepTutor source_visuals.py's exact-source check and bounded crop
approach (Apache-2.0, f7aed0744c36c5afef16a4261c31ebee1208cac2).
The PHP supervisor remains responsible for current access/lifecycle authority.
"""

from contextlib import contextmanager
from dataclasses import dataclass
from decimal import Decimal, ROUND_HALF_EVEN
from io import BytesIO
import math

from ..protocol import MAX_INPUT, Envelope, Refusal, canonical, sha256

MAX_PIXELS = 16_000_000
MAX_SIDE = 16_384


def dimensions(width: float, height: float, scale: float = 1) -> tuple[int, int]:
    if not all(math.isfinite(v) and v > 0 for v in (width, height, scale)):
        raise Refusal("invalid_geometry")
    w, h = math.ceil(width * scale), math.ceil(height * scale)
    if max(w, h) > MAX_SIDE or w * h > MAX_PIXELS:
        raise Refusal("render_limit")
    return w, h


@contextmanager
def open_pdf(source: bytes, max_pages: int):
    import pypdfium2 as pdfium

    if not source.startswith(b"%PDF-") or len(source) > MAX_INPUT:
        raise Refusal("invalid_pdf")
    try:
        document = pdfium.PdfDocument(source)
    except pdfium.PdfiumError:
        raise Refusal("invalid_pdf") from None
    try:
        if len(document) < 1 or len(document) > max_pages:
            raise Refusal("page_limit")
        if (pdfium.raw.FPDFDoc_GetJavaScriptActionCount(document) > 0
                or pdfium.raw.FPDFDoc_GetAttachmentCount(document) > 0):
            raise Refusal("active_pdf_denied", "denied")
        # Check every page before the layout pipeline can allocate a bitmap.
        for page in document:
            try:
                dimensions(*page.get_size())
                for index, obj in enumerate(page.get_objects(max_depth=32)):
                    if index >= 20_000:
                        raise Refusal("page_object_limit")
                    if obj.type == pdfium.raw.FPDF_PAGEOBJ_IMAGE:
                        dimensions(*obj.get_px_size())
            finally:
                page.close()
        yield document
    finally:
        document.close()


def page_png(page, scale: float = 1) -> bytes:
    dimensions(*page.get_size(), scale)
    bitmap = page.render(scale=scale, may_draw_forms=False)
    try:
        image = bitmap.to_pil()
        try:
            output = BytesIO()
            image.save(output, format="PNG")
            return output.getvalue()
        finally:
            image.close()
    finally:
        bitmap.close()


def bbox(values, width, height) -> dict:
    """Input coordinates already refer to the displayed, rotated page."""
    dimensions(width, height)
    coordinates = []
    for value, extent in zip(values, (width, height, width, height)):
        if not math.isfinite(value) or not 0 <= value <= extent:
            raise Refusal("invalid_geometry")
        normalized = Decimal(str(value)) * 1_000_000 / Decimal(str(extent))
        coordinates.append(int(normalized.to_integral_value(rounding=ROUND_HALF_EVEN)))
    x0, y0, x1, y1 = coordinates
    if x0 >= x1 or y0 >= y1:
        raise Refusal("invalid_geometry")
    return dict(space="page-millionths-top-left-v1", origin="top-left", view="rotated-page",
                x0=x0, y0=y0, x1=x1, y1=y1)


@dataclass(frozen=True)
class RenderedPage:
    revision: dict
    physical_no: int
    png: bytes

    @property
    def digest(self) -> str:
        return sha256(self.png)


def render_page(source: bytes, envelope: Envelope, revision: dict, ir: dict,
                physical_no: int, scale: float = 1) -> RenderedPage:
    from ..parsers.pdf.qualification import verify_dependencies
    from ..profiles import DOCLING

    verify_dependencies()
    job = envelope.value
    if job["parserProfile"] != DOCLING.id or job["parserSignature"] != DOCLING.signature:
        raise Refusal("profile_mismatch")
    expected = {key: job[key] for key in ("tenantId", "sourceId", "sourceVersionId", "parserSignature")}
    expected.update(sourceDigest=sha256(source), irDigest=sha256(canonical(ir)))
    if (set(revision) != {*expected, "documentRevisionId"}
            or any(revision.get(k) != v for k, v in expected.items())
            or job["inputDigest"] != expected["sourceDigest"]
            or any(ir[k] != job[k] for k in ("tenantId", "sourceVersionId", "parserSignature"))):
        raise Refusal("revision_mismatch", "denied")
    import re
    if re.fullmatch(r"[0-7][0-9A-HJKMNP-TV-Z]{25}", revision["documentRevisionId"]) is None:
        raise Refusal("revision_mismatch", "denied")
    if type(physical_no) is not int or physical_no not in [p["physicalNo"] for p in ir["pages"]]:
        raise Refusal("page_not_found")
    with open_pdf(source, job["limits"]["maxPages"]) as document:
        if physical_no > len(document):
            raise Refusal("page_not_found")
        page = document[physical_no - 1]
        try:
            png = page_png(page, scale)
        finally:
            page.close()
    if len(png) > int(job["limits"]["maxOutputBytes"]):
        raise Refusal("output_limit")
    return RenderedPage(dict(revision), physical_no, png)
