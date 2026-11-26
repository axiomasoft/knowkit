#!/usr/bin/env python3
"""Verify a local artifact before installing or changing its inventory."""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import sys
import stat


def _digest_within(root: Path, relative: PurePosixPath) -> str:
    flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW
    descriptor = os.open(root, flags | os.O_DIRECTORY)
    try:
        for part in relative.parts:
            child = os.open(part, flags, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise ValueError("non-regular or linked artifact entry")
        digest = hashlib.sha256()
        while chunk := os.read(descriptor, 1024 * 1024):
            digest.update(chunk)
        return digest.hexdigest()
    finally:
        os.close(descriptor)


def verify(root: Path) -> None:
    if not stat.S_ISDIR(root.lstat().st_mode):
        raise ValueError("invalid artifact root")
    info = (root / "artifact.json").lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise ValueError("invalid artifact manifest")
    manifest = json.loads((root / "artifact.json").read_text(encoding="utf-8"))
    if manifest.get("schemaVersion") != "knowkit-local-artifact:1" or not manifest.get("files"):
        raise ValueError("unknown/empty artifact")
    expected = manifest["files"]
    actual = set()
    for path in root.rglob("*"):
        info = path.lstat()
        if stat.S_ISDIR(info.st_mode):
            continue
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise ValueError("non-regular or linked artifact entry")
        if path != root / "artifact.json":
            actual.add(path.relative_to(root).as_posix())
    if actual != set(expected):
        raise ValueError("inventory set mismatch")
    for name, digest in expected.items():
        relative = PurePosixPath(name)
        if relative.is_absolute() or ".." in relative.parts or "\\" in name:
            raise ValueError("unsafe inventory path")
        if _digest_within(root, relative) != digest:
            raise ValueError("artifact digest mismatch")


if __name__ == "__main__":
    try:
        if len(sys.argv) != 2:
            raise ValueError("usage: python3 deploy/verify.py ARTIFACT_DIRECTORY")
        verify(Path(sys.argv[1]).absolute())
    except Exception:
        print("artifact verification failed", file=sys.stderr)
        sys.exit(1)
