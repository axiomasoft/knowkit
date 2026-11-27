"""Activate local parser runners only after explicit dependency/asset qualification."""

from dataclasses import replace

from .models import Asset, ParsedDocument, Profile

from .protocol import Refusal, fingerprint
from .parsers.pdf.qualification import ROOT as PDF_ROOT, ready as pdf_ready, record as pdf_record
from .ocr.profile import ocr_profile
from .structure.formula_verify import MAX_BRACE_DEPTH, MAX_LATEX_BYTES, MAX_PARSE_MS
from .structure.qualification import ready as structure_ready, record as structure_record


DOCLING = Profile("docling-pdf-text-v1", "docling", "2.148.0",
                  {"parser": "docling", "revision": "2.148.0", "ocr": False,
                   "network": False, "profile": "docling-pdf-text-v1",
                   "qualificationDigest": fingerprint("knowkit:pdf-qualification:v1", pdf_record()),
                   "readingOrder": "docling-native-v1", "geometry": "rotated-half-even-millionths-v1",
                   "tables": False, "formulas": False, "renderPixels": 16_000_000})


STRUCTURE = replace(DOCLING, id="docling-pdf-structure-v1", options={
    **DOCLING.options, "profile": "docling-pdf-structure-v1", "structureRetention": "native-ir-retention-v1",
    "qualificationDigest": fingerprint("knowkit:structure-qualification:v1", structure_record()),
})


FORMULA_SYMPY_VERIFY = Profile(
    "formula-sympy-verify-v1",
    "sympy-formula-verify",
    "1.14.0",
    {
        **STRUCTURE.options,
        "profile": "formula-sympy-verify-v1",
        "formulas": True,
        "formulaVerification": "formula-sympy-verify-v1",
        "formulaMaxBytes": MAX_LATEX_BYTES,
        "formulaMaxDepth": MAX_BRACE_DEPTH,
        "formulaTimeoutMs": MAX_PARSE_MS,
        "network": False,
    },
)


# Optional GPU VLM-OCR profile descriptors: inactive until local weights are staged and qualified.
PADDLEOCR_VL = Profile(
    "paddleocr-vl-v1",
    "paddleocr-vl",
    "0.9-unqualified",
    {
        "parser": "paddleocr-vl",
        "profile": "paddleocr-vl-v1",
        "ocr": True,
        "gpu": True,
        "requiresLocalWeights": True,
        "network": False,
        "tables": True,
        "formulas": True,
    },
)


OLMOCR2_MATH = Profile(
    "olmocr2-math-v1",
    "olmocr2-math",
    "2.0-unqualified",
    {
        "parser": "olmocr2-math",
        "profile": "olmocr2-math-v1",
        "ocr": True,
        "gpu": True,
        "requiresLocalWeights": True,
        "network": False,
        "formulas": True,
        "formulaVerification": "formula-sympy-verify-v1",
    },
)


def pdf_profile(model_root=PDF_ROOT / "models", structured: bool = False) -> Profile:
    from functools import partial
    from .parsers.pdf.engine import parse
    from .parsers.pdf.qualification import verify_dependencies, verify_models

    verify_dependencies()
    verify_models(model_root)
    if structured:
        from .structure.qualification import verify
        verify()
    profile = STRUCTURE if structured else DOCLING
    return replace(profile, parse=partial(parse, model_root=model_root, structured=structured),
                   capabilities=("parser.text", "parser.images"))


PROFILES = {DOCLING.id: pdf_profile() if pdf_ready() else DOCLING}
PROFILES[STRUCTURE.id] = pdf_profile(structured=True) if pdf_ready() and structure_ready() else STRUCTURE
PROFILES[FORMULA_SYMPY_VERIFY.id] = (
    replace(FORMULA_SYMPY_VERIFY, parse=PROFILES[STRUCTURE.id].parse, capabilities=PROFILES[STRUCTURE.id].capabilities)
    if PROFILES[STRUCTURE.id].parse is not None
    else FORMULA_SYMPY_VERIFY
)
PROFILES[PADDLEOCR_VL.id] = PADDLEOCR_VL
PROFILES[OLMOCR2_MATH.id] = OLMOCR2_MATH

# OCR is a separate exact profile; the text profile above always keeps OCR off.
for _language in ("eng", "rus"):
    _profile = ocr_profile(_language)
    PROFILES[_profile.id] = _profile


def lookup(id: str, signature: dict, profiles: dict[str, Profile]) -> Profile:
    profile = profiles.get(id)
    if profile is None:
        raise Refusal("unsupported_profile")
    if signature != profile.signature:
        raise Refusal("profile_mismatch")
    if profile.parse is None:
        raise Refusal("profile_not_qualified", "unavailable")
    return profile
