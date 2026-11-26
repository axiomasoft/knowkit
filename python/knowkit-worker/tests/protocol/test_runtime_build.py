"""Offline build regressions without copying the interpreter or model assets."""

import importlib.util
import json
import os
from pathlib import Path
from types import SimpleNamespace

import pytest

from knowkit_worker.protocol import sha256


ROOT = Path(__file__).resolve().parents[4]
SPEC = importlib.util.spec_from_file_location("runtime_builder", ROOT / "deploy/worker/build_runtime.py")
builder = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(builder)


def test_runtime_dependency_closure_includes_transitive_extras_without_dev_tools(monkeypatch):
    requirements = {
        "knowkit-worker": ["parser", "adapter", 'pytest; extra == "dev"'],
        "adapter": ["parser[layout]", "helper_lib"],
        "parser": ["helper-lib", 'native; extra == "layout"',
                   'ocr; extra == "ocr"', 'windows-only; sys_platform == "never"'],
        "native": ["parser[layout]"],
        "helper-lib": [],
    }
    distributions = {name: SimpleNamespace(name=name, requires=deps) for name, deps in requirements.items()}
    monkeypatch.setattr(builder.metadata, "distribution", distributions.__getitem__)
    assert [dist.name for dist in builder.distributions()] == ["adapter", "helper-lib", "native", "parser"]
    # A synthetic protocol fixture can opt into a small closure explicitly.
    assert [dist.name for dist in builder.distributions(("helper-lib",))] == ["helper-lib"]


@pytest.mark.parametrize("kind", ["symlink-file", "symlink-directory", "dangling", "fifo", "hardlink", "manifest"])
def test_bom_rejects_uninventoryable_entries_before_reading_or_writing(tmp_path, kind):
    runtime = tmp_path / "runtime"
    runtime.mkdir()
    source = tmp_path / "source"
    source.write_bytes(b"untouched")
    entry = runtime / ("bom.json" if kind == "manifest" else "entry")
    if kind == "symlink-directory":
        entry.symlink_to(tmp_path, target_is_directory=True)
    elif kind in ("symlink-file", "manifest"):
        entry.symlink_to(source)
    elif kind == "dangling":
        entry.symlink_to(tmp_path / "missing")
    elif kind == "fifo":
        os.mkfifo(entry)
    else:
        os.link(source, entry)
    with pytest.raises(RuntimeError, match="non-regular or linked runtime entry"):
        builder.write_bom(runtime, ROOT)
    assert source.read_bytes() == b"untouched"


def test_bom_rejects_linked_root_and_preserves_exact_inventory(tmp_path):
    runtime = tmp_path / "runtime"
    runtime.mkdir()
    (runtime / "data").write_bytes(b"data")
    linked = tmp_path / "linked"
    linked.symlink_to(runtime, target_is_directory=True)
    with pytest.raises(RuntimeError, match="invalid runtime root"):
        builder.write_bom(linked, ROOT)
    builder.write_bom(runtime, ROOT)
    manifest = json.loads((runtime / "bom.json").read_text())
    assert manifest["files"] == {"data": sha256(b"data")}
    assert manifest["buildInputs"]["deploy/worker/build_runtime.py"] == sha256(
        (ROOT / "deploy/worker/build_runtime.py").read_bytes())
    builder.write_bom(runtime, ROOT)
    assert json.loads((runtime / "bom.json").read_text()) == manifest


def test_native_dependencies_are_not_duplicated_under_build_machine_paths(tmp_path, monkeypatch):
    runtime = tmp_path / "runtime"
    runtime.mkdir()
    internal = runtime / "library.so"
    internal.write_bytes(b"native")
    monkeypatch.setattr(builder.subprocess, "run", lambda *args, **kwargs: SimpleNamespace(
        returncode=0, stdout=f"library.so => {internal} (0x123)\n"))
    builder.copy_native_dependencies(runtime)
    assert list(runtime.rglob("*")) == [internal]


def test_native_dependency_inspection_rejects_unresolved_libraries(tmp_path, monkeypatch):
    monkeypatch.setattr(builder.subprocess, "run", lambda *args, **kwargs: SimpleNamespace(
        returncode=0, stdout="librequired.so => not found\n"))
    with pytest.raises(RuntimeError, match="runtime dependency inspection failed"):
        builder.copy_native_dependencies(tmp_path)


def test_native_dependency_inspection_allows_preloaded_bundled_wheel_libraries(tmp_path, monkeypatch):
    (tmp_path / "libtorch.so").write_bytes(b"bundled wheel library")
    monkeypatch.setattr(builder.subprocess, "run", lambda *args, **kwargs: SimpleNamespace(
        returncode=0, stdout="libtorch.so => not found\n"))
    builder.copy_native_dependencies(tmp_path)


def test_fresh_seccomp_filter_denies_clone3_and_network_without_breaking_threads(tmp_path):
    import subprocess
    import sys

    policy = tmp_path / 'seccomp.bpf'
    builder.seccomp(policy)
    # Load the actual generated BPF only in a disposable child; no namespace or
    # privileged launcher is needed to test libc's clone3 -> clone fallback.
    code = r'''
import ctypes, ctypes.util, errno, socket, sys, threading
from pathlib import Path
class Filter(ctypes.Structure):
    _fields_ = [('code', ctypes.c_ushort), ('jt', ctypes.c_ubyte),
                ('jf', ctypes.c_ubyte), ('k', ctypes.c_uint)]
class Program(ctypes.Structure):
    _fields_ = [('length', ctypes.c_ushort), ('filters', ctypes.POINTER(Filter))]
raw = Path(sys.argv[1]).read_bytes()
assert len(raw) % ctypes.sizeof(Filter) == 0
filters = (Filter * (len(raw) // ctypes.sizeof(Filter))).from_buffer_copy(raw)
program = Program(len(filters), filters)
seccomp = ctypes.CDLL(ctypes.util.find_library('seccomp'))
seccomp.seccomp_syscall_resolve_name.argtypes = [ctypes.c_char_p]
clone3 = seccomp.seccomp_syscall_resolve_name(b'clone3')
assert clone3 >= 0
libc = ctypes.CDLL(None, use_errno=True)
libc.prctl.argtypes = [ctypes.c_int, ctypes.c_ulong, ctypes.c_void_p,
                      ctypes.c_ulong, ctypes.c_ulong]
assert libc.prctl(38, 1, None, 0, 0) == 0  # PR_SET_NO_NEW_PRIVS
assert libc.prctl(22, 2, ctypes.byref(program), 0, 0) == 0  # PR_SET_SECCOMP/FILTER
assert libc.syscall(clone3, ctypes.c_void_p(), ctypes.c_size_t(0)) == -1
assert ctypes.get_errno() == errno.ENOSYS
seen = []
thread = threading.Thread(target=lambda: seen.append(True))
thread.start()
thread.join(2)
assert seen == [True]
try:
    socket.socket()
except OSError as error:
    assert error.errno == errno.EPERM
else:
    raise AssertionError('network was permitted')
print('SECCOMP_THREADS_OK_NETWORK_DENIED')
'''
    result = subprocess.run([sys.executable, '-c', code, str(policy)],
                            capture_output=True, text=True, timeout=10)
    assert result.returncode == 0, result.stderr
    assert result.stdout == 'SECCOMP_THREADS_OK_NETWORK_DENIED\n'
