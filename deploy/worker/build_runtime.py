#!/usr/bin/env python3
"""Controlled, offline build of a minimal read-only runtime and its hashed BOM.

Run with the locked worker venv. No model download occurs here or at job time.
The deployment operator supplies a private destination, never a host root mount.
"""

import argparse
import ctypes
import ctypes.util
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import sysconfig


def seccomp(destination: Path) -> None:
    library = ctypes.util.find_library("seccomp")
    if library is None:
        raise RuntimeError("sandbox_unavailable: libseccomp")
    lib = ctypes.CDLL(library, use_errno=True)
    lib.seccomp_init.argtypes = [ctypes.c_uint32]
    lib.seccomp_init.restype = ctypes.c_void_p
    lib.seccomp_syscall_resolve_name.argtypes = [ctypes.c_char_p]
    lib.seccomp_rule_add.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_int, ctypes.c_uint]
    lib.seccomp_export_bpf.argtypes = [ctypes.c_void_p, ctypes.c_int]
    lib.seccomp_release.argtypes = [ctypes.c_void_p]
    context = lib.seccomp_init(0x7FFF0000)  # ALLOW; fixed EPERM deny list below.
    if not context:
        raise RuntimeError("sandbox_unavailable: seccomp init")
    try:
        for name in ("socket", "socketpair", "connect", "bind", "listen", "accept", "accept4",
                     "mount", "umount2", "unshare", "setns", "ptrace", "bpf", "keyctl",
                     "add_key", "request_key", "userfaultfd", "perf_event_open"):
            number = lib.seccomp_syscall_resolve_name(name.encode())
            if number < 0 or lib.seccomp_rule_add(context, 0x00050001, number, 0) != 0:
                raise RuntimeError("sandbox_unavailable: seccomp rule")
        with destination.open("wb") as output:
            if lib.seccomp_export_bpf(context, output.fileno()) != 0:
                raise RuntimeError("sandbox_unavailable: seccomp export")
    finally:
        lib.seccomp_release(context)


def write_bom(root: Path, repo: Path) -> None:
    files = {}
    for path in sorted(root.rglob("*")):
        if path.is_file() and path != root / "bom.json":
            if path.is_symlink():
                raise RuntimeError("runtime symlink")
            files[path.relative_to(root).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    (root / "bom.json").write_text(json.dumps({
        "schemaVersion": "worker-runtime-bom-1.0", "python": sys.version.split()[0],
        "lockDigest": hashlib.sha256((repo / "python/knowkit-worker/uv.lock").read_bytes()).hexdigest(),
        "models": [], "qualifiedParserProfiles": [], "files": files,
    }, sort_keys=True, indent=2) + "\n")


def build(root: Path, repo: Path) -> None:
    if sys.version_info[:2] != (3, 12) or root.exists():
        raise RuntimeError("Python 3.12 and a new destination required")
    root.mkdir(mode=0o700, parents=True)
    for name in ("input", "output", "tmp", "proc", "dev", "runtime/bin", "runtime/lib/python3.12"):
        (root / name).mkdir(parents=True, exist_ok=True)
    binary = root / "runtime/bin/python"
    shutil.copy2(sys.executable, binary)
    stdlib = Path(sysconfig.get_path("stdlib"))
    shutil.copytree(stdlib, root / "runtime/lib/python3.12", dirs_exist_ok=True,
                    ignore=shutil.ignore_patterns("site-packages", "dist-packages", "__pycache__", "test", "tests", "ensurepip"))
    # Runtime dependency closure only. Development tools and ambient site packages are excluded.
    import importlib.metadata
    from packaging.requirements import Requirement
    pending = ["knowkit-worker"]
    visited = set()
    site = root / "runtime/lib/python3.12/site-packages"
    while pending:
        name = pending.pop()
        if name in visited:
            continue
        visited.add(name)
        dist = importlib.metadata.distribution(name)
        for requirement in dist.requires or []:
            parsed = Requirement(requirement)
            if parsed.marker is None or parsed.marker.evaluate({"extra": ""}):
                pending.append(parsed.name)
        if name == "knowkit-worker":
            shutil.copytree(repo / "python/knowkit-worker/src/knowkit_worker", site / "knowkit_worker",
                            ignore=shutil.ignore_patterns("__pycache__"))
            continue
        for item in dist.files or []:
            if ".." in item.parts or "__pycache__" in item.parts:
                continue
            source = Path(dist.locate_file(item))
            if source.is_file():
                target = site / item
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, target)
    modules = site / "knowkit_worker/schemas"
    modules.mkdir(exist_ok=True)
    for name in ("common", "job-envelope", "worker-result", "document-ir"):
        shutil.copy2(repo / f"schemas/{name}.schema.json", modules / f"{name}.schema.json")
    # Only ELF dependency files are copied; no /usr, home, project or credential bind.
    objects = [binary, *root.rglob("*.so"), *root.rglob("*.so.*")]
    for obj in objects:
        result = subprocess.run(["ldd", str(obj)], capture_output=True, text=True, check=False)
        if result.returncode != 0:
            raise RuntimeError("runtime dependency inspection failed")
        for line in result.stdout.splitlines():
            tokens = line.replace("=>", " ").split()
            for token in tokens:
                if token.startswith("/"):
                    target = root / token.lstrip("/")
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(Path(token).resolve(), target)
    launcher = root / "runtime/bin/knowkit-worker"
    prelude = "#!/runtime/bin/python\nimport sys\nsys.path.insert(0, '/runtime/lib/python3.12/site-packages')\n"
    launcher.write_text(prelude + "from knowkit_worker.cli import main\nraise SystemExit(main())\n")
    launcher.chmod(0o755)
    probe = root / "runtime/bin/knowkit-worker-probe"
    probe.write_text(prelude + "from knowkit_worker.sandbox_probe import main\nraise SystemExit(main())\n")
    probe.chmod(0o755)
    seccomp(root / "seccomp.bpf")
    write_bom(root, repo)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--destination", required=True, type=Path)
    parser.add_argument("--repository", default=Path(__file__).resolve().parents[2], type=Path)
    args = parser.parse_args()
    build(args.destination, args.repository)
