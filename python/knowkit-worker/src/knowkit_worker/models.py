"""Lightweight worker result and profile types; no parser activation."""

from dataclasses import dataclass
from typing import Callable, Any

from .protocol import fingerprint


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

