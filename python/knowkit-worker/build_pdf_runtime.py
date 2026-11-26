#!/usr/bin/env python3
"""Explicit controlled build; model downloads never occur in the worker.

First sync uv.lock. Run --fetch-models --model-root <private-directory> once,
then --model-root <same-directory> --destination <new-runtime-root> offline.
The output extends P6.1's sandbox runtime; models and notices enter its BOM.
"""

import argparse
import importlib.util
import json
from pathlib import Path
import shutil
import urllib.request

from knowkit_worker.parsers.pdf.qualification import record, verify_dependencies, verify_models
from knowkit_worker.protocol import sha256


def fetch(root: Path) -> None:
    root.mkdir(parents=True, exist_ok=True)
    for asset in record()["modelAssets"]:
        path = root / asset["path"]
        path.parent.mkdir(parents=True, exist_ok=True)
        if path.exists() and sha256(path.read_bytes()) == asset["sha256"]:
            continue
        with urllib.request.urlopen(asset["source"], timeout=60) as source:
            data = source.read(asset["byteLength"] + 1)
        if len(data) != asset["byteLength"] or sha256(data) != asset["sha256"]:
            raise RuntimeError("model download digest mismatch")
        path.write_bytes(data)
    verify_models(root)


def build(root: Path, models: Path) -> None:
    verify_dependencies()
    verify_models(models)
    repo = Path(__file__).resolve().parents[2]
    spec = importlib.util.spec_from_file_location("base_worker_build", repo / "deploy/worker/build_runtime.py")
    base = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(base)
    base.build(root, repo)
    site = root / "runtime/lib/python3.12/site-packages"
    target = site / "knowkit_worker/parsers/pdf/models"
    shutil.copytree(models, target)
    shutil.copytree(Path(__file__).parent / "third_party", root / "runtime/third_party")
    (root / "sbom.json").write_text(json.dumps({
        "schemaVersion": "knowkit-pdf-sbom-1.0",
        "lockDigest": record()["lockDigest"],
        "components": record()["transitiveComponents"],
        "modelAssets": record()["modelAssets"],
    }, sort_keys=True, indent=2) + "\n")
    base.write_bom(root, repo)
    bom_path = root / "bom.json"
    bom = json.loads(bom_path.read_text())
    bom.update(models=record()["modelAssets"], qualifiedParserProfiles=["docling-pdf-text-v1"])
    bom_path.write_text(json.dumps(bom, sort_keys=True, indent=2) + "\n")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("--model-root", required=True, type=Path)
    parser.add_argument("--fetch-models", action="store_true")
    parser.add_argument("--destination", type=Path)
    args = parser.parse_args()
    if args.fetch_models:
        fetch(args.model_root)
    if args.destination:
        build(args.destination, args.model_root)
    if not args.fetch_models and not args.destination:
        parser.error("choose --fetch-models or --destination")
