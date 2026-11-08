"""Exact IR locators shared by table, formula and visual retention."""

import re

from ..protocol import Refusal, sha256


def local_id(value: str) -> None:
    if not isinstance(value, str) or not re.fullmatch(r"[A-Za-z0-9._:-]{1,128}", value):
        raise Refusal("structure_id_invalid")


def check_region(region: dict) -> None:
    if (set(region) != {"space", "origin", "view", "x0", "y0", "x1", "y1"}
            or region["space"] != "page-millionths-top-left-v1" or region["origin"] != "top-left"
            or region["view"] != "rotated-page"
            or any(type(region[k]) is not int for k in ("x0", "y0", "x1", "y1"))
            or not 0 <= region["x0"] < region["x1"] <= 1_000_000
            or not 0 <= region["y0"] < region["y1"] <= 1_000_000):
        raise Refusal("structure_region_invalid")


def block_for(ir: dict, block_id: str) -> dict:
    local_id(block_id)
    for block in ir["blocks"]:
        if block["blockId"] == block_id:
            return block
    raise Refusal("structure_block_unknown", "unknown")


def text_for(ir: dict, span: dict, block_id: str) -> str:
    if span["blockId"] != block_id:
        raise Refusal("structure_locator_mismatch")
    kind = span["kind"]
    if kind not in ("page", "segment"):
        raise Refusal("structure_locator_mismatch")
    key = "pageId" if kind == "page" else "segmentId"
    units = ir["pages"] if kind == "page" else ir["segments"]
    unit = next((unit for unit in units if unit[key] == span[key]), None)
    if unit is None:
        raise Refusal("structure_locator_unknown")
    if kind == "segment" and ("physicalNo" in span or "bbox" in span):
        raise Refusal("structure_locator_mismatch")
    if kind == "page" and (span["physicalNo"] != unit["physicalNo"]
                            or span["printedLabel"] != unit["printedLabel"]):
        raise Refusal("structure_locator_mismatch")
    if "bbox" in span:
        check_region(span["bbox"])
    raw = unit["text"].encode("utf-8")
    lo, hi = span["byteStart"], span["byteEnd"]
    if sha256(raw) != unit["textDigest"] or not 0 <= lo < hi <= len(raw):
        raise Refusal("structure_text_mismatch")
    try:
        raw[:lo].decode("utf-8")
        return raw[lo:hi].decode("utf-8")
    except UnicodeError:
        raise Refusal("structure_utf8_boundary") from None


def unavailable(ir: dict, feature: str, code: str, unit_id: str | None = None) -> None:
    coverage = ir["coverage"]
    diagnostic = {"code": code, "severity": "warning"}
    if unit_id is not None:
        diagnostic["segmentId"] = unit_id
    if diagnostic not in coverage["diagnostics"]:
        if len(coverage["diagnostics"]) >= 1024:
            raise Refusal("structure_diagnostic_limit")
        coverage["diagnostics"].append(diagnostic)
    coverage["missingFeatures"] = sorted(set(coverage["missingFeatures"]) | {feature})
    if coverage["state"] != "unknown":
        coverage["state"] = "partial"
