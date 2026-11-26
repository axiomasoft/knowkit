#!/usr/bin/env python3
"""Create a local, deterministic core delivery tree. Never publish or install it.

Notice and manifest text is UTF-8. ``KNOWKIT_PHP`` selects the license-audit
interpreter and defaults to ``php``.
"""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import stat
import subprocess
import sys


def preflight(source: Path) -> None:
    info = source.lstat()
    if stat.S_ISDIR(info.st_mode):
        for child in source.iterdir():
            preflight(child)
    elif not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise ValueError("delivery inputs must be regular files or directories")


def _digest(path: Path) -> str:
    descriptor = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise ValueError("non-regular or linked artifact entry")
        digest = hashlib.sha256()
        while chunk := os.read(descriptor, 1024 * 1024):
            digest.update(chunk)
        return digest.hexdigest()
    finally:
        os.close(descriptor)


def _notice_text(root: Path, relative: str) -> str:
    path = PurePosixPath(relative)
    if not relative or path.is_absolute() or ".." in path.parts or "\\" in relative:
        raise ValueError("unsafe notice path")
    file = root.joinpath(*path.parts)
    info = file.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise ValueError("unsafe notice path")
    return file.read_text(encoding="utf-8")


def assemble(destination: Path) -> None:
    root = Path(__file__).resolve().parents[1]
    if destination.exists():
        raise ValueError("destination must not exist")
    selected = ["packages/core", "schemas", "examples/plain-php", "licenses"]
    files = ["docs/installation.md", "docs/operations/runbook.md",
             "docs/operations/backup.md", "docs/operations/purge.md",
             "docs/operations/restore.md", "docs/operations/observability.md",
             "docs/operations/capacity.md", "deploy/profiles/delivery.v1.json",
             "deploy/licensing.v1.json", "examples/acceptance/README.md",
             "examples/acceptance/fixtures/v1/deeptutor-source-pins.json",
             "tests/delivery/readiness/evidence/P9.5-reuse.json"]
    # Inspect every input before any copy can follow a link or open a FIFO.
    for relative in [*selected, *files]:
        preflight(root / relative)
    # Qualify notices before creating an artifact; missing evidence is fatal.
    audit = subprocess.run(
        [os.environ.get("KNOWKIT_PHP") or "php", str(root / "tools/license-audit.php"),
         "--catalog", "deploy/licensing.v1.json"],
        cwd=root, capture_output=True, check=True,
    )
    report = json.loads(audit.stdout)
    destination.mkdir(parents=True, mode=0o700)
    for relative in selected:
        shutil.copytree(root / relative, destination / relative, symlinks=True,
                        ignore=shutil.ignore_patterns("vendor", ".phpunit.cache", "__pycache__"))
    for relative in files:
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(root / relative, target, follow_symlinks=False)
    (destination / "sbom.json").write_text(
        json.dumps(report, indent=2, sort_keys=True, ensure_ascii=False) + "\n", encoding="utf-8")
    notices = ["KnowKit local third-party inventory. Publication is not authorized.\n"]
    seen = set()
    for component in report["components"]:
        notices.append(f'\n{component["id"]}@{component["sourceRevision"]} — {component["codeLicense"]}\n')
        for notice in component["notices"]:
            if notice["sha256"] not in seen:
                notices.append("\n--- " + notice["path"] + " ---\n" +
                               _notice_text(root, notice["path"]) + "\n")
                seen.add(notice["sha256"])
    (destination / "THIRD_PARTY_NOTICES.txt").write_text("".join(notices), encoding="utf-8")
    inventory = {}
    for path in sorted(destination.rglob("*")):
        info = path.lstat()
        if stat.S_ISDIR(info.st_mode):
            continue
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise ValueError("non-regular or linked artifact entry")
        inventory[path.relative_to(destination).as_posix()] = _digest(path)
    manifest = {"schemaVersion": "knowkit-local-artifact:1", "publicationAllowed": False,
                "profile": "core-cli", "files": inventory,
                "bridge": {"selection": "NOT_SELECTED", "qualification": "PENDING"},
                "qualification": "Local artifact only; runtime lanes require executed checks."}
    (destination / "artifact.json").write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")


if __name__ == "__main__":
    try:
        if len(sys.argv) != 2:
            raise ValueError("usage: python3 deploy/assemble.py NEW_DIRECTORY")
        assemble(Path(sys.argv[1]).absolute())
    except Exception:
        print("local assembly failed; inspect qualification and destination", file=sys.stderr)
        sys.exit(1)
