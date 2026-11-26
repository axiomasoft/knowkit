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

from packaging.markers import Marker
from packaging.requirements import Requirement
from packaging.utils import canonicalize_name


def _extra_selected(marker: Marker | None, extras: frozenset[str]) -> bool:
    """A requested extra is not also evaluated as the base extra."""
    if marker is None:
        return True
    probes = extras or frozenset({""})
    return any(marker.evaluate({"extra": extra}) for extra in probes)


def distributions(requirements: tuple[str, ...] = ("knowkit-worker",)) -> list[metadata.Distribution]:
    """Resolve runtime requirements, including extras requested by dependencies."""
    pending = [(canonicalize_name(name), frozenset()) for name in requirements]
    visited: set[tuple[str, frozenset[str]]] = set()
    names: set[str] = set()
    while pending:
        name, extras = pending.pop()
        if (name, extras) in visited:
            continue
        visited.add((name, extras))
        names.add(name)
        dist = metadata.distribution(name)
        for text in dist.requires or []:
            req = Requirement(text)
            if _extra_selected(req.marker, extras):
                pending.append((canonicalize_name(req.name), frozenset(req.extras)))
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


def _digest(path: Path) -> str:
    descriptor = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
            raise RuntimeError("non-regular or linked runtime entry")
        digest = hashlib.sha256()
        while chunk := os.read(descriptor, 1024 * 1024):
            digest.update(chunk)
        return digest.hexdigest()
    finally:
        os.close(descriptor)


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
            files[path.relative_to(root).as_posix()] = _digest(path)
    (root / "bom.json").write_text(json.dumps({
        "schemaVersion": "worker-runtime-bom-1.0", "python": sys.version.split()[0],
        "lockDigest": hashlib.sha256((repo / "python/knowkit-worker/uv.lock").read_bytes()).hexdigest(),
        "buildInputs": {name: hashlib.sha256((repo / name).read_bytes()).hexdigest() for name in (
            "deploy/worker/build_runtime.py", "python/knowkit-worker/build_pdf_runtime.py",
            "python/knowkit-worker/build_ocr_runtime.py")},
        "models": [], "qualifiedParserProfiles": [], "files": files,
    }, sort_keys=True, indent=2) + "\n")


def _shared_object(path: Path) -> bool:
    name = path.name
    return name.endswith(".so") or ".so." in name


def copy_native_dependencies(root: Path) -> None:
    """Copy external ELF libraries once; wheel RPATH libraries are already present."""
    resolved_root = root.resolve()
    objects: list[Path] = [root / "runtime/bin/python"]
    seen: set[Path] = set(objects)
    if root.exists():
        for path in root.rglob("*"):
            if path in seen or not _shared_object(path):
                continue
            try:
                info = path.lstat()
            except OSError:
                continue
            if not stat.S_ISREG(info.st_mode):
                continue
            seen.add(path)
            objects.append(path)
    bundled = {obj.name for obj in objects}
    copied: set[Path] = set()
    inspected: set[tuple[int, int]] = set()
    # Host loader overrides must not decide which libraries enter the sandbox.
    ldd_env = {"LC_ALL": "C", "PATH": os.environ.get("PATH", "/usr/bin:/bin")}
    for obj in objects:
        try:
            info = obj.lstat()
        except OSError:
            info = None
        if info is not None:
            if not stat.S_ISREG(info.st_mode):
                continue
            identity = (info.st_dev, info.st_ino)
            if identity in inspected:
                continue
            inspected.add(identity)
        result = subprocess.run(["ldd", str(obj)], capture_output=True, text=True, check=False, env=ldd_env)
        report = f"{result.stdout or ''}\n{getattr(result, 'stderr', '') or ''}"
        # Some wheels preload sibling libraries (torchvision imports torch).
        # ldd alone cannot resolve these, but their exact soname must be bundled.
        # A non-zero status is success only when every missing soname is bundled.
        missing = {line.split()[0] for line in report.splitlines() if "=> not found" in line}
        if missing - bundled or (result.returncode != 0 and not missing):
            raise RuntimeError("runtime dependency inspection failed")
        for line in (result.stdout or "").splitlines():
            if "=> not found" in line:
                continue
            for token in line.replace("=>", " ").split():
                if not token.startswith("/"):
                    continue
                source = Path(token).resolve()
                if source.is_relative_to(resolved_root):
                    continue
                target = root / token.lstrip("/")
                if target in copied:
                    continue
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, target)
                copied.add(target)


