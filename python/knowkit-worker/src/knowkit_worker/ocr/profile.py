"""Exact, build-qualified profiles. Automatic language/engine selection is absent."""

from dataclasses import replace
from functools import partial
from pathlib import Path

from ..protocol import Refusal, fingerprint
from ..parsers.pdf.qualification import ROOT as PDF_ROOT, ready as pdf_ready
from .qualification import NATIVE, ready, record, model_fingerprint


def descriptor(language: str = "eng"):
    from ..profiles import DOCLING, Profile

    if language not in ("eng", "rus"):
        raise Refusal("ocr_language_not_approved")
    return Profile(f"docling-pdf-ocr-{language}-v1", "docling", "2.148.0", {
        **DOCLING.options, "profile": f"docling-pdf-ocr-{language}-v1", "ocr": True,
        "ocrEngine": "TesseractCliOcrOptions", "language": language,
        "modelFingerprint": model_fingerprint(language),
        "ocrQualificationDigest": fingerprint("knowkit:ocr-qualification:v1", record()),
        "selection": "raster-regions-on-original-pages-v1", "scale": 3, "psm": 3,
        "ocrGeometry": "actual-rendered-pixels-half-even-millionths-v1",
        "confidenceThreshold": 700, "maxRegionsPerPage": 128, "wordSeparator": "\n",
        "orientation": "osd-explicit-script-no-language-switch-v1",
    })


def ocr_profile(language: str = "eng", *, native_root: Path = NATIVE,
                model_root: Path = PDF_ROOT / "models", activate: bool = True):
    profile = descriptor(language)
    if not activate or not pdf_ready(model_root):
        return profile
    from .engine import parse

    capabilities = ("parser.text", "parser.images")
    if ready(native_root):
        capabilities += ("parser.ocr",)
    return replace(profile, parse=partial(parse, language=language, native_root=native_root,
                                         model_root=model_root), capabilities=capabilities)
