"""Descriptor-relative, non-following I/O inside supervisor-owned mounts."""

import os
import stat
from pathlib import Path

from .protocol import Refusal, relative_path


def read_regular(root: Path, relative: str, limit: int, readonly: bool = False) -> bytes:
    if not relative_path(relative):
        raise Refusal("path_escape", "denied")
    directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        parts = relative.split("/")
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
            os.close(directory)
            directory = child
        fd = os.open(parts[-1], os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=directory)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > limit:
                raise Refusal("unsafe_file", "denied")
            if readonly and info.st_mode & 0o222:
                raise Refusal("input_not_readonly", "denied")
            with os.fdopen(fd, "rb", closefd=False) as stream:
                data = stream.read(limit + 1)
            if len(data) > limit:
                raise Refusal("document_too_large")
            return data
        finally:
            os.close(fd)
    except OSError:
        raise Refusal("unsafe_file", "denied") from None
    finally:
        os.close(directory)


def write_new(root: Path, name: str, data: bytes) -> None:
    if not relative_path(name):
        raise Refusal("path_escape", "denied")
    directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        parts = name.split("/")
        for part in parts[:-1]:
            try:
                os.mkdir(part, mode=0o700, dir_fd=directory)
            except FileExistsError:
                pass
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
            os.close(directory)
            directory = child
        fd = os.open(parts[-1], os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=directory)
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.fsync(directory)
    finally:
        os.close(directory)
