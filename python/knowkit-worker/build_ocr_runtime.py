#!/usr/bin/env python3
"""Extend the qualified PDF sandbox image with a verified offline OCR inventory."""

import argparse
import importlib.util
import json
from pathlib import Path
import shutil

from knowkit_worker.ocr.qualification import record, verify_native


def build(destination: Path, models: Path, native: Path) -> None:
    verify_native(native)
    worker = Path(__file__).parent
    spec = importlib.util.spec_from_file_location("pdf_runtime_build", worker / "build_pdf_runtime.py")
    pdf = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(pdf)
    pdf.build(destination, models)
    site = destination / "runtime/lib/python3.12/site-packages"
    shutil.copytree(native, site / "knowkit_worker/ocr/native")
    sbom_path = destination / "sbom.json"
    sbom = json.loads(sbom_path.read_text())
    sbom["schemaVersion"] = "knowkit-ocr-sbom-1.0"
    sbom["ocr"] = record()
    sbom_path.write_text(json.dumps(sbom, indent=2) + "\n")
    base_spec = importlib.util.spec_from_file_location(
        "worker_runtime_build", worker.parents[1] / "deploy/worker/build_runtime.py")
    base = importlib.util.module_from_spec(base_spec)
    base_spec.loader.exec_module(base)
    base.write_bom(destination, worker.parents[1])
    bom_path = destination / "bom.json"
    bom = json.loads(bom_path.read_text())
    bom.update(models=pdf.record()["modelAssets"] + record()["modelAssets"],
               qualifiedParserProfiles=["docling-pdf-text-v1", *record()["enabledProfiles"]])
    bom_path.write_text(json.dumps(bom, indent=2) + "\n")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("--destination", required=True, type=Path)
    parser.add_argument("--model-root", required=True, type=Path)
    parser.add_argument("--native-root", required=True, type=Path)
    args = parser.parse_args()
    build(args.destination, args.model_root, args.native_root)
