#!/usr/bin/env python3
"""Verify a local artifact before installing or changing its inventory."""
import hashlib
import json
from pathlib import Path, PurePosixPath
import sys


def verify(root: Path) -> None:
    manifest = json.loads((root / "artifact.json").read_text())
    if manifest.get("schemaVersion") != "knowkit-local-artifact:1" or not manifest.get("files"):
        raise ValueError("unknown/empty artifact")
    expected = manifest["files"]
    actual = set()
    for path in root.rglob("*"):
        if path.is_symlink():
            raise ValueError("symlink artifact entry")
        if path.is_file() and path != root / "artifact.json":
            actual.add(path.relative_to(root).as_posix())
    if actual != set(expected):
        raise ValueError("inventory set mismatch")
    for name, digest in expected.items():
        relative = PurePosixPath(name)
        if relative.is_absolute() or ".." in relative.parts or "\\" in name:
            raise ValueError("unsafe inventory path")
        if hashlib.sha256((root / name).read_bytes()).hexdigest() != digest:
            raise ValueError("artifact digest mismatch")


if __name__ == "__main__":
    try:
        if len(sys.argv) != 2:
            raise ValueError("usage: python3 deploy/verify.py ARTIFACT_DIRECTORY")
        verify(Path(sys.argv[1]).absolute())
    except Exception:
        print("artifact verification failed", file=sys.stderr)
        sys.exit(1)
