#!/usr/bin/env python3
"""Trusted supervisor launcher: join the cgroup BEFORE exec of bubblewrap.

No user code runs outside the sandbox. No shell, environment inheritance or
parser-selected executable. The parent supplies argv and private cgroup path.
"""

import os
import errno
from pathlib import Path
import resource
import sys


def main() -> int:
    try:
        group, cpu, file_bytes, *argv = sys.argv[1:]
        os.setsid()
        (Path(group) / "cgroup.procs").write_text(str(os.getpid()))
        # proc_open may inherit host metadata/blob handles. Only stdio and the
        # explicitly supplied seccomp FD 3 may survive into bubblewrap.
        for descriptor in os.listdir('/proc/self/fd'):
            fd = int(descriptor)
            if fd > 3:
                try:
                    os.close(fd)
                except OSError as exc:
                    # The descriptor used by listdir itself is already closed.
                    if exc.errno != errno.EBADF:
                        raise
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        resource.setrlimit(resource.RLIMIT_CPU, (int(cpu), int(cpu)))
        resource.setrlimit(resource.RLIMIT_FSIZE, (int(file_bytes), int(file_bytes)))
        resource.setrlimit(resource.RLIMIT_NOFILE, (128, 128))
        os.execv(argv[0], argv)
    except Exception:
        os.write(2, b'{"code":"sandbox_unavailable"}\n')
        return 4


if __name__ == "__main__":
    raise SystemExit(main())
