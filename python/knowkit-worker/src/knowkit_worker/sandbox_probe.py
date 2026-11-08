"""Executable preflight, run inside exactly the sandbox used by a job."""

import errno
import os
from pathlib import Path
import socket


def main() -> int:
    try:
        if os.getuid() == 0 or not Path("/bom.json").is_file():
            return 4
        try:
            socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        except OSError as exc:
            if exc.errno != errno.EPERM:
                return 4
        else:
            return 4
        try:
            Path("/input/probe-write").write_bytes(b"x")
        except OSError as exc:
            if exc.errno not in (errno.EROFS, errno.EACCES):
                return 4
        else:
            return 4
        if set(os.environ) - {"PATH", "LANG", "LC_CTYPE", "PYTHONDONTWRITEBYTECODE", "PWD"}:
            return 4
        return 0
    except Exception:
        return 4


if __name__ == "__main__":
    raise SystemExit(main())
