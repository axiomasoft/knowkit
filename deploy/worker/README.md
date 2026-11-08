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
closure, four canonical schemas and a seccomp filter. `bom.json` binds their
SHA-256 and the lock digest; it contains no machine paths. Model assets are empty.
Docling 2.148.0 is a descriptor only: PDF/table/image/OCR remain unavailable until
their separate qualification items supply a pinned implementation and asset BOM.
There is no fallback parser, download, HTTP transport or arbitrary callable entry.

The host explicitly wires `Adapter\ProcessWorker\ProcessWorkerSupervisor` with
a trusted runtime root, staging parent, Python launcher and delegated cgroup v2
parent (cpu, memory, pids). It provides a schema-valid `JobEnvelope` and source
bytes. Staging is private, input files become read-only, and argv is always
`knowkit-worker run --envelope /input/job.json` inside bubblewrap. The launcher
joins a per-job cgroup before bubblewrap runs. Network namespaces and seccomp
deny networking; no home, database, metadata credentials or project is mounted.
Before exec, the trusted launcher closes inherited host descriptors except
stdio and the supplied seccomp filter; an open host metadata handle is not a grant.

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
or publish a source. Those application orchestration steps belong to P6.2.

Protocol tests build a separate runtime with an explicitly synthetic fixture
parser; this does not qualify Docling, OCR or an external backend. A delegated
cgroup and working bwrap/seccomp are mandatory for these tests; absence fails.
