"""Independent authored fingerprint oracle; reads fixtures and never rewrites expectations.

All object member names in these authored preimages are ASCII, so Python's key
order equals JCS UTF-16 order. Values retain exact UTF-8, including decomposed e.
Changing an expected digest requires a fixture version and an authored rationale.
"""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


def sha(text):
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def jcs(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def profile_digest(name):
    profile = json.loads((ROOT / f"tests/fixtures/host-profiles/{name}.json").read_text())
    descriptors = {}
    for port, binding in profile["adapterBindings"].items():
        name = binding["adapterId"].split(".", 1)[1]
        schema = {
            "$schema": "https://json-schema.org/draft/2020-12/schema",
            "$id": "urn:knowkit:example:" + name + "-config:1", "type": "object",
            "properties": {
                "mode": {"enum": ["local", "alternate"]},
                "credentials": {
                    "type": "object", "required": ["handle", "present"],
                    "properties": {"handle": {"type": "string"}, "present": {"type": "boolean"}},
                    "unevaluatedProperties": False,
                },
            }, "unevaluatedProperties": False,
        }
        descriptors[binding["adapterId"]] = {
            "kind": port, "version": "1", "packageFingerprint": sha("synthetic-package:1"),
            "weightsFingerprint": None, "configSchemaDigest": sha(jcs(schema)),
            "capabilities": [{"id": cap, "requires": []} for cap in binding["capabilities"]],
            "features": {"ocr.language.ru": False, "parser.geometry": False} if name == "parser" else {},
            "requiresExt": [], "tokenizerIdentity": profile["tokenizer"] if port == "tokenizer" else None,
        }
    profile["adapters"] = descriptors
    return sha(jcs(profile))


def expected():
    return {"projectA": profile_digest("project-a"), "projectB": profile_digest("project-b")}


if __name__ == "__main__":
    golden = json.loads((ROOT / "tests/fixtures/host-profiles/fingerprints.v1.json").read_text())
    actual = expected()
    assert all(golden[key] == value for key, value in actual.items()), "authored profile digest mismatch"
    data = json.loads((ROOT / "packages/core/conformance/fixtures/corpus.v1.json").read_text())
    assert sha(data["text"]) == data["sourceDigest"]
    assert all(len(vector["text"]) == vector["count"] for vector in data["tokenVectors"])
    print(json.dumps({"ok": True, "profileDigests": actual, "tokenVectors": len(data["tokenVectors"])}))
