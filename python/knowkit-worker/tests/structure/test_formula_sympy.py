import ast
from copy import deepcopy
import json
import socket
from unittest.mock import patch

import pytest

from knowkit_worker.profiles import (
    FORMULA_SYMPY_VERIFY,
    OLMOCR2_MATH,
    PADDLEOCR_VL,
    PROFILES,
    lookup,
)
from knowkit_worker.protocol import Contracts, Refusal
from knowkit_worker.structure import formula_verify
from knowkit_worker.structure.formula_verify import (
    MAX_BRACE_DEPTH,
    MAX_LATEX_BYTES,
    MAX_PARSE_MS,
    FormulaVerifyResult,
    verify_latex_formula,
)
from knowkit_worker.structure.formulas import FormulaMetadata, RETRY_BBOX_DPI, retain_formula

from .conftest import ROOT


def _replace_span_text(ir: dict, block_id: str, new_text: str) -> None:
    from knowkit_worker.protocol import sha256

    block = next(b for b in ir["blocks"] if b["blockId"] == block_id)
    span = block["spans"][0]
    page = next(p for p in ir["pages"] if p["pageId"] == span["pageId"])
    page["text"] = new_text
    raw = new_text.encode("utf-8")
    page["textDigest"] = sha256(raw)
    span["byteStart"] = 0
    span["byteEnd"] = len(raw)


@pytest.mark.parametrize(
    "latex",
    [
        r"E = m c^2",
        r"\frac{a + b}{c}",
        r"\sqrt[3]{x^2 + 1}",
        r"\sin(\theta) + \cos(\theta) = 1",
        r"\sum_{i=1}^{n} i^2",
        r"$$x_1 \le x_2$$",
        r"\left| x - y \right| > 0",
        r"\left( a + b \right)",
        r"\left[ x + 1 \right]",
    ],
)
def test_valid_latex_formulas_produce_canonical_latex(latex: str):
    with patch.object(socket.socket, "connect", side_effect=AssertionError("network attempted")):
        result = verify_latex_formula(latex)
    assert isinstance(result, FormulaVerifyResult)
    assert result.valid is True
    assert result.canonical_latex
    assert result.diagnostic_code is None


