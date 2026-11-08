"""Descriptors do not import parsers or download models. Qualification is explicit."""

from dataclasses import dataclass
from typing import Callable, Any

from .protocol import Refusal, fingerprint


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

    @property
    def signature(self) -> dict[str, str]:
        return {"name": self.name, "version": self.version,
                "profileDigest": fingerprint("knowkit:parser-profile:v1", self.options)}


DOCLING = Profile("docling-pdf-text-v1", "docling", "2.148.0",
                  {"parser": "docling", "revision": "2.148.0", "ocr": False,
                   "network": False, "profile": "docling-pdf-text-v1"})
PROFILES = {DOCLING.id: DOCLING}


def lookup(id: str, signature: dict, profiles: dict[str, Profile]) -> Profile:
    profile = profiles.get(id)
    if profile is None:
        raise Refusal("unsupported_profile")
    if signature != profile.signature:
        raise Refusal("profile_mismatch")
    if profile.parse is None:
        raise Refusal("profile_not_qualified", "unavailable")
    return profile
