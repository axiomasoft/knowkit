# Subprocess runtime

Build locally with Python 3.12 (no global installs):

```sh
uv sync --project python/knowkit-worker --frozen --python /usr/bin/python3 --link-mode=copy
python3 - <<'PY'
from pathlib import Path
import shutil
bin_dir = Path('python/knowkit-worker/.venv/bin')
binary = (bin_dir / 'python').resolve()
for name in ('python', 'python3', 'python3.12'):
    target = bin_dir / name
    if target.is_symlink():
        target.unlink()
        shutil.copy2(binary, target)
PY
python/knowkit-worker/.venv/bin/python deploy/worker/build_runtime.py --destination python/knowkit-worker/runtime-root
```

The physical venv binaries keep the QA runner's repository containment check
valid. The build requires host libseccomp and copies only Python, its ELF dependencies, the locked runtime dependency
closure (including transitive extras), four canonical schemas and a seccomp filter.
A dependency extra is not also evaluated as the base extra. PDF and OCR extensions reuse that dependency closure. Wheel libraries already
inside the runtime are not duplicated under build-machine paths. `ldd` runs with `LC_ALL=C` and without host `LD_LIBRARY_PATH` or `LD_PRELOAD`, and each host library path is copied once.
Unresolved native dependencies fail the build unless their exact library name is already
bundled for a wheel that preloads it, such as torchvision/torch. A non-zero `ldd` status is accepted only in that bundled case.
Stdlib symlinks that resolve outside the stdlib, including host `sitecustomize.py`, are omitted; in-tree file symlinks are materialized as regular files. Static archives are not runtime dependencies and are not copied.
`bom.json` binds their
SHA-256 and the lock digest. Its buildInputs bind the base, PDF and OCR build
scripts so test caches reject artifacts from an older sandbox policy. It contains
no machine paths. The base build does not install model assets.
Docling 2.148.0 text/structure extraction and Tesseract eng/rus OCR are implemented;
capability activation requires the separate pinned asset inventories. Extend the
base runtime with [build_pdf_runtime.py](../../python/knowkit-worker/build_pdf_runtime.py)
and [build_ocr_runtime.py](../../python/knowkit-worker/build_ocr_runtime.py), following
[PDF](../../python/knowkit-worker/PDF.md) and [OCR](../../python/knowkit-worker/OCR.md)
build instructions. Base-build descriptors alone do not qualify extraction.
There is no fallback parser, download, HTTP transport or arbitrary callable entry.

The host explicitly wires `Adapter\ProcessWorker\ProcessWorkerSupervisor` with
a trusted runtime root, staging parent, Python launcher and delegated cgroup v2
parent (cpu, memory, pids). It provides a schema-valid `JobEnvelope` and source
bytes. Staging is private, input files become read-only, and argv is always
`knowkit-worker run --envelope /input/job.json` inside bubblewrap. The launcher
joins a per-job cgroup before bubblewrap runs. Network namespaces and seccomp
deny networking; no home, database, metadata credentials or project is mounted.
Before exec, the trusted launcher joins the cgroup by writing `cgroup.procs` without creating a missing file, then closes inherited host descriptors except
stdio and FD 3 carrying the supplied seccomp filter, using `close_range` from FD 4 through the current open-file limit. Negative CPU or file-size limits are refused because they mean unlimited. Bubblewrap is executed with an absolute path and receives only `PATH`, `LANG`, `LC_ALL`, `LC_CTYPE` and `LC_MESSAGES` when those variables are already set; other launcher environment variables are not inherited. `KNOWKIT_SANDBOX_NOFILE` overrides the open-file rlimit when set in the launcher process; the default remains 128. The PHP supervisor starts the launcher with only `LANG` and `PATH`, so production jobs keep 128. Bubblewrap owns FD 3,
reads and closes it, then installs the filter before executing the worker.
See the [bubblewrap 0.9.0 implementation](https://github.com/containers/bubblewrap/blob/v0.9.0/bubblewrap.c#L246-L283). The outer cgroup launcher must not close it before bubblewrap starts.
The denylist also blocks io_uring setup/enter/register and clone3. The clone3
refusal uses ENOSYS so glibc can create native threads through its existing clone
fallback; network syscalls still receive EPERM. An open host
metadata handle is not a grant.

Required limits include total deadline (also bounded by the lease), aggregate
cgroup CPU budget, memory with swap disabled, pids, per-file output RLIMIT,
aggregate output monitoring, bounded stdout/stderr and a size-limited tmpfs.
Quiet parsers do not need heartbeat logs. Timeout/cancel/failure kills the entire
cgroup and joins it before staging cleanup; failure to quiesce is an error.
Missing cgroup, namespace, seccomp or runtime capability refuses execution with
`unavailable/sandbox_unavailable`; a plain subprocess is never a fallback.

PHP treats even exit-zero results as untrusted: schema, identity, typed digest,
IR semantics/coverage and complete file inventory are independently verified
after quiescence. The returned bundle has no publication/acceptance methods.
The caller must import through resource reservations, recheck access/current
source state and use `JobQueue::complete` / the existing conditional fence,
token, epoch and expiry predicates. Successful extraction does not accept a job
or publish a source. Those application orchestration steps are implemented in the PHP ingestion
and publication layers and remain outside the Python parser.

Protocol tests build a separate runtime with an explicitly synthetic fixture
parser; this does not qualify Docling, OCR or an external backend. A delegated
cgroup and working bwrap/seccomp are mandatory for these tests; absence fails.
The two synthetic fixture builders explicitly select only the `jsonschema`
dependency closure, avoiding copies of the native ML stack for protocol tests.
The production builder defaults to the complete `knowkit-worker` closure.

The original base-runtime instructions above are retained from the protocol
bootstrap. Current parser jobs restore temporary environment and logging settings
on success or failure. Unvisited form descendants at the PDF traversal limit
prevent complete coverage; rendering refuses such documents before unchecked
nested image dimensions can allocate a bitmap. Local delivery assembly preflights
source trees, and artifact verification rejects links and special files.
The runtime BOM builder also rejects symbolic links, hard links and special
files, including a linked manifest destination. Fast build, I/O and parser
regressions run with `uv run --project python/knowkit-worker --frozen pytest
python/knowkit-worker/tests/protocol -q`; they need installed dependencies but
no downloaded model assets or full runtime build.
