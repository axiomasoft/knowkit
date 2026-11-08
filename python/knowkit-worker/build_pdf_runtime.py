#!/usr/bin/env python3
"""Explicit controlled build; model downloads never occur in the worker.

First sync uv.lock. Run --fetch-models --model-root <private-directory> once,
then --model-root <same-directory> --destination <new-runtime-root> offline.
The output extends P6.1's sandbox runtime; models and notices enter its BOM.
"""

import argparse
from importlib import metadata
import importlib.util
import json
from pathlib import Path
import shutil
import sys
import urllib.request

from packaging.requirements import Requirement
from packaging.utils import canonicalize_name

from knowkit_worker.parsers.pdf.qualification import record, verify_dependencies, verify_models
from knowkit_worker.protocol import sha256


def distributions() -> list:
    pending = [("knowkit-worker", frozenset())]
    visited = set()
    names = set()
    while pending:
        name, extras = pending.pop()
        name = canonicalize_name(name)
        if (name, extras) in visited:
            continue
        visited.add((name, extras))
        names.add(name)
        dist = metadata.distribution(name)
        for text in dist.requires or []:
            req = Requirement(text)
            if req.marker is None or any(req.marker.evaluate({"extra": e}) for e in extras | {""}):
                pending.append((req.name, frozenset(req.extras)))
    return [metadata.distribution(name) for name in sorted(names - {"knowkit-worker"})]


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
    # Unlike the baseline builder, retain dependencies selected through extras.
    for dist in distributions():
        for item in dist.files or []:
            if ".." in item.parts or "__pycache__" in item.parts:
                continue
            source = Path(dist.locate_file(item))
            if source.is_file():
                target = site / item
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, target)
    target = site / "knowkit_worker/parsers/pdf/models"
    shutil.copytree(models, target)
    shutil.copytree(Path(__file__).parent / "third_party", root / "runtime/third_party")
    # Extra-selected native libraries also need their dynamic linker closure.
    import subprocess
    for obj in [*site.rglob("*.so"), *site.rglob("*.so.*")]:
        result = subprocess.run(["ldd", str(obj)], capture_output=True, text=True)
        if result.returncode != 0:
            raise RuntimeError("native dependency inspection failed")
        for line in result.stdout.splitlines():
            for token in line.replace("=>", " ").split():
                if token.startswith("/"):
                    dependency = Path(token)
                    if dependency.is_relative_to(site.resolve()) or dependency.is_relative_to(Path(sys.prefix)):
                        continue
                    destination = root / token.lstrip("/")
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(Path(token).resolve(), destination)
    # The baseline builder copied ELF dependencies under their build-time paths.
    # Wheel RPATHs resolve the originals in site-packages; retain no host home tree.
    for entry in root.iterdir():
        if entry.name not in {"runtime", "lib", "lib64", "usr", "input", "output", "tmp", "proc", "dev", "seccomp.bpf", "bom.json"}:
            if entry.is_dir():
                shutil.rmtree(entry)
            else:
                entry.unlink()
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