_STDLIB_SKIP = {"site-packages", "dist-packages", "__pycache__", "test", "tests", "ensurepip"}


def ignored_stdlib_entries(stdlib_root: Path, directory: str, names: list[str]) -> set[str]:
    """Drop host policy files, static archives, and symlink cycles."""
    ignored: set[str] = set()
    base = Path(directory)
    for name in names:
        if name in _STDLIB_SKIP or name.endswith(".a"):
            ignored.add(name)
            continue
        path = base / name
        if not path.is_symlink():
            continue
        try:
            resolved = path.resolve(strict=True)
            inside = resolved.is_relative_to(stdlib_root) and not resolved.is_dir()
        except (OSError, ValueError):
            inside = False
        if not inside:
            ignored.add(name)
    return ignored


def build(root: Path, repo: Path, *, requirements: tuple[str, ...] = ("knowkit-worker",)) -> None:
    if sys.version_info[:2] != (3, 12) or root.exists():
        raise RuntimeError("Python 3.12 and a new destination required")
    root.mkdir(mode=0o700, parents=True)
    for name in ("input", "output", "tmp", "proc", "dev", "runtime/bin", "runtime/lib/python3.12"):
        (root / name).mkdir(parents=True, exist_ok=True)
    binary = root / "runtime/bin/python"
    # Follow a venv symlink so the runtime interpreter is a regular file.
    shutil.copy2(sys.executable, binary)
    stdlib = Path(sysconfig.get_path("stdlib"))
    stdlib_root = stdlib.resolve()
    shutil.copytree(stdlib, root / "runtime/lib/python3.12", dirs_exist_ok=True,
                    ignore=lambda directory, names: ignored_stdlib_entries(stdlib_root, directory, names))
    # Runtime dependency closure only. Development tools and ambient site packages are excluded.
    site = root / "runtime/lib/python3.12/site-packages"
    shutil.copytree(repo / "python/knowkit-worker/src/knowkit_worker", site / "knowkit_worker",
                    ignore=shutil.ignore_patterns("__pycache__"))
    for dist in distributions(requirements):
        for item in dist.files or []:
            if "__pycache__" in item.parts:
                continue
            if ".." in item.parts or item.is_absolute():
                raise RuntimeError("unsafe distribution path")
            source = Path(dist.locate_file(item))
            try:
                info = source.lstat()
            except OSError:
                continue
            if source.is_symlink() or not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode)):
                raise RuntimeError("non-regular or linked runtime entry")
            if not stat.S_ISREG(info.st_mode):
                continue
            target = site.joinpath(*item.parts)
            try:
                target.relative_to(site)
            except ValueError:
                raise RuntimeError("unsafe distribution path") from None
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target, follow_symlinks=False)
    modules = site / "knowkit_worker/schemas"
    modules.mkdir(exist_ok=True)
    for name in ("common", "job-envelope", "worker-result", "document-ir"):
        schema = repo / f"schemas/{name}.schema.json"
        if schema.is_symlink() or not stat.S_ISREG(schema.lstat().st_mode):
            raise RuntimeError("non-regular or linked runtime entry")
        shutil.copy2(schema, modules / f"{name}.schema.json", follow_symlinks=False)
    # Only ELF dependency files are copied; no /usr, home, project or credential bind.
    copy_native_dependencies(root)
    launcher = root / "runtime/bin/knowkit-worker"
    prelude = "#!/runtime/bin/python\nimport sys\nsys.path.insert(0, '/runtime/lib/python3.12/site-packages')\n"
    launcher.write_text(prelude + "from knowkit_worker.cli import main\nraise SystemExit(main())\n",
                        encoding="utf-8")
    launcher.chmod(0o755)
    probe = root / "runtime/bin/knowkit-worker-probe"
    probe.write_text(prelude + "from knowkit_worker.sandbox_probe import main\nraise SystemExit(main())\n",
                     encoding="utf-8")
    probe.chmod(0o755)
    seccomp(root / "seccomp.bpf")
    write_bom(root, repo)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--destination", required=True, type=Path)
    parser.add_argument("--repository", default=Path(__file__).resolve().parents[2], type=Path)
    args = parser.parse_args()
    build(args.destination, args.repository)
