#!/usr/bin/env python3
"""Create a local, deterministic core delivery tree. Never publish or install it."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys


def assemble(destination: Path) -> None:
    root = Path(__file__).resolve().parents[1]
    if destination.exists():
        raise ValueError("destination must not exist")
    # Qualify notices before creating an artifact; missing evidence is fatal.
    audit = subprocess.run(
        ["php", str(root / "tools/license-audit.php"), "--catalog", "deploy/licensing.v1.json"],
        cwd=root, capture_output=True, check=True,
    )
    report = json.loads(audit.stdout)
    destination.mkdir(parents=True, mode=0o700)
    selected = ["packages/core", "schemas", "examples/plain-php"]
    for relative in selected:
        source = root / relative
        shutil.copytree(source, destination / relative,
                        ignore=shutil.ignore_patterns("vendor", ".phpunit.cache", "__pycache__"))
    shutil.copytree(root / "licenses", destination / "licenses")
    for relative in ["docs/installation.md", "docs/operations/runbook.md",
                     "docs/operations/backup.md", "docs/operations/purge.md",
                     "docs/operations/restore.md", "docs/operations/observability.md",
                     "docs/operations/capacity.md", "deploy/profiles/delivery.v1.json",
                     "deploy/licensing.v1.json", "examples/acceptance/README.md",
                     "examples/acceptance/fixtures/v1/deeptutor-source-pins.json",
                     "tests/delivery/readiness/evidence/P9.5-reuse.json"]:
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(root / relative, target)
    (destination / "sbom.json").write_text(
        json.dumps(report, indent=2, sort_keys=True, ensure_ascii=False) + "\n")
    notices = ["KnowKit local third-party inventory. Publication is not authorized.\n"]
    seen = set()
    for component in report["components"]:
        notices.append(f'\n{component["id"]}@{component["sourceRevision"]} — {component["codeLicense"]}\n')
        for notice in component["notices"]:
            if notice["sha256"] not in seen:
                notices.append("\n--- " + notice["path"] + " ---\n" +
                               (root / notice["path"]).read_text() + "\n")
                seen.add(notice["sha256"])
    (destination / "THIRD_PARTY_NOTICES.txt").write_text("".join(notices))
    inventory = {}
    for path in sorted(destination.rglob("*")):
        if path.is_symlink():
            raise ValueError("symlinks are not delivery inputs")
        if path.is_file():
            inventory[path.relative_to(destination).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    manifest = {"schemaVersion": "knowkit-local-artifact:1", "publicationAllowed": False,
                "profile": "core-cli", "files": inventory,
                "bridge": {"selection": "NOT_SELECTED", "qualification": "PENDING"},
                "qualification": "Local artifact only; runtime lanes require executed checks."}
    (destination / "artifact.json").write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n")


if __name__ == "__main__":
    try:
        if len(sys.argv) != 2:
            raise ValueError("usage: python3 deploy/assemble.py NEW_DIRECTORY")
        assemble(Path(sys.argv[1]).absolute())
    except Exception:
        print("local assembly failed; inspect qualification and destination", file=sys.stderr)
        sys.exit(1)
