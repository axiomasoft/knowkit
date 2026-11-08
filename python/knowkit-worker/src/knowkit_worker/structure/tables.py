"""Retain explicit cell identities and continuity, never match tables by caption.

Rebases DeepTutor slice/visual-grounding regressions onto immutable IR spans.
No MinerU implementation or donor runtime is used.
"""

from copy import deepcopy
from dataclasses import asdict, dataclass

from ..protocol import Refusal
from .retention import block_for, local_id, text_for, unavailable


@dataclass(frozen=True)
class Cell:
    cellId: str
    row: int
    column: int
    rowSpan: int
    colSpan: int
    header: bool
    headerRefs: list[str]
    unit: str | None
    noteRefs: list[str]
    spans: list[dict]


def retain_table(ir: dict, block_id: str, rows: int, columns: int, cells: list[Cell]) -> None:
    """One source-declared table ID may span pages or unpaginated segments.

    Repeated headers remain actual cells. Header/note/unit associations must
    come from the source adapter; no text heuristics add them here.
    """
    block = block_for(ir, block_id)
    if not 1 <= rows <= 65535 or not 1 <= columns <= 65535 or not 1 <= len(cells) <= 100000:
        raise Refusal("table_shape_invalid")
    by_id = {cell.cellId: cell for cell in cells}
    if len(by_id) != len(cells):
        raise Refusal("table_cell_duplicate")
    active = []
    comparisons = 0
    for cell in sorted(cells, key=lambda c: (c.row, c.column)):
        _validate_cell(ir, block_id, rows, columns, cell, by_id)
        # Bounded sweep, never materialize rowSpan * colSpan grid entries.
        active = [previous for previous in active if previous.row + previous.rowSpan > cell.row]
        comparisons += len(active)
        if comparisons > 1_000_000:
            raise Refusal("table_validation_limit")
        if any(cell.column < p.column + p.colSpan and p.column < cell.column + cell.colSpan for p in active):
            raise Refusal("table_cell_overlap")
        active.append(cell)
    block["kind"] = "table"
    block["table"] = {"rows": rows, "columns": columns, "cells": [deepcopy(asdict(c)) for c in cells]}
    block["provenance"]["kind"] = "table_representation"
    if any(not cell.spans for cell in cells):
        unavailable(ir, "table_cell_locators", "table_cell_locator_unavailable")


def _validate_cell(ir, block_id, rows, columns, cell, by_id):
    local_id(cell.cellId)
    if cell.unit is not None and (not isinstance(cell.unit, str) or not 1 <= len(cell.unit) <= 64):
        raise Refusal("table_unit_invalid")
    if (not 0 <= cell.row < rows or not 0 <= cell.column < columns
            or not 1 <= cell.rowSpan <= rows - cell.row
            or not 1 <= cell.colSpan <= columns - cell.column):
        raise Refusal("table_cell_out_of_range")
    if any(ref not in by_id or not by_id[ref].header for ref in cell.headerRefs):
        raise Refusal("table_header_unknown")
    for ref in cell.noteRefs:
        block_for(ir, ref)
    for span in cell.spans:
        text_for(ir, span, block_id)
