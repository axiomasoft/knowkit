#!/usr/bin/env python3
"""Trusted supervisor launcher: join the cgroup BEFORE exec of bubblewrap.

No user code runs outside the sandbox. No shell, environment inheritance or
parser-selected executable. The parent supplies argv and private cgroup path.
"""

import os
import resource
import sys

# Keep the historical default when the launcher environment does not set this.
_DEFAULT_NOFILE = 128


def _limit(value: str) -> int:
    try:
        parsed = int(value)
    except ValueError:
        raise ValueError("sandbox limit") from None
    # Negative rlimits are RLIM_INFINITY and would remove the sandbox cap.
    if parsed < 0:
        raise ValueError("sandbox limit")
    return parsed


def _nofile_limit() -> int:
    raw = os.environ.get("KNOWKIT_SANDBOX_NOFILE")
    if raw is None or raw == "":
        return _DEFAULT_NOFILE
    limit = _limit(raw)
    if limit < 4 or limit > 1048576:
        raise ValueError("sandbox limit")
    return limit


def _launcher_environment() -> dict[str, str]:
    # Bubblewrap clears the worker environment itself. Do not hand it host
    # loader or credential variables from the launcher process.
    kept: dict[str, str] = {}
    for key in ("PATH", "LANG", "LC_ALL", "LC_CTYPE", "LC_MESSAGES"):
        value = os.environ.get(key)
        if value is not None and "\0" not in value:
            kept[key] = value
    return kept


def main() -> int:
    try:
        group, cpu, file_bytes, *argv = sys.argv[1:]
        if not argv or not argv[0].startswith("/") or "\0" in argv[0]:
            raise ValueError("sandbox command required")
        if not group.startswith("/") or "\0" in group:
            raise ValueError("sandbox cgroup required")
        cpu_limit = _limit(cpu)
        file_limit = _limit(file_bytes)
        os.setsid()
        # Do not create a regular file when the delegated cgroup is missing.
        descriptor = os.open(os.path.join(group, "cgroup.procs"), os.O_WRONLY | os.O_CLOEXEC)
        try:
            os.write(descriptor, str(os.getpid()).encode("ascii"))
        finally:
            os.close(descriptor)
        # proc_open may inherit host metadata/blob handles. Only stdio and the
        # explicitly supplied seccomp FD 3 may survive into bubblewrap.
        soft, hard = resource.getrlimit(resource.RLIMIT_NOFILE)
        ceiling = soft if soft >= 0 else hard if hard >= 0 else 1048576
        os.closerange(4, ceiling)
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        resource.setrlimit(resource.RLIMIT_CPU, (cpu_limit, cpu_limit))
        resource.setrlimit(resource.RLIMIT_FSIZE, (file_limit, file_limit))
        nofile = _nofile_limit()
        resource.setrlimit(resource.RLIMIT_NOFILE, (nofile, nofile))
        # FD 3 belongs to bubblewrap's --seccomp option. Bubblewrap reads and
        # closes the FD, then installs the filter before executing the worker.
        # Closing it here would prevent sandbox setup.
        os.execve(argv[0], argv, _launcher_environment())
    except Exception:
        os.write(2, b'{"code":"sandbox_unavailable"}\n')
        return 4


if __name__ == "__main__":
    raise SystemExit(main())