def test_rejects_oversized_and_deeply_nested_formulas():
    too_long = "x+" * (MAX_LATEX_BYTES // 2) + "x"
    assert len(too_long.encode("utf-8")) > MAX_LATEX_BYTES
    res_long = verify_latex_formula(too_long)
    assert res_long == FormulaVerifyResult(False, None, "formula_too_long")

    ok_depth = "{" * MAX_BRACE_DEPTH + "x" + "}" * MAX_BRACE_DEPTH
    assert verify_latex_formula(ok_depth).valid is True

    too_deep = "{" * (MAX_BRACE_DEPTH + 1) + "x" + "}" * (MAX_BRACE_DEPTH + 1)
    res_deep = verify_latex_formula(too_deep)
    assert res_deep == FormulaVerifyResult(False, None, "formula_too_deep")


def test_enforces_strict_parse_timeout():
    ticks = iter([0.0, 0.0, (MAX_PARSE_MS + 50) / 1000.0])
    with patch.object(formula_verify.time, "monotonic", side_effect=lambda: next(ticks, 10.0)):
        res = verify_latex_formula("x + y + z")
    assert res == FormulaVerifyResult(False, None, "formula_timeout")


@pytest.mark.parametrize(
    "malicious",
    [
        "__import__('os').system('id')",
        "x + __builtins__",
        r"\write18{cat /etc/passwd}",
        r"\input{/etc/passwd}",
        r"\include{secret}",
        r"\def\foo{bar}",
        r"\url{https://example.com}",
        "E = m \ufffd c^2",
        "x + \x00 y",
        "   ",
        r"\frac{1}{}",
        "x + * y",
    ],
)
def test_rejects_injection_macros_and_invalid_syntax(malicious: str):
    res = verify_latex_formula(malicious)
    assert res.valid is False
    assert res.canonical_latex is None
    assert res.diagnostic_code == "formula_ast_invalid"


def test_formula_verify_source_never_calls_eval_exec_or_sympify():
    tree = ast.parse(open(formula_verify.__file__, encoding="utf-8").read())
    forbidden = {"eval", "exec", "sympify", "parse_expr"}
    for node in ast.walk(tree):
        if isinstance(node, ast.Call):
            func = node.func
            name = func.id if isinstance(func, ast.Name) else func.attr if isinstance(func, ast.Attribute) else None
            assert name not in forbidden, f"forbidden call {name} found in formula_verify.py"


def test_retain_formula_retries_bbox_on_invalid_ast_and_recovers(oracle):
    ir = deepcopy(oracle["ir"])
    _replace_span_text(ir, "equation", "E = m \ufffd c^2")
    calls = []

    def retry_render(block, regions, dpi):
        calls.append((block["blockId"], len(regions), dpi))
        return r"E = m c^2", 950

    res = retain_formula(ir, "equation", confidence=400, verify_ast=True, retry_render=retry_render)
    assert res is not None and res.valid is True
    assert calls == [("equation", 1, RETRY_BBOX_DPI)]
    meta = FormulaMetadata.from_ir(ir, "equation")
    assert meta.confidence == 950
    assert meta.representation == r"E = m c^2"
    Contracts(ROOT / "schemas").validate(ir, "document-ir")
    assert not any(d["code"] == "formula_ast_invalid" for d in ir["coverage"]["diagnostics"])
    assert not any(d["code"] == "formula_transcription_uncertain" for d in ir["coverage"]["diagnostics"])


def test_retain_formula_records_diagnostic_and_lowers_confidence_on_unrecoverable_ast(oracle):
    ir = deepcopy(oracle["ir"])
    _replace_span_text(ir, "equation", r"\frac{1}{}")

    res = retain_formula(ir, "equation", confidence=850, verify_ast=True)
    assert res is not None and res.valid is False
    assert res.diagnostic_code == "formula_ast_invalid"
    meta = FormulaMetadata.from_ir(ir, "equation")
    assert meta.confidence is not None and meta.confidence <= 200
    assert ir["coverage"]["state"] == "partial"
    assert "formula_transcription" in ir["coverage"]["missingFeatures"]
    codes = {d["code"] for d in ir["coverage"]["diagnostics"]}
    assert "formula_ast_invalid" in codes
    assert "formula_transcription_uncertain" in codes
    Contracts(ROOT / "schemas").validate(ir, "document-ir")


def test_profiles_register_sympy_and_unqualified_gpu_vlm_descriptors():
    for profile in (FORMULA_SYMPY_VERIFY, PADDLEOCR_VL, OLMOCR2_MATH):
        assert profile.id in PROFILES
        assert PROFILES[profile.id].options["network"] is False
    for gpu_profile in (PADDLEOCR_VL, OLMOCR2_MATH):
        assert gpu_profile.options["gpu"] is True
        assert gpu_profile.options["requiresLocalWeights"] is True
        assert gpu_profile.parse is None
        with pytest.raises(Refusal, match="profile_not_qualified"):
            lookup(gpu_profile.id, gpu_profile.signature, PROFILES)


def test_license_qualifications_keep_mineru_and_pymupdf_blocked():
    qdir = ROOT / "licenses/qualifications"
    for blocked in ("mineru.json", "pymupdf.json", "paddleocr-vl.json", "olmocr2-math.json"):
        data = json.loads((qdir / blocked).read_text(encoding="utf-8"))
        assert data["verdict"] == "blocked"
        assert data["networkAtRuntime"]["required"] is not True
    sympy_q = json.loads((qdir / "sympy-formula-verify.json").read_text(encoding="utf-8"))
    assert sympy_q["verdict"] in ("allowed", "restricted")
    assert sympy_q["networkAtRuntime"]["required"] is False
    assert "formula-sympy-verify-v1" in sympy_q["enabledProfiles"]
