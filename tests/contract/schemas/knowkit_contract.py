#!/usr/bin/env python3
"""KnowKit wire-contract reference checker (Python side of the cross-language corpus).

Independent re-implementation of the PHP KnowKit\\Domain\\Contract classes: strict
JSON decoding, RFC 8785 JCS, the typed hash-domain table, the bounded JSON Schema
2020-12 subset with x-knowkit keywords, semantic rules, locator conversion and
scope/selection binding. Normative text: specs/contracts/wire-contract.md.

Adapted (not imported) patterns, see the P1.4 reuse receipt: AgenTools
2054acda51eb69eca1579c78900858c5ff3de01e Wire/Integrity/CanonicalJson strict
decoding contract; DeepTutor f7aed0744c36c5afef16a4261c31ebee1208cac2
parsing/types.py zero-based page_idx and parsing/signature.py (rejected: truncated
hex, codepoint key order). Standard library only; Python >= 3.10.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
import re
import sys
from typing import Any

MAX_DEPTH = 64
SAFE_INTEGER = 9007199254740991
DEFAULT_MAX_DOCUMENT_BYTES = 1048576
CORPUS_MANIFEST = "tests/fixtures/contracts/manifest.v1.json"
HASH_VECTORS = "tests/fixtures/contracts/v1/vectors/hash-vectors.json"
LOCATOR_VECTORS = "tests/fixtures/contracts/v1/vectors/locator-vectors.json"
BINDING_VECTORS = "tests/fixtures/contracts/v1/vectors/binding-vectors.json"
COMMON_ID = "urn:knowkit:schema:common:1.0"

HASH_DOMAINS = {
    "indexManifest": "knowkit:index-manifest:v1",
    "publicationSnapshotManifest": "knowkit:publication-snapshot:v1",
    "chunkSetManifest": "knowkit:chunkset-manifest:v1",
    "embeddingSpace": "knowkit:embedding-space:v1",
    "domainEventPayload": "knowkit:event:v1",
    "workerResult": "knowkit:worker-result:v1",
    "processingIdentity": "knowkit:processing-identity:v1",
    "parserProfile": "knowkit:parser-profile:v1",
    "chunkerProfile": "knowkit:chunker-profile:v1",
    "queryBinding": "knowkit:query-binding:v1",
    "scopeBinding": "knowkit:scope-binding:v1",
    "policyBinding": "knowkit:policy-binding:v1",
    "decisionReceipt": "knowkit:decision-receipt:v1",
    "retrievalCacheKey": "knowkit:retrieval-cache:v1",
    "sourceIdentity": "knowkit:source-identity:v1",
    "dependencyNodeIdentity": "knowkit:dependency-node:v1",
    "dependencyParentSet": "knowkit:dependency-parents:v1",
    "idempotencyRequest": "knowkit:command:v1",
    "evidenceSet": "knowkit:evidence-set:v1",
    "resourceInventory": "knowkit:resource-inventory:v1",
    "tombstoneSet": "knowkit:tombstone-set:v1",
    "answerInvocationFingerprint": "knowkit:answer-invocation:v1",
    "answerOutcomeRecord": "knowkit:answer-outcome:v1",
}

SUPPORTED_KEYWORDS = {
    "$schema", "$id", "$defs", "$ref", "$comment", "title", "description", "type", "const", "enum",
    "properties", "required", "unevaluatedProperties", "additionalProperties", "propertyNames",
    "maxProperties", "minProperties", "items", "minItems", "maxItems", "uniqueItems", "contains",
    "minLength", "maxLength", "pattern", "minimum", "maximum", "allOf", "if", "then", "else",
    "x-knowkit-decimalMax", "x-knowkit-lessThan", "x-knowkit-uniqueBy", "x-knowkit-sortedBy",
    "x-knowkit-maxDocumentBytes", "x-knowkit-schemaVersion",
}


class ContractError(Exception):
    """A typed contract rejection with a stable error code and JSON Pointer path."""

    def __init__(self, code: str, path: str = "") -> None:
        super().__init__(code + " at " + (path or "/"))
        self.code = code
        self.path = path


class SchemaDefinitionError(Exception):
    """The bundled schema set itself is malformed or uses an unsupported keyword."""


# ---------------------------------------------------------------- strict JSON

def _pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ContractError("duplicate_key")
        result[key] = value
    return result


def _parse_int(text: str) -> int:
    digits = text.lstrip("-")
    if len(digits) > 16 or int(digits) > SAFE_INTEGER:
        raise ContractError("unsafe_integer")
    return int(text)


def _parse_float(_text: str) -> Any:
    raise ContractError("non_integer_number")


def _parse_constant(_text: str) -> Any:
    raise ContractError("invalid_json")


def _max_depth(text: str) -> int:
    depth = deepest = 0
    in_string = escaped = False
    for char in text:
        if in_string:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                in_string = False
        elif char == '"':
            in_string = True
        elif char in "[{":
            depth += 1
            deepest = max(deepest, depth)
        elif char in "]}":
            depth -= 1
    return deepest


def _check_strings(value: Any) -> None:
    stack = [value]
    while stack:
        item = stack.pop()
        if isinstance(item, str):
            try:
                item.encode("utf-8")
            except UnicodeEncodeError:
                raise ContractError("invalid_surrogate") from None
        elif isinstance(item, list):
            stack.extend(item)
        elif isinstance(item, dict):
            for key, child in item.items():
                stack.append(key)
                stack.append(child)


def decode(data: bytes, max_bytes: int = DEFAULT_MAX_DOCUMENT_BYTES) -> Any:
    """Decode strict UTF-8 JSON: no duplicate keys, surrogates, non-integers, unsafe ints."""
    if len(data) > max_bytes:
        raise ContractError("document_too_large")
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError:
        raise ContractError("invalid_utf8") from None
    if _max_depth(text) > MAX_DEPTH:
        raise ContractError("depth_exceeded")
    try:
        value = json.loads(text, object_pairs_hook=_pairs, parse_int=_parse_int,
                           parse_float=_parse_float, parse_constant=_parse_constant)
    except ContractError:
        raise
    except (ValueError, RecursionError):
        raise ContractError("invalid_json") from None
    _check_strings(value)
    return value


# ---------------------------------------------------------------- JCS (RFC 8785)

_SHORT_ESCAPES = {'"': '\\"', "\\": "\\\\", "\b": "\\b", "\f": "\\f", "\n": "\\n", "\r": "\\r", "\t": "\\t"}


def _jcs_string(value: str) -> str:
    out = ['"']
    for char in value:
        if char in _SHORT_ESCAPES:
            out.append(_SHORT_ESCAPES[char])
        elif ord(char) < 0x20:
            out.append("\\u%04x" % ord(char))
        else:
            out.append(char)
    out.append('"')
    return "".join(out)


def jcs(value: Any) -> str:
    """RFC 8785 canonical text for the KnowKit integer-only JSON profile."""
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, int):
        if abs(value) > SAFE_INTEGER:
            raise ContractError("unsafe_integer")
        return str(value)
    if isinstance(value, float):
        raise ContractError("non_integer_number")
    if isinstance(value, str):
        _check_strings(value)
        return _jcs_string(value)
    if isinstance(value, list):
        return "[" + ",".join(jcs(item) for item in value) + "]"
    if isinstance(value, dict):
        keys = sorted(value.keys(), key=lambda key: key.encode("utf-16-be"))
        return "{" + ",".join(_jcs_string(key) + ":" + jcs(value[key]) for key in keys) + "}"
    raise ContractError("invalid_json")


def sha256_hex(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def ir_digest(ir: Any) -> str:
    return sha256_hex(jcs(ir).encode("utf-8"))


def without(payload: dict[str, Any], field: str | None) -> dict[str, Any]:
    if field is None:
        return payload
    return {key: value for key, value in payload.items() if key != field}


def domain_digest(domain_key: str, payload: Any) -> str:
    if domain_key not in HASH_DOMAINS:
        raise ContractError("unknown_reference")
    return sha256_hex(HASH_DOMAINS[domain_key].encode("ascii") + b"\x00" + jcs(payload).encode("utf-8"))


# ---------------------------------------------------------------- schema subset

def _escape(token: str) -> str:
    return token.replace("~", "~0").replace("/", "~1")


def _is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def json_equal(left: Any, right: Any) -> bool:
    if isinstance(left, bool) or isinstance(right, bool):
        return type(left) is type(right) and left == right
    if isinstance(left, dict) and isinstance(right, dict):
        return left.keys() == right.keys() and all(json_equal(left[k], right[k]) for k in left)
    if isinstance(left, list) and isinstance(right, list):
        return len(left) == len(right) and all(json_equal(a, b) for a, b in zip(left, right))
    if _is_int(left) and _is_int(right):
        return left == right
    if isinstance(left, str) and isinstance(right, str):
        return left == right
    return left is None and right is None


def _type_ok(expected: Any, value: Any) -> bool:
    names = expected if isinstance(expected, list) else [expected]
    for name in names:
        if name in ("integer", "number") and _is_int(value):
            return True
        if name == "string" and isinstance(value, str):
            return True
        if name == "boolean" and isinstance(value, bool):
            return True
        if name == "null" and value is None:
            return True
        if name == "array" and isinstance(value, list):
            return True
        if name == "object" and isinstance(value, dict):
            return True
    return False


class SchemaSet:
    """Offline registry of bundled schemas keyed by $id (urn:knowkit:schema:<name>:1.0)."""

    def __init__(self, schemas: dict[str, Any]) -> None:
        self.schemas = schemas
        self._patterns: dict[str, re.Pattern[str]] = {}
        for schema_id, schema in schemas.items():
            self._lint(schema, schema_id)

    @classmethod
    def load(cls, root: str) -> "SchemaSet":
        directory = os.path.join(root, "schemas")
        schemas: dict[str, Any] = {}
        for name in sorted(os.listdir(directory)):
            if not name.endswith(".schema.json"):
                continue
            with open(os.path.join(directory, name), "rb") as handle:
                schema = decode(handle.read(), 4194304)
            expected = "urn:knowkit:schema:" + name[: -len(".schema.json")] + ":1.0"
            if not isinstance(schema, dict) or schema.get("$id") != expected:
                raise SchemaDefinitionError("schema $id mismatch: " + name)
            if schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
                raise SchemaDefinitionError("schema dialect must be 2020-12: " + name)
            schemas[expected] = schema
        return cls(schemas)

    def _lint(self, schema: Any, base: str) -> None:
        if isinstance(schema, bool):
            return
        if not isinstance(schema, dict):
            raise SchemaDefinitionError("schema must be object/boolean")
        unknown = set(schema) - SUPPORTED_KEYWORDS
        if unknown:
            raise SchemaDefinitionError("unsupported keyword: " + ",".join(sorted(unknown)))
        if "unevaluatedProperties" in schema and schema["unevaluatedProperties"] is not False:
            raise SchemaDefinitionError("unevaluatedProperties must be false")
        if "pattern" in schema:
            pattern = schema["pattern"]
            if not (pattern.startswith("^") and pattern.endswith("$")):
                raise SchemaDefinitionError("pattern must be anchored: " + pattern)
            self._patterns[pattern] = re.compile(pattern[1:-1])
        if "$ref" in schema:
            self.resolve(schema["$ref"], base)
        for key in ("properties", "$defs"):
            for child in schema.get(key, {}).values():
                self._lint(child, base)
        for key in ("additionalProperties", "propertyNames", "items", "contains", "if", "then", "else"):
            if key in schema:
                self._lint(schema[key], base)
        for child in schema.get("allOf", []):
            self._lint(child, base)

    def resolve(self, ref: str, base: str) -> tuple[Any, str]:
        document, _, fragment = ref.partition("#")
        document = document or base
        if document not in self.schemas:
            raise SchemaDefinitionError("unresolved $ref: " + ref)
        node = self.schemas[document]
        if fragment:
            for token in fragment.lstrip("/").split("/"):
                token = token.replace("~1", "/").replace("~0", "~")
                if not isinstance(node, dict) or token not in node:
                    raise SchemaDefinitionError("unresolved $ref: " + ref)
                node = node[token]
        return node, document

    def validate(self, instance: Any, schema_id: str) -> list[tuple[str, str]]:
        if schema_id not in self.schemas or schema_id == COMMON_ID:
            raise ContractError("unknown_schema")
        errors: list[tuple[str, str]] = []
        self._evaluate(self.schemas[schema_id], instance, "", schema_id, errors)
        return sort_errors(errors)

    def _evaluate(self, schema: Any, value: Any, path: str, base: str, errors: list[tuple[str, str]]) -> set[str]:
        if schema is True:
            return set()
        if schema is False:
            errors.append(("forbidden_field", path))
            return set()
        if "type" in schema and not _type_ok(schema["type"], value):
            errors.append(("type", path))
            return set()
        evaluated: set[str] = set()
        if "$ref" in schema:
            target, target_base = self.resolve(schema["$ref"], base)
            evaluated |= self._evaluate(target, value, path, target_base, errors)
        if "const" in schema and not json_equal(schema["const"], value):
            errors.append(("const", path))
        if "enum" in schema and not any(json_equal(option, value) for option in schema["enum"]):
            errors.append(("enum", path))
        if isinstance(value, dict):
            evaluated |= self._object(schema, value, path, base, errors)
        elif isinstance(value, list):
            self._array(schema, value, path, base, errors)
        elif isinstance(value, str):
            self._string(schema, value, path, errors)
        elif _is_int(value):
            if "minimum" in schema and value < schema["minimum"]:
                errors.append(("minimum", path))
            if "maximum" in schema and value > schema["maximum"]:
                errors.append(("maximum", path))
        for child in schema.get("allOf", []):
            evaluated |= self._evaluate(child, value, path, base, errors)
        if "if" in schema:
            probe: list[tuple[str, str]] = []
            matched = self._evaluate(schema["if"], value, path, base, probe)
            if not probe:
                evaluated |= matched
                if "then" in schema:
                    evaluated |= self._evaluate(schema["then"], value, path, base, errors)
            elif "else" in schema:
                evaluated |= self._evaluate(schema["else"], value, path, base, errors)
        if isinstance(value, dict) and schema.get("unevaluatedProperties") is False:
            for key in value:
                if key not in evaluated:
                    errors.append(("unknown_field", path + "/" + _escape(key)))
            evaluated |= set(value)
        return evaluated

    def _object(self, schema: dict[str, Any], value: dict[str, Any], path: str, base: str,
                errors: list[tuple[str, str]]) -> set[str]:
        evaluated: set[str] = set()
        for name in schema.get("required", []):
            if name not in value:
                errors.append(("required", path + "/" + _escape(name)))
        properties = schema.get("properties", {})
        for name, child in properties.items():
            if name in value:
                evaluated.add(name)
                self._evaluate(child, value[name], path + "/" + _escape(name), base, errors)
        if "additionalProperties" in schema:
            for key, child_value in value.items():
                if key not in properties:
                    evaluated.add(key)
                    self._evaluate(schema["additionalProperties"], child_value, path + "/" + _escape(key), base, errors)
        if "propertyNames" in schema:
            for key in value:
                probe: list[tuple[str, str]] = []
                self._evaluate(schema["propertyNames"], key, path + "/" + _escape(key), base, probe)
                if probe:
                    errors.append(("property_name", path + "/" + _escape(key)))
        if "minProperties" in schema and len(value) < schema["minProperties"]:
            errors.append(("min_properties", path))
        if "maxProperties" in schema and len(value) > schema["maxProperties"]:
            errors.append(("max_properties", path))
        rule = schema.get("x-knowkit-lessThan")
        if rule is not None:
            for left, right in rule["pairs"]:
                a, b = value.get(left), value.get(right)
                if _is_int(a) and _is_int(b) and not (a <= b if rule.get("orEqual") else a < b):
                    errors.append((rule["code"], path + "/" + _escape(right)))
        return evaluated

    def _array(self, schema: dict[str, Any], value: list[Any], path: str, base: str,
               errors: list[tuple[str, str]]) -> None:
        if "items" in schema:
            for index, item in enumerate(value):
                self._evaluate(schema["items"], item, path + "/" + str(index), base, errors)
        if "minItems" in schema and len(value) < schema["minItems"]:
            errors.append(("min_items", path))
        if "maxItems" in schema and len(value) > schema["maxItems"]:
            errors.append(("max_items", path))
        if schema.get("uniqueItems") is True:
            for index in range(len(value)):
                if any(json_equal(value[index], value[other]) for other in range(index)):
                    errors.append(("unique_items", path + "/" + str(index)))
        if "contains" in schema:
            found = False
            for index, item in enumerate(value):
                probe: list[tuple[str, str]] = []
                self._evaluate(schema["contains"], item, path + "/" + str(index), base, probe)
                if not probe:
                    found = True
                    break
            if not found:
                errors.append(("contains", path))
        key = schema.get("x-knowkit-uniqueBy")
        if key is not None:
            seen: set[str] = set()
            for index, item in enumerate(value):
                if isinstance(item, dict) and isinstance(item.get(key), str):
                    if item[key] in seen:
                        errors.append(("duplicate_identifier", path + "/" + str(index) + "/" + _escape(key)))
                    seen.add(item[key])
        keys = schema.get("x-knowkit-sortedBy")
        if keys is not None:
            previous = None
            for index, item in enumerate(value):
                if not isinstance(item, dict) or not all(isinstance(item.get(k), str) for k in keys):
                    continue
                current = tuple(item[k].encode("utf-8") for k in keys)
                if previous is not None and not previous < current:
                    errors.append(("unsorted_members", path + "/" + str(index)))
                previous = current

    def _string(self, schema: dict[str, Any], value: str, path: str, errors: list[tuple[str, str]]) -> None:
        if "minLength" in schema and len(value) < schema["minLength"]:
            errors.append(("min_length", path))
        if "maxLength" in schema and len(value) > schema["maxLength"]:
            errors.append(("max_length", path))
        if "pattern" in schema and self._patterns[schema["pattern"]].fullmatch(value) is None:
            errors.append(("pattern", path))
        maximum = schema.get("x-knowkit-decimalMax")
        if maximum is not None and re.fullmatch("[0-9]+", value):
            if len(value) > len(maximum) or (len(value) == len(maximum) and value > maximum):
                errors.append(("decimal_out_of_range", path))


def sort_errors(errors: list[tuple[str, str]]) -> list[tuple[str, str]]:
    return sorted(set(errors), key=lambda error: (error[1].encode("utf-8"), error[0].encode("utf-8")))


# ---------------------------------------------------------------- semantic rules

def _utf8(text: str) -> bytes:
    return text.encode("utf-8")


def _check_locator(locator: dict[str, Any], block_id: str, prefix: str, pages: dict[str, Any],
                   segments: dict[str, Any], errors: list[tuple[str, str]]) -> None:
    if locator["blockId"] != block_id:
        errors.append(("binding_mismatch", prefix + "/blockId"))
    if locator["kind"] == "page":
        page = pages.get(locator["pageId"])
        if page is None:
            errors.append(("unknown_reference", prefix + "/pageId"))
            return
        if page["physicalNo"] != locator["physicalNo"]:
            errors.append(("binding_mismatch", prefix + "/physicalNo"))
        if not json_equal(page["printedLabel"], locator["printedLabel"]):
            errors.append(("binding_mismatch", prefix + "/printedLabel"))
        text = page["text"]
    else:
        segment = segments.get(locator["segmentId"])
        if segment is None:
            errors.append(("unknown_reference", prefix + "/segmentId"))
            return
        text = segment["text"]
    data = _utf8(text)
    if locator["byteEnd"] > len(data):
        errors.append(("span_out_of_range", prefix + "/byteEnd"))
        return
    for field in ("byteStart", "byteEnd"):
        offset = locator[field]
        if offset < len(data) and (data[offset] & 0xC0) == 0x80:
            errors.append(("utf8_boundary", prefix + "/" + field))


def _units(ir: dict[str, Any], errors: list[tuple[str, str]]) -> dict[str, int]:
    tally = {"ok": 0, "empty": 0, "failed": 0, "skipped": 0}
    for collection in ("pages", "segments"):
        for index, unit in enumerate(ir[collection]):
            prefix = "/" + collection + "/" + str(index)
            if sha256_hex(_utf8(unit["text"])) != unit["textDigest"]:
                errors.append(("digest_mismatch", prefix + "/textDigest"))
            if unit["status"] == "ok" and unit["text"] == "":
                errors.append(("page_status_mismatch", prefix + "/status"))
            if unit["status"] != "ok" and unit["text"] != "":
                errors.append(("page_status_mismatch", prefix + "/text"))
            tally[unit["status"]] += 1
    return tally


def semantic_document_ir(ir: dict[str, Any]) -> list[tuple[str, str]]:
    errors: list[tuple[str, str]] = []
    pages = {page["pageId"]: page for page in ir["pages"]}
    segments = {segment["segmentId"]: segment for segment in ir["segments"]}
    blocks = {block["blockId"]: block for block in ir["blocks"]}
    assets = {asset["assetId"] for asset in ir["assets"]}
    tally = _units(ir, errors)
    for index in range(1, len(ir["pages"])):
        if ir["pages"][index]["physicalNo"] <= ir["pages"][index - 1]["physicalNo"]:
            errors.append(("order_violation", "/pages/" + str(index) + "/physicalNo"))
    for index, block in enumerate(ir["blocks"]):
        prefix = "/blocks/" + str(index)
        block_id = block["blockId"]
        if index > 0 and block["order"] <= ir["blocks"][index - 1]["order"]:
            errors.append(("order_violation", prefix + "/order"))
        parent = block["parentId"]
        if parent is not None:
            if parent == block_id:
                errors.append(("self_reference", prefix + "/parentId"))
            elif parent not in blocks:
                errors.append(("unknown_reference", prefix + "/parentId"))
            else:
                seen = {block_id}
                cursor = parent
                while cursor is not None and cursor in blocks:
                    if cursor in seen:
                        if cursor == block_id:
                            errors.append(("reference_cycle", prefix + "/parentId"))
                        break
                    seen.add(cursor)
                    cursor = blocks[cursor]["parentId"]
        for ref_index, ref in enumerate(block["provenance"]["supportingRefs"]):
            ref_path = prefix + "/provenance/supportingRefs/" + str(ref_index)
            if ref == block_id:
                errors.append(("self_reference", ref_path))
            elif ref not in blocks and ref not in assets:
                errors.append(("unknown_reference", ref_path))
        for ref_index, ref in enumerate(block.get("assetRefs", [])):
            if ref not in assets:
                errors.append(("unknown_reference", prefix + "/assetRefs/" + str(ref_index)))
        for span_index, span in enumerate(block["spans"]):
            _check_locator(span, block_id, prefix + "/spans/" + str(span_index), pages, segments, errors)
        table = block.get("table")
        if table is not None:
            headers = {cell["cellId"] for cell in table["cells"] if cell["header"]}
            for cell_index, cell in enumerate(table["cells"]):
                cell_path = prefix + "/table/cells/" + str(cell_index)
                if cell["row"] + cell["rowSpan"] > table["rows"] or cell["column"] + cell["colSpan"] > table["columns"]:
                    errors.append(("table_cell_out_of_range", cell_path))
                for ref_index, ref in enumerate(cell["headerRefs"]):
                    if ref not in headers:
                        errors.append(("unknown_reference", cell_path + "/headerRefs/" + str(ref_index)))
                for ref_index, ref in enumerate(cell["noteRefs"]):
                    if ref not in blocks:
                        errors.append(("unknown_reference", cell_path + "/noteRefs/" + str(ref_index)))
                for span_index, span in enumerate(cell["spans"]):
                    _check_locator(span, block_id, cell_path + "/spans/" + str(span_index), pages, segments, errors)
    for index, asset in enumerate(ir["assets"]):
        if asset["pageId"] is not None and asset["pageId"] not in pages:
            errors.append(("unknown_reference", "/assets/" + str(index) + "/pageId"))
    coverage = ir["coverage"]
    counts = coverage["counts"]
    expected_counts = {"processed": tally["ok"] + tally["empty"], "empty": tally["empty"],
                       "failed": tally["failed"], "skipped": tally["skipped"]}
    for field, number in expected_counts.items():
        if counts[field] != number:
            errors.append(("coverage_mismatch", "/coverage/counts/" + field))
    total = sum(tally.values())
    if "expected" in counts and counts["expected"] != total:
        errors.append(("coverage_mismatch", "/coverage/counts/expected"))
    for index, diagnostic in enumerate(coverage["diagnostics"]):
        if "segmentId" in diagnostic and diagnostic["segmentId"] not in segments and diagnostic["segmentId"] not in pages:
            errors.append(("unknown_reference", "/coverage/diagnostics/" + str(index) + "/segmentId"))
    if coverage["state"] == "complete":
        has_error = any(diagnostic["severity"] == "error" for diagnostic in coverage["diagnostics"])
        if tally["failed"] or tally["skipped"] or coverage["missingFeatures"] or has_error:
            errors.append(("coverage_mismatch", "/coverage/state"))
    return errors


def derive_deletion_status(states: list[str]) -> str:
    completed = {"deleted", "absent"}
    if all(state in completed for state in states):
        return "done"
    if any(state in completed for state in states):
        return "partial"
    if all(state == "unsupported" for state in states):
        return "unsupported"
    if any(state in ("unknown", "unsupported") for state in states):
        return "unknown"
    return "pending"


def semantic(schema_id: str, doc: Any) -> list[tuple[str, str]]:
    name = schema_id.split(":")[3]
    errors: list[tuple[str, str]] = []
    if name == "document-ir":
        errors = semantic_document_ir(doc)
    elif name == "decision-receipt":
        if domain_digest("decisionReceipt", without(doc, "receiptDigest")) != doc["receiptDigest"]:
            errors.append(("digest_mismatch", "/receiptDigest"))
    elif name == "worker-result":
        if domain_digest("workerResult", without(doc, "resultDigest")) != doc["resultDigest"]:
            errors.append(("digest_mismatch", "/resultDigest"))
    elif name == "domain-event":
        if domain_digest("domainEventPayload", doc["payload"]) != doc["payloadDigest"]:
            errors.append(("digest_mismatch", "/payloadDigest"))
    elif name == "job-envelope":
        if doc["profileFingerprint"] != doc["parserSignature"]["profileDigest"]:
            errors.append(("binding_mismatch", "/profileFingerprint"))
    elif name == "evidence-set":
        total = 0
        versions: dict[tuple[str, str], str] = {}
        revisions: dict[tuple[str, str, str], str] = {}
        for index, fragment in enumerate(doc["fragments"]):
            prefix = "/fragments/" + str(index)
            data = _utf8(fragment["text"])
            total += len(data)
            if sha256_hex(data) != fragment["fragmentDigest"]:
                errors.append(("digest_mismatch", prefix + "/fragmentDigest"))
            if fragment["citation"]["contentDigest"] != fragment["fragmentDigest"]:
                errors.append(("digest_mismatch", prefix + "/citation/contentDigest"))
            if fragment["citation"]["tenantId"] != doc["tenantId"]:
                errors.append(("binding_mismatch", prefix + "/citation/tenantId"))
            # One immutable SourceVersion has one sourceDigest and one DocumentRevision one irDigest.
            citation = fragment["citation"]
            version = (citation["tenantId"], citation["sourceVersionId"])
            revision = version + (citation["documentRevisionId"],)
            if versions.setdefault(version, citation["sourceDigest"]) != citation["sourceDigest"]:
                errors.append(("binding_mismatch", prefix + "/citation/sourceDigest"))
            if revisions.setdefault(revision, citation["irDigest"]) != citation["irDigest"]:
                errors.append(("binding_mismatch", prefix + "/citation/irDigest"))
            if (len(fragment["citation"]["spans"]) > 1) != (fragment["joiner"] is not None):
                errors.append(("binding_mismatch", prefix + "/joiner"))
        if doc["snapshotRef"]["tenantId"] != doc["tenantId"]:
            errors.append(("binding_mismatch", "/snapshotRef/tenantId"))
        if total != doc["totalBytes"]:
            errors.append(("count_mismatch", "/totalBytes"))
        if domain_digest("evidenceSet", without(doc, "evidenceSetDigest")) != doc["evidenceSetDigest"]:
            errors.append(("digest_mismatch", "/evidenceSetDigest"))
    elif name == "index-manifest":
        counts = doc["counts"]
        if counts["members"] != len(doc["members"]):
            errors.append(("count_mismatch", "/counts/members"))
        if counts["resources"] != len(doc["resources"]):
            errors.append(("count_mismatch", "/counts/resources"))
        if counts["chunks"] != sum(member["chunkCount"] for member in doc["members"]):
            errors.append(("count_mismatch", "/counts/chunks"))
    elif name == "deletion-task":
        if derive_deletion_status([r["state"] for r in doc["requiredResources"]]) != doc["status"]:
            errors.append(("status_mismatch", "/status"))
    elif name == "selection":
        for index, ref in enumerate(doc["candidateRefs"]):
            if ref["tenantId"] != doc["tenantId"]:
                errors.append(("binding_mismatch", "/candidateRefs/" + str(index) + "/tenantId"))
    elif name == "usage":
        for index, ref in enumerate(doc["fragmentRefs"]):
            if ref["chunkRef"]["tenantId"] != doc["tenantId"]:
                errors.append(("binding_mismatch", "/fragmentRefs/" + str(index) + "/chunkRef/tenantId"))
    return errors


class ContractValidator:
    def __init__(self, schemas: SchemaSet) -> None:
        self.schemas = schemas

    def validate_bytes(self, data: bytes, schema_id: str) -> list[tuple[str, str]]:
        if schema_id not in self.schemas.schemas or schema_id == COMMON_ID:
            raise ContractError("unknown_schema")
        schema = self.schemas.schemas[schema_id]
        try:
            document = decode(data, schema.get("x-knowkit-maxDocumentBytes", DEFAULT_MAX_DOCUMENT_BYTES))
        except ContractError as error:
            return [(error.code, "")]
        return self.validate_value(document, schema_id)

    def validate_value(self, document: Any, schema_id: str) -> list[tuple[str, str]]:
        schema = self.schemas.schemas[schema_id]
        version = schema.get("x-knowkit-schemaVersion")
        if version is not None and isinstance(document, dict) and isinstance(document.get("schemaVersion"), str) \
                and document["schemaVersion"] != version:
            return [("unsupported_schema_version", "/schemaVersion")]
        errors = self.schemas.validate(document, schema_id)
        if not errors:
            errors = sort_errors(semantic(schema_id, document))
        return errors


# ---------------------------------------------------------------- conversion

_DECIMAL = re.compile("(0|[1-9][0-9]{0,5})(\\.[0-9]{1,6})?")


def _micro(value: Any) -> int:
    if not isinstance(value, str) or _DECIMAL.fullmatch(value) is None:
        raise ContractError("invalid_decimal")
    whole, _, fraction = value.partition(".")
    return int(whole) * 1000000 + int(fraction.ljust(6, "0") or "0")


def _half_even(numerator: int, denominator: int) -> int:
    quotient, remainder = divmod(numerator, denominator)
    if remainder * 2 > denominator or (remainder * 2 == denominator and quotient % 2 == 1):
        quotient += 1
    return quotient


def normalize_bbox(source: dict[str, Any]) -> dict[str, Any]:
    """Decimal-string page coordinates -> integer millionths, half-even, explicit origin."""
    origin = source.get("coordinateOrigin")
    if origin not in ("top-left", "bottom-left"):
        raise ContractError("missing_coordinate_system")
    width, height = _micro(source.get("pageWidth")), _micro(source.get("pageHeight"))
    x0, y0, x1, y1 = (_micro(source.get(key)) for key in ("x0", "y0", "x1", "y1"))
    if width == 0 or height == 0 or max(x0, x1) > width or max(y0, y1) > height:
        raise ContractError("invalid_bbox")
    if origin == "bottom-left":
        y0, y1 = height - y1, height - y0
    result = {"space": "page-millionths-top-left-v1", "origin": "top-left", "view": "rotated-page",
              "x0": _half_even(x0 * 1000000, width), "y0": _half_even(y0 * 1000000, height),
              "x1": _half_even(x1 * 1000000, width), "y1": _half_even(y1 * 1000000, height)}
    if not (result["x0"] < result["x1"] and result["y0"] < result["y1"]):
        raise ContractError("invalid_bbox")
    return result


def page_index_to_locator(source: dict[str, Any]) -> dict[str, Any]:
    """Zero-based donor page_idx -> 1-based physicalNo; printed label kept separately."""
    index = source.get("pageIndex")
    if not _is_int(index) or index < 0 or index >= 4294967295:
        raise ContractError("invalid_page_index")
    label = source.get("printedLabel")
    if label is not None and (not isinstance(label, str) or label == ""):
        raise ContractError("invalid_page_index")
    start, end = source.get("byteStart"), source.get("byteEnd")
    if not _is_int(start) or not _is_int(end) or start < 0 or end <= start:
        raise ContractError("invalid_span")
    locator: dict[str, Any] = {"kind": "page", "pageId": source.get("pageId"), "physicalNo": index + 1,
                               "printedLabel": label, "blockId": source.get("blockId"),
                               "byteStart": start, "byteEnd": end}
    if "bbox" in source:
        box = dict(source["bbox"]) if isinstance(source["bbox"], dict) else {}
        for key in ("pageWidth", "pageHeight", "coordinateOrigin"):
            if key in source:
                box[key] = source[key]
        locator["bbox"] = normalize_bbox(box)
    return locator


def resolve_span(source: dict[str, Any]) -> dict[str, Any]:
    text, start, end = source.get("text"), source.get("byteStart"), source.get("byteEnd")
    if not isinstance(text, str) or not _is_int(start) or not _is_int(end) or start < 0 or end <= start:
        raise ContractError("invalid_span")
    data = _utf8(text)
    if end > len(data):
        raise ContractError("span_out_of_range")
    for offset in (start, end):
        if offset < len(data) and (data[offset] & 0xC0) == 0x80:
            raise ContractError("utf8_boundary")
    return {"text": data[start:end].decode("utf-8"), "byteLength": end - start}


# ---------------------------------------------------------------- binding

def policy_binding_digest(binding: dict[str, Any]) -> str:
    return domain_digest("policyBinding", binding)


def scope_binding_digest(scope: dict[str, Any], policy_digest: str) -> str:
    return domain_digest("scopeBinding", {"tenantId": scope["tenantId"], "kbId": scope["kbId"],
                                          "actorId": scope["actorId"], "purpose": scope["purpose"],
                                          "hostScopeHash": scope["hostScopeHash"],
                                          "policyBindingDigest": policy_digest})


def check_scope_selection(scope: dict[str, Any], selection: dict[str, Any]) -> dict[str, str]:
    if scope["tenantId"] != selection["tenantId"] or scope["hostScopeHash"] != selection["hostScopeHash"]:
        return {"outcome": "denied", "code": "scope_mismatch"}
    digest = scope_binding_digest(scope, policy_binding_digest(selection["policyBinding"]))
    if digest != selection["scopeBindingDigest"]:
        return {"outcome": "denied", "code": "scope_mismatch"}
    if scope["policyEpoch"] != selection["policyBinding"]["kbPolicyEpoch"]:
        return {"outcome": "denied", "code": "POLICY_CHANGED"}
    return {"outcome": "success"}


def check_disclosure_selection(request: dict[str, Any], selection: dict[str, Any]) -> dict[str, str]:
    if request["selectionId"] != selection["selectionId"]:
        return {"outcome": "denied", "code": "selection_ref_mismatch"}
    # Trusted run binding: usageContext.runRef (absent = direct library call = None) must equal
    # Selection.runRef verbatim; it is stored outside the scopeBinding payload.
    run_ref = request["usageContext"]["runRef"] if "usageContext" in request else None
    if run_ref != selection["runRef"]:
        return {"outcome": "denied", "code": "scope_mismatch"}
    for ref in request["chunkRefs"]:
        if not any(json_equal(ref, candidate) for candidate in selection["candidateRefs"]):
            return {"outcome": "denied", "code": "selection_ref_mismatch"}
    return {"outcome": "success"}


# ---------------------------------------------------------------- corpus

def identity_conflicts(documents: dict[str, Any]) -> list[dict[str, str]]:
    """Records naming one documentRevisionId (sourceVersionId) must agree on irDigest (sourceDigest)."""
    seen: dict[str, dict[str, str]] = {"irDigest": {}, "sourceDigest": {}}
    conflicts: list[dict[str, str]] = []

    def walk(node: Any, path: str, entry_id: str) -> None:
        if isinstance(node, dict):
            for key, field in (("documentRevisionId", "irDigest"), ("sourceVersionId", "sourceDigest")):
                if isinstance(node.get(key), str) and isinstance(node.get(field), str):
                    if seen[field].setdefault(node[key], node[field]) != node[field]:
                        conflicts.append({"entry": entry_id, "path": path + "/" + field, "field": field})
            for name, child in node.items():
                walk(child, path + "/" + _escape(name), entry_id)
        elif isinstance(node, list):
            for index, child in enumerate(node):
                walk(child, path + "/" + str(index), entry_id)

    for entry_id, document in documents.items():
        walk(document, "", entry_id)
    return conflicts


def _read(root: str, relative: str) -> bytes:
    path = os.path.realpath(os.path.join(root, relative))
    real_root = os.path.realpath(root)
    if not path.startswith(real_root + os.sep) or not os.path.isfile(path):
        raise ContractError("missing_file")
    with open(path, "rb") as handle:
        return handle.read()


def load_manifest(root: str, schemas: SchemaSet, manifest_path: str) -> dict[str, Any]:
    validator = ContractValidator(schemas)
    with open(manifest_path, "rb") as handle:
        data = handle.read()
    errors = validator.validate_bytes(data, "urn:knowkit:schema:fixture-manifest:1.0")
    if errors:
        raise ContractError(errors[0][0], errors[0][1])
    manifest = decode(data, 4194304)
    seen: set[str] = set()
    for index, entry in enumerate(manifest["entries"]):
        prefix = "/entries/" + str(index)
        if entry["id"] in seen:
            raise ContractError("duplicate_identifier", prefix + "/id")
        seen.add(entry["id"])
        if entry["schemaId"] not in schemas.schemas or entry["schemaId"] == COMMON_ID:
            raise ContractError("unknown_schema", prefix + "/schemaId")
        try:
            content = _read(root, entry["path"])
        except ContractError:
            raise ContractError("missing_file", prefix + "/path") from None
        if sha256_hex(content) != entry["sha256"]:
            raise ContractError("digest_mismatch", prefix + "/sha256")
    for index, tag in enumerate(manifest["requiredTags"]):
        if not any(tag in entry["tags"] for entry in manifest["entries"]):
            raise ContractError("empty_case_group", "/requiredTags/" + str(index))
    return manifest


def _vectors(root: str, validator: ContractValidator, relative: str, schema: str) -> list[dict[str, Any]]:
    data = _read(root, relative)
    errors = validator.validate_bytes(data, schema)
    if errors:
        raise ContractError(errors[0][0], relative + "#" + errors[0][1])
    return decode(data, 4194304)["vectors"]


def hash_report(root: str, validator: ContractValidator) -> list[dict[str, Any]]:
    results = []
    for vector in _vectors(root, validator, HASH_VECTORS, "urn:knowkit:schema:hash-vectors:1.0"):
        algorithm = vector["algorithm"]
        entry: dict[str, Any] = {"id": vector["id"]}
        if algorithm == "jcs":
            canonical = jcs(vector["payload"])
            entry.update(canonical=canonical, sha256=sha256_hex(canonical.encode("utf-8")))
        elif algorithm == "domain":
            payload = vector["payload"]
            if "excludeField" in vector:
                payload = without(payload, vector["excludeField"])
            entry.update(canonical=jcs(payload), sha256=domain_digest(vector["domain"], payload))
        elif algorithm == "ir":
            entry.update(sha256=ir_digest(decode(_read(root, vector["payloadPath"]), 67108864)))
        elif algorithm == "raw":
            entry.update(sha256=sha256_hex(vector["payloadUtf8"].encode("utf-8")))
        elif algorithm == "decode":
            try:
                decode(base64.b64decode(vector["inputBase64"], validate=True))
                entry.update(error=None)
            except ContractError as error:
                entry.update(error=error.code)
        elif algorithm == "legacy":
            entry.update(sha256=sha256_hex(jcs(vector["payload"]).encode("utf-8")))
        results.append(entry)
    return results


def locator_report(root: str, validator: ContractValidator) -> list[dict[str, Any]]:
    operations = {"page_index_to_locator": page_index_to_locator, "normalize_bbox": normalize_bbox,
                  "resolve_span": resolve_span}
    results = []
    for vector in _vectors(root, validator, LOCATOR_VECTORS, "urn:knowkit:schema:locator-vectors:1.0"):
        try:
            results.append({"id": vector["id"], "result": operations[vector["op"]](vector["input"])})
        except ContractError as error:
            results.append({"id": vector["id"], "error": error.code})
    return results


def binding_report(root: str, validator: ContractValidator) -> list[dict[str, Any]]:
    results = []
    for vector in _vectors(root, validator, BINDING_VECTORS, "urn:knowkit:schema:binding-vectors:1.0"):
        data = vector["input"]
        inputs = [("scope", "scope"), ("selection", "selection")] if vector["op"] == "scope_selection" \
            else [("request", "disclosure-request"), ("selection", "selection")]
        invalid = []
        for field, name in inputs:
            errors = validator.validate_value(data.get(field), "urn:knowkit:schema:" + name + ":1.0")
            invalid.extend({"field": field, "code": code, "path": path} for code, path in errors)
        if invalid:
            results.append({"id": vector["id"], "inputErrors": invalid})
            continue
        if vector["op"] == "scope_selection":
            outcome = check_scope_selection(data["scope"], data["selection"])
        else:
            outcome = check_disclosure_selection(data["request"], data["selection"])
        results.append(dict({"id": vector["id"]}, **outcome))
    return results


def report(root: str) -> dict[str, Any]:
    schemas = SchemaSet.load(root)
    validator = ContractValidator(schemas)
    manifest = load_manifest(root, schemas, os.path.join(root, CORPUS_MANIFEST))
    corpus = []
    valid: dict[str, Any] = {}
    for entry in manifest["entries"]:
        errors = validator.validate_bytes(_read(root, entry["path"]), entry["schemaId"])
        corpus.append({"id": entry["id"], "errors": [{"code": code, "path": path} for code, path in errors]})
        if entry["expected"] == "valid":
            valid[entry["id"]] = decode(_read(root, entry["path"]), 67108864)
    roundtrip = []
    for entry in manifest["entries"]:
        if "ir-roundtrip" not in entry["tags"]:
            continue
        first = decode(_read(root, entry["path"]), 67108864)
        canonical = jcs(first)
        second = decode(canonical.encode("utf-8"), 67108864)
        roundtrip.append({"id": entry["id"], "irDigest": ir_digest(first),
                          "stable": canonical == jcs(second) and json_equal(first, second)})
    return {"schemaVersion": "contract-report-1.0", "corpusVersion": manifest["corpusVersion"],
            "schemas": sorted(schemas.schemas), "corpus": corpus, "hashVectors": hash_report(root, validator),
            "locatorVectors": locator_report(root, validator), "bindingVectors": binding_report(root, validator),
            "irRoundTrip": roundtrip, "hashDomains": HASH_DOMAINS, "identityConflicts": identity_conflicts(valid)}


def main(argv: list[str]) -> int:
    args = dict(zip(argv[1::2], argv[2::2])) if len(argv) > 1 else {}
    command = argv[0] if argv else ""
    root = os.path.realpath(args.get("--root", os.getcwd()))
    try:
        if command == "report" and len(argv) == 3:
            output: Any = report(root)
        elif command == "manifest" and len(argv) == 5:
            load_manifest(root, SchemaSet.load(root), args["--manifest"])
            output = {"ok": True}
        elif command == "validate" and len(argv) == 7:
            with open(args["--file"], "rb") as handle:
                errors = ContractValidator(SchemaSet.load(root)).validate_bytes(handle.read(), args["--schema"])
            output = {"errors": [{"code": code, "path": path} for code, path in errors]}
        else:
            sys.stderr.write("usage: report --root R | manifest --root R --manifest M | "
                             "validate --root R --schema ID --file F\n")
            return 2
    except ContractError as error:
        sys.stdout.write(json.dumps({"error": {"code": error.code, "path": error.path}}) + "\n")
        return 1
    sys.stdout.write(json.dumps(output, ensure_ascii=True, sort_keys=True) + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
