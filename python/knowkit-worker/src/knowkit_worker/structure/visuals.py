"""Separate original bytes, derived previews, captions and generated prose.

Adapts DeepTutor's exact visual identity and explicit coverage, without its
filesystem lookup or caption matching. Host disclosure checks remain mandatory.
"""

from copy import deepcopy

from ..profiles import Asset, ParsedDocument
from ..protocol import Envelope, Refusal, canonical, sha256
from .retention import block_for, check_region, local_id, text_for


def retain_visual(parsed: ParsedDocument, envelope: Envelope, asset_id: str, data: bytes,
                  media_type: str, page_id: str | None, region: dict | None = None,
                  derived_from: str | None = None) -> ParsedDocument:
    """Original means exact input asset bytes, never a renderer's re-encoding.

    A preview must name its retained original asset. Source page/region stays
    exact on both assets; unpaginated visuals keep pageId=null.
    """
    ir = deepcopy(parsed.ir)
    job = envelope.value
    local_id(asset_id)
    if region is not None:
        check_region(region)
    if any(ir[key] != job[key] for key in ("tenantId", "sourceVersionId", "parserSignature")):
        raise Refusal("structure_identity_mismatch", "denied")
    if (any(a["assetId"] == asset_id for a in ir["assets"])
            or any(b["blockId"] == asset_id for b in ir["blocks"])):
        raise Refusal("structure_id_duplicate")
    if page_id is not None and not any(p["pageId"] == page_id for p in ir["pages"]):
        raise Refusal("structure_locator_unknown")
    if page_id is None and region is not None:
        raise Refusal("structure_locator_mismatch")
    if derived_from is not None:
        original = next((a for a in ir["assets"] if a["assetId"] == derived_from), None)
        if original is None or original["pageId"] != page_id or original.get("bbox") != region:
            raise Refusal("visual_dependency_mismatch")
    extension = {"image/png": "png", "image/jpeg": "jpg", "image/webp": "webp",
                 "image/gif": "gif", "image/tiff": "tiff"}.get(media_type)
    if extension is None:
        raise Refusal("visual_media_unsupported")
    asset = Asset(f"visual-{sha256(asset_id.encode())}.{extension}", data, media_type)
    if any(a.relative_path == asset.relative_path for a in parsed.assets):
        raise Refusal("structure_id_duplicate")
    descriptor = dict(assetId=asset_id, mediaType=media_type, digest=sha256(data),
                      byteLength=str(len(data)), pageId=page_id)
    if region is not None:
        descriptor["bbox"] = deepcopy(region)
    ir["assets"].append(descriptor)
    ir["blocks"].append(dict(blockId=asset_id, kind="image", parentId=None, order=len(ir["blocks"]),
                             origin="generated" if derived_from else "source", spans=[],
                             assetRefs=[asset_id], provenance=dict(
                                 kind="description" if derived_from else "verbatim",
                                 supportingRefs=[derived_from] if derived_from else [])))
    assets = (*parsed.assets, asset)
    if (len(assets) > job["limits"]["maxAssets"]
            or len(canonical(ir)) + sum(len(a.data) for a in assets) > int(job["limits"]["maxOutputBytes"])):
        raise Refusal("output_limit")
    return ParsedDocument(ir, assets)


def bind_caption(ir: dict, caption_id: str, image_id: str) -> None:
    caption = block_for(ir, caption_id)
    image = block_for(ir, image_id)
    if (caption["origin"] not in ("source", "ocr")
            or caption["provenance"]["kind"] != "verbatim" or image["kind"] != "image"):
        raise Refusal("caption_provenance_invalid")
    for span in caption["spans"]:
        text_for(ir, span, caption_id)
    if not caption["spans"]:
        raise Refusal("caption_region_unavailable")
    caption["parentId"] = image_id
    caption["provenance"]["supportingRefs"] = list(image["assetRefs"])


def retain_description(parsed: ParsedDocument, envelope: Envelope, block_id: str,
                       text: str, image_id: str, caption_id: str | None = None) -> ParsedDocument:
    """Generated text receives its own canonical segment, never source offsets."""
    ir = deepcopy(parsed.ir)
    job = envelope.value
    local_id(block_id)
    if any(ir[key] != job[key] for key in ("tenantId", "sourceVersionId", "parserSignature")):
        raise Refusal("structure_identity_mismatch", "denied")
    if not text or any(b["blockId"] == block_id for b in ir["blocks"]):
        raise Refusal("structure_id_duplicate")
    image = block_for(ir, image_id)
    if image["kind"] != "image":
        raise Refusal("visual_dependency_mismatch")
    refs = list(image["assetRefs"])
    if caption_id is not None:
        caption = block_for(ir, caption_id)
        if caption["parentId"] != image_id or caption["origin"] == "generated":
            raise Refusal("caption_provenance_invalid")
        refs.append(caption_id)
    segment_id = f"{block_id}-text"
    local_id(segment_id)
    if any(s["segmentId"] == segment_id for s in ir["segments"]):
        raise Refusal("structure_id_duplicate")
    raw = text.encode("utf-8")
    ir["segments"].append(dict(segmentId=segment_id, text=text, textDigest=sha256(raw), status="ok"))
    ir["blocks"].append(dict(blockId=block_id, kind="text", parentId=image_id, order=len(ir["blocks"]),
                             origin="generated", spans=[dict(kind="segment", blockId=block_id,
                                 segmentId=segment_id, byteStart=0, byteEnd=len(raw))],
                             provenance=dict(kind="description", supportingRefs=refs)))
    counts = ir["coverage"]["counts"]
    if "expected" in counts:
        counts["expected"] += 1
    counts["processed"] += 1
    if len(canonical(ir)) + sum(len(a.data) for a in parsed.assets) > int(job["limits"]["maxOutputBytes"]):
        raise Refusal("output_limit")
    return ParsedDocument(ir, parsed.assets)
