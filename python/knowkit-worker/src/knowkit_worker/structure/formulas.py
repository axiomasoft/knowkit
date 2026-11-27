"""Accessible source transcription with exact regions and explicit confidence.

IR 1.0 has no formula metadata property. A versioned diagnostic code binds
permille extraction confidence to the exact block ID, without schema changes.
Unknown confidence is distinct from zero; neither means semantic correctness.
"""

from dataclasses import dataclass
import re
from typing import Callable

from ..protocol import Refusal, sha256
from .formula_verify import FormulaVerifyResult, verify_latex_formula
from .retention import block_for, text_for, unavailable

RETRY_BBOX_DPI = 300
_INVALID_AST_CONFIDENCE_CAP = 200


def prefix(block_id: str) -> str:
    return "formula_v1_" + sha256(block_id.encode("ascii"))[:24] + "_conf_"


@dataclass(frozen=True)
class FormulaMetadata:
    confidence: int | None
    representation: str
    regions: tuple[dict, ...]

    @classmethod
    def from_ir(cls, ir: dict, block_id: str):
        block = block_for(ir, block_id)
        codes = [d["code"][len(prefix(block_id)):]
                 for d in ir["coverage"]["diagnostics"] if d["code"].startswith(prefix(block_id))]
        if (block["kind"] != "formula" or len(codes) != 1
                or not re.fullmatch(r"unknown|0|[1-9][0-9]{0,3}", codes[0])):
            raise Refusal("formula_metadata_invalid")
        confidence = None if codes[0] == "unknown" else int(codes[0])
        if confidence is not None and confidence > 1000:
            raise Refusal("formula_metadata_invalid")
        regions = block["spans"]
        if not regions or any(s["kind"] == "page" and "bbox" not in s for s in regions):
            raise Refusal("formula_region_unavailable")
        representation = "\n".join(text_for(ir, s, block_id) for s in regions)
        return cls(confidence, representation, tuple(regions))


def retain_formula(
    ir: dict,
    block_id: str,
    confidence: int | None = None,
    *,
    verify_ast: bool = False,
    retry_render: Callable[[dict, tuple[dict, ...], int], tuple[str, int | None]] | None = None,
    retry_dpi: int = RETRY_BBOX_DPI,
) -> FormulaVerifyResult | None:
    if confidence is not None and (type(confidence) is not int or not 0 <= confidence <= 1000):
        raise Refusal("formula_metadata_invalid")
    block = block_for(ir, block_id)
    regions = block["spans"]
    if not regions or any(s["kind"] == "page" and "bbox" not in s for s in regions):
        raise Refusal("formula_region_unavailable")
    text = "\n".join(text_for(ir, s, block_id) for s in regions)
    diagnostics = ir["coverage"]["diagnostics"]
    if any(d["code"].startswith(prefix(block_id)) for d in diagnostics):
        raise Refusal("formula_metadata_duplicate")
    if len(diagnostics) >= 1023:
        raise Refusal("structure_diagnostic_limit")

    verify_result: FormulaVerifyResult | None = None
    effective_confidence = confidence
    if verify_ast:
        verify_result = verify_latex_formula(text)
        if not verify_result.valid and verify_result.diagnostic_code == "formula_ast_invalid" and retry_render is not None:
            retried_text, retried_confidence = retry_render(block, tuple(regions), retry_dpi)
            if retried_confidence is not None and (type(retried_confidence) is not int or not 0 <= retried_confidence <= 1000):
                raise Refusal("formula_metadata_invalid")
            retry_check = verify_latex_formula(retried_text)
            if retry_check.valid:
                verify_result = retry_check
                text = retried_text
                if retried_confidence is not None:
                    effective_confidence = retried_confidence
        if not verify_result.valid:
            code = verify_result.diagnostic_code or "formula_ast_invalid"
            unavailable(ir, "formula_transcription", code)
            effective_confidence = 0 if effective_confidence is None else min(effective_confidence, _INVALID_AST_CONFIDENCE_CAP)

    block["kind"] = "formula"
    diagnostics.append({"code": prefix(block_id) + ("unknown" if effective_confidence is None else str(effective_confidence)),
                        "severity": "info"})
    if effective_confidence is None or effective_confidence < 900 or "\ufffd" in text:
        unavailable(ir, "formula_transcription", "formula_transcription_uncertain")
    return verify_result
