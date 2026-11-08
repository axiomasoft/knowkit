"""Closed, bounded wire records; schemas are copied verbatim at build time.

Integrity-before-use adapts AgenTools GenericValidator, revision
2054acda51eb69eca1579c78900858c5ff3de01e. No donor runtime is imported.
Private reuse authority: licenses/local-reuse-authorization.md.
"""

from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator
from referencing import Registry, Resource

MAX_MANIFEST = 1 << 20
MAX_INPUT = 64 << 20
CEILINGS = {"wallSeconds": 600, "cpuSeconds": 480, "memoryBytes": 2 << 30,
            "tempBytes": 4 << 30, "maxPages": 500, "maxOutputBytes": 256 << 20,
            "maxAssets": 2000}


class Refusal(Exception):
    """Only a fixed code/outcome crosses the process boundary, never an exception body."""

    def __init__(self, code: str, outcome: str = "incompatible") -> None:
        super().__init__(code)
        self.code = code
        self.outcome = outcome


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result = {}
    for key, value in pairs:
        if key in result:
            raise Refusal("duplicate_key")
        result[key] = value
    return result


def _invalid(_: str) -> Any:
    raise Refusal("non_integer_number")


def _check(value: Any, depth: int = 0) -> None:
    if depth > 64:
        raise Refusal("depth_limit")
    if isinstance(value, str):
        try:
            value.encode("utf-8")
        except UnicodeError:
            raise Refusal("invalid_utf8") from None
    elif type(value) is int:
        if abs(value) > 9007199254740991:
            raise Refusal("unsafe_integer")
    elif isinstance(value, list):
        for item in value:
            _check(item, depth + 1)
    elif isinstance(value, dict):
        for key, item in value.items():
            _check(key, depth + 1)
            _check(item, depth + 1)
    elif value is not None and type(value) is not bool:
        raise Refusal("invalid_json")


def decode(data: bytes, limit: int = MAX_MANIFEST) -> dict[str, Any]:
    if len(data) > limit:
        raise Refusal("document_too_large")
    try:
        value = json.loads(data.decode("utf-8"), object_pairs_hook=_pairs,
                           parse_float=_invalid, parse_constant=_invalid)
        _check(value)
    except (ValueError, UnicodeError, RecursionError):
        raise Refusal("invalid_json") from None
    if not isinstance(value, dict):
        raise Refusal("invalid_json")
    return value


def canonical(value: Any) -> bytes:
    """RFC8785 for the contract's integer-only subset; keys sort as UTF-16 units."""
    _check(value)
    if isinstance(value, dict):
        keys = sorted(value, key=lambda k: k.encode("utf-16-be"))
        return b"{" + b",".join(canonical(k) + b":" + canonical(value[k]) for k in keys) + b"}"
    if isinstance(value, list):
        return b"[" + b",".join(canonical(v) for v in value) + b"]"
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8")


def fingerprint(domain: str, payload: Any) -> str:
    return sha256(domain.encode("ascii") + b"\0" + canonical(payload))


class Contracts:
    def __init__(self, directory: Path) -> None:
        schemas = [decode(p.read_bytes()) for p in sorted(directory.glob("*.schema.json"))]
        self.schemas = {s["$id"]: s for s in schemas}
        self.registry = Registry().with_resources((s["$id"], Resource.from_contents(s)) for s in schemas)

    def validate(self, value: dict[str, Any], name: str) -> None:
        schema = self.schemas[f"urn:knowkit:schema:{name}:1.0"]
        if value.get("schemaVersion") != schema["x-knowkit-schemaVersion"]:
            raise Refusal("unsupported_schema_version")
        if not Draft202012Validator(schema, registry=self.registry).is_valid(value):
            raise Refusal("schema_mismatch")


@dataclass(frozen=True)
class Envelope:
    value: dict[str, Any]

    @classmethod
    def parse(cls, data: bytes, contracts: Contracts) -> Envelope:
        value = decode(data, 65536)
        contracts.validate(value, "job-envelope")
        for name in ("fence", "sourceEpoch"):
            if int(value[name]) > 9223372036854775807:
                raise Refusal("schema_mismatch")
        if value["profileFingerprint"] != value["parserSignature"]["profileDigest"]:
            raise Refusal("profile_mismatch")
        for name, ceiling in CEILINGS.items():
            if not 0 <= int(value["limits"][name]) <= ceiling:
                raise Refusal("limit_exceeded")
        for name in ("memoryBytes", "tempBytes", "maxOutputBytes"):
            if int(value["limits"][name]) == 0:
                raise Refusal("limit_exceeded")
        try:
            expiry = datetime.fromisoformat(value["leaseExpiresAt"].replace("Z", "+00:00"))
        except ValueError:
            raise Refusal("schema_mismatch") from None
        if expiry <= datetime.now(timezone.utc):
            raise Refusal("stale_lease", "denied")
        return cls(value)

    def result(self, ir: dict[str, Any], ir_bytes: bytes) -> dict[str, Any]:
        fields = ("jobId", "attemptId", "tenantId", "sourceId", "sourceVersionId",
                  "fence", "sourceEpoch", "profileFingerprint")
        result = {k: self.value[k] for k in fields}
        result.update(schemaVersion="worker-result-1.0", status=ir["coverage"]["state"],
                      irPath="ir.json", irDigest=sha256(ir_bytes), irByteLength=str(len(ir_bytes)),
                      assets=[], coverage=ir["coverage"], diagnostics=[])
        result["resultDigest"] = fingerprint("knowkit:worker-result:v1", result)
        return result


def relative_path(path: str) -> bool:
    return len(path) <= 1024 and re.fullmatch(
        r"[A-Za-z0-9_-][A-Za-z0-9._-]{0,127}(/[A-Za-z0-9_-][A-Za-z0-9._-]{0,127}){0,15}", path) is not None
