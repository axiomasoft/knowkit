"""Map available pinned Docling cells; recognition options stay unchanged.

The text profile has table recognition disabled. Empty cells, rich cells and
ambiguous multi-page cell geometry remain unsupported, not guessed structures.
An explicit source-reported multi-page table can use retain_table directly.
"""

from copy import deepcopy

from ..protocol import Refusal, sha256
from ..rendering.pdf import bbox
from .tables import Cell, retain_table


def map_table(item, pages: list[dict], metadata: list[dict], blocks: list[dict], max_bytes: int,
              missing: set | None = None, diagnostics: list[dict] | None = None) -> bool:
    data = getattr(item, "data", None)
    if data is None or not data.table_cells or len(item.prov) != 1:
        return False
    if any(getattr(c, "ref", None) is not None or c.bbox is None for c in data.table_cells):
        return False
    prov = item.prov[0]
    if not 1 <= prov.page_no <= len(pages):
        raise Refusal("native_page_mismatch")
    page = deepcopy(pages[prov.page_no - 1])
    width, height = metadata[prov.page_no - 1]["size"]
    block_id = f"block-{len(blocks) + 1}"
    cells = []
    spans = []
    other_bytes = sum(len(p["text"].encode()) for p in pages) - len(page["text"].encode())
    for index, cell in enumerate(data.table_cells):
        if (cell.row_span != cell.end_row_offset_idx - cell.start_row_offset_idx
                or cell.col_span != cell.end_col_offset_idx - cell.start_col_offset_idx):
            return False
        locators = []
        if cell.text:
            start = len(page["text"].encode("utf-8"))
            if other_bytes + len(page["text"].encode()) + len(cell.text.encode()) + 1 > max_bytes:
                raise Refusal("output_limit")
            page["text"] += cell.text + "\n"
            geometry = bbox(cell.bbox.to_top_left_origin(height).as_tuple(), width, height)
            locators.append(dict(kind="page", blockId=block_id, pageId=page["pageId"],
                                 physicalNo=page["physicalNo"], printedLabel=page["printedLabel"],
                                 byteStart=start, byteEnd=start + len(cell.text.encode()), bbox=geometry))
            spans.extend(locators)
        cells.append(Cell(f"{block_id}-cell-{index + 1}", cell.start_row_offset_idx,
                          cell.start_col_offset_idx, cell.row_span, cell.col_span,
                          cell.column_header or cell.row_header, [], None, [], locators))
    if not spans:
        return False
    if len(spans) > 256:
        return False
    page["textDigest"] = sha256(page["text"].encode())
    block = dict(blockId=block_id, kind="text", parentId=None, order=len(blocks), origin="source",
                 spans=spans, provenance=dict(kind="verbatim", supportingRefs=[]))
    # Validate on scratch state so unsupported geometry never leaves partial cells.
    scratch = dict(pages=[page], segments=[], blocks=[*blocks, block],
                   coverage=dict(state="complete", missingFeatures=[], diagnostics=[]))
    try:
        retain_table(scratch, block_id, data.num_rows, data.num_cols, cells)
    except Refusal as error:
        if error.code.startswith("table_"):
            if missing is not None:
                missing.add("table_structure")
            if diagnostics is not None:
                diagnostics.append(dict(code=error.code, severity="warning"))
            return False
        raise
    if missing is not None:
        missing.update(scratch["coverage"]["missingFeatures"])
    if diagnostics is not None:
        diagnostics.extend(scratch["coverage"]["diagnostics"])
    pages[prov.page_no - 1] = page
    blocks.append(block)
    return True
