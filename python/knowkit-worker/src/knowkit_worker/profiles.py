"""Descriptors do not import parsers or download models. Qualification is explicit."""

from dataclasses import dataclass, replace
from typing import Callable, Any

from .protocol import Refusal, fingerprint
from .parsers.pdf.qualification import ROOT as PDF_ROOT, ready as pdf_ready, record as pdf_record


@dataclass(frozen=True)
class Asset:
    relative_path: str
    data: bytes
    media_type: str


@dataclass(frozen=True)
class ParsedDocument:
    ir: dict[str, Any]
    assets: tuple[Asset, ...] = ()


@dataclass(frozen=True)
class Profile:
    id: str
    name: str
    version: str
    options: dict[str, Any]
    parse: Callable | None = None
    capabilities: tuple[str, ...] = ()

    @property
    def signature(self) -> dict[str, str]:
        return {"name": self.name, "version": self.version,
                "profileDigest": fingerprint("knowkit:parser-profile:v1", self.options)}


DOCLING = Profile("docling-pdf-text-v1", "docling", "2.148.0",
                  {"parser": "docling", "revision": "2.148.0", "ocr": False,
                   "network": False, "profile": "docling-pdf-text-v1",
                   "qualificationDigest": fingerprint("knowkit:pdf-qualification:v1", pdf_record()),
                   "readingOrder": "docling-native-v1", "geometry": "rotated-half-even-millionths-v1",
                   "tables": False, "formulas": False, "renderPixels": 16_000_000})


def pdf_profile(model_root=PDF_ROOT / "models") -> Profile:
    from functools import partial
    from .parsers.pdf.engine import parse
    from .parsers.pdf.qualification import verify_dependencies, verify_models

    verify_dependencies()
    verify_models(model_root)
    return replace(DOCLING, parse=partial(parse, model_root=model_root),
                   capabilities=("parser.text", "parser.images"))


PROFILES = {DOCLING.id: pdf_profile() if pdf_ready() else DOCLING}


def lookup(id: str, signature: dict, profiles: dict[str, Profile]) -> Profile:
    profile = profiles.get(id)
    if profile is None:
        raise Refusal("unsupported_profile")
    if signature != profile.signature:
        raise Refusal("profile_mismatch")
    if profile.parse is None:
        raise Refusal("profile_not_qualified", "unavailable")
    return profile
