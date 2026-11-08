"""Local metadata and one fixed staged-file job command; no service transport."""

import argparse
import os
import signal
import sys
from pathlib import Path

from .files import read_regular, write_new
from .profiles import PROFILES, lookup
from .protocol import Contracts, Envelope, MAX_INPUT, MAX_MANIFEST, Refusal, canonical, fingerprint, relative_path, sha256


def _cancel(_signal, _frame) -> None:
    raise Refusal("cancelled", "denied")


def run_job(path: str, profiles: dict, contracts: Contracts) -> None:
    # A direct process is never declared a sandbox. The supervisor establishes the mounts.
    if path != "/input/job.json":
        raise Refusal("path_escape", "denied")
    envelope = Envelope.parse(read_regular(Path("/input"), "job.json", 65536, True), contracts)
    job = envelope.value
    source = read_regular(Path("/input"), job["inputPath"][len("/input/"):], MAX_INPUT, True)
    if sha256(source) != job["inputDigest"]:
        raise Refusal("input_digest_mismatch")
    profile = lookup(job["parserProfile"], job["parserSignature"], profiles)
    parsed = profile.parse(source, envelope)
    ir = parsed.ir
    contracts.validate(ir, "document-ir")
    if ir["tenantId"] != job["tenantId"] or ir["sourceVersionId"] != job["sourceVersionId"]:
        raise Refusal("identity_mismatch", "denied")
    if ir["parserSignature"] != job["parserSignature"]:
        raise Refusal("profile_mismatch")
    if len(ir["pages"]) > job["limits"]["maxPages"] or len(parsed.assets) > job["limits"]["maxAssets"]:
        raise Refusal("output_limit")
    if ir["coverage"]["state"] not in ("complete", "partial"):
        raise Refusal("incomplete_result")
    ir_bytes = canonical(ir)
    result = envelope.result(ir, ir_bytes)
    paths = {"ir.json", "result.json"}
    for asset in parsed.assets:
        if not relative_path(asset.relative_path) or asset.relative_path in paths:
            raise Refusal("path_escape", "denied")
        paths.add(asset.relative_path)
        result["assets"].append({"relativePath": asset.relative_path, "digest": sha256(asset.data),
                                 "byteLength": str(len(asset.data)), "mediaType": asset.media_type})
    inventory = sorted((a["digest"], a["byteLength"], a["mediaType"]) for a in ir["assets"])
    if inventory != sorted((a["digest"], a["byteLength"], a["mediaType"]) for a in result["assets"]):
        raise Refusal("inventory_mismatch")
    result["resultDigest"] = fingerprint("knowkit:worker-result:v1", {k: v for k, v in result.items() if k != "resultDigest"})
    result_bytes = canonical(result)
    contracts.validate(result, "worker-result")
    size = len(result_bytes) + len(ir_bytes) + sum(len(a.data) for a in parsed.assets)
    if len(result_bytes) > MAX_MANIFEST or size > int(job["limits"]["maxOutputBytes"]):
        raise Refusal("output_limit")
    for asset in parsed.assets:
        write_new(Path(job["outDir"]), asset.relative_path, asset.data)
    write_new(Path(job["outDir"]), "ir.json", ir_bytes)
    write_new(Path(job["outDir"]), "result.json", result_bytes)


def main(argv: list[str] | None = None, *, profiles: dict | None = None) -> int:
    parser = argparse.ArgumentParser(prog="knowkit-worker")
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("capabilities")
    run = commands.add_parser("run")
    run.add_argument("--envelope", required=True)
    args = parser.parse_args(argv)
    if args.command == "capabilities":
        print(canonical({"schemaVersion": "worker-capabilities-1.0", "transport": "subprocess",
                         "jobSchema": "job-envelope-1.0", "resultSchema": "worker-result-1.0",
                         "profiles": [{"id": p.id, "parserSignature": p.signature,
                                       "qualified": False, "pdf": False, "ocr": False}
                                      for p in PROFILES.values()]}).decode())
        return 0
    signal.signal(signal.SIGTERM, _cancel)
    signal.signal(signal.SIGINT, _cancel)
    os.umask(0o077)
    try:
        run_job(args.envelope, PROFILES if profiles is None else profiles,
                Contracts(Path(__file__).parent / "schemas"))
    except Refusal as exc:
        print(canonical({"outcome": exc.outcome, "code": exc.code}).decode(), file=sys.stderr)
        return {"denied": 3, "unavailable": 4, "incompatible": 5}[exc.outcome]
    except Exception:
        print('{"outcome":"unavailable","code":"worker_failed"}', file=sys.stderr)
        return 4
    return 0
