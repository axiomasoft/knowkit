#!/usr/bin/env python3
"""Controlled, offline build of a minimal read-only runtime and its hashed BOM.

Run with the locked worker venv. No model download occurs here or at job time.
The deployment operator supplies a private destination, never a host root mount.
"""

import argparse
import ctypes
import ctypes.util
import hashlib
import errno
from importlib import metadata
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import sysconfig

from packaging.requirements import Requirement
from packaging.utils import canonicalize_name


def distributions(requirements: tuple[str, ...] = ("knowkit-worker",)) -> list:
    """Resolve runtime requirements, including extras requested by dependencies."""
    pending = [(name, frozenset()) for name in requirements]
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
                     "add_key", "request_key", "userfaultfd", "perf_event_open",
                     "io_uring_setup", "io_uring_enter", "io_uring_register", "clone3"):
            number = lib.seccomp_syscall_resolve_name(name.encode())
            # glibc retries pthread creation with clone only when clone3 is unavailable.
            error = errno.ENOSYS if name == "clone3" else errno.EPERM
            if number < 0 or lib.seccomp_rule_add(context, 0x00050000 | error, number, 0) != 0:
                raise RuntimeError("sandbox_unavailable: seccomp rule")
        with destination.open("wb") as output:
            if lib.seccomp_export_bpf(context, output.fileno()) != 0:
                raise RuntimeError("sandbox_unavailable: seccomp export")
    finally:
        lib.seccomp_release(context)


def write_bom(root: Path, repo: Path) -> None:
    if not stat.S_ISDIR(root.lstat().st_mode):
        raise RuntimeError("invalid runtime root")
    files = {}
    for path in sorted(root.rglob("*")):
        info = path.lstat()
        if stat.S_ISDIR(info.st_mode):
            continue
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise RuntimeError("non-regular or linked runtime entry")
        if path != root / "bom.json":
            files[path.relative_to(root).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    (root / "bom.json").write_text(json.dumps({
        "schemaVersion": "worker-runtime-bom-1.0", "python": sys.version.split()[0],
        "lockDigest": hashlib.sha256((repo / "python/knowkit-worker/uv.lock").read_bytes()).hexdigest(),
        "buildInputs": {name: hashlib.sha256((repo / name).read_bytes()).hexdigest() for name in (
            "deploy/worker/build_runtime.py", "python/knowkit-worker/build_pdf_runtime.py",
            "python/knowkit-worker/build_ocr_runtime.py")},
        "models": [], "qualifiedParserProfiles": [], "files": files,
    }, sort_keys=True, indent=2) + "\n")


def copy_native_dependencies(root: Path) -> None:
    """Copy external ELF libraries once; wheel RPATH libraries are already present."""
    resolved_root = root.resolve()
    objects = [root / "runtime/bin/python", *root.rglob("*.so"), *root.rglob("*.so.*")]
    bundled = {obj.name for obj in objects}
    for obj in objects:
        result = subprocess.run(["ldd", str(obj)], capture_output=True, text=True, check=False,
                                env={**os.environ, "LC_ALL": "C"})
        # Some wheels preload sibling libraries (torchvision imports torch).
        # ldd alone cannot resolve these, but their exact soname must be bundled.
        missing = {line.split()[0] for line in result.stdout.splitlines() if "=> not found" in line}
        if result.returncode != 0 or missing - bundled:
            raise RuntimeError("runtime dependency inspection failed")
        for line in result.stdout.splitlines():
            for token in line.replace("=>", " ").split():
                if not token.startswith("/"):
                    continue
                source = Path(token).resolve()
                if source.is_relative_to(resolved_root):
                    continue
                target = root / token.lstrip("/")
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, target)


def build(root: Path, repo: Path, *, requirements: tuple[str, ...] = ("knowkit-worker",)) -> None:
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
    site = root / "runtime/lib/python3.12/site-packages"
    shutil.copytree(repo / "python/knowkit-worker/src/knowkit_worker", site / "knowkit_worker",
                    ignore=shutil.ignore_patterns("__pycache__"))
    for dist in distributions(requirements):
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
    copy_native_dependencies(root)
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
