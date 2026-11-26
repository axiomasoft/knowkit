"""Synthetic adversarial parser, injected into a disposable TEST runtime only.

No PDF/backend capability is established by these protocol fixtures.
"""

import errno
import os
from pathlib import Path
import socket
import sys
import time
import signal

from knowkit_worker.cli import main
from knowkit_worker.profiles import Asset, ParsedDocument, Profile
from knowkit_worker.protocol import sha256


def parse(source, envelope):
    mode = source.decode("utf-8")
    if mode == "timeout":
        time.sleep(60)
    if mode == "cancel":
        Path('/output/blocked').write_bytes(b'1')
        time.sleep(60)
    if mode == "cpu":
        while True:
            pass
    if mode == "oom":
        allocations = []
        while True:
            allocations.append(bytearray(16 << 20))
    if mode == "quiet":
        # Longer than the supervisor poll so a silent worker is joined, not treated as already finished.
        time.sleep(0.05)
    if mode == "flood":
        while True:
            os.write(1, b"DOCUMENT_SECRET_CANARY" * 4096)
            os.write(2, b"CREDENTIAL_SECRET_CANARY" * 4096)
    if mode == "stderr-flood":
        while True:
            os.write(2, b"DOCUMENT_SECRET_CANARY" * 4096)
    if mode == "eof":
        os._exit(0)
    if mode == "malformed":
        Path("/output/result.json").write_bytes(b"{")
        os._exit(0)
    if mode == "oversized":
        Path("/output/result.json").write_bytes(b"x" * ((1 << 20) + 1))
        os._exit(0)
    if mode == "descendant":
        if os.fork() == 0:
            time.sleep(60)
            os._exit(0)
    if mode in ("network", "least-privilege"):
        for family in (socket.AF_INET, socket.AF_INET6, socket.AF_UNIX):
            try:
                socket.socket(family, socket.SOCK_STREAM)
            except OSError as exc:
                assert exc.errno == errno.EPERM
            else:
                raise AssertionError("network permitted")
    if mode == "least-privilege":
        for descriptor in Path('/proc/self/fd').iterdir():
            if int(descriptor.name) <= 2:
                continue
            try:
                target = os.readlink(descriptor)
            except FileNotFoundError:
                continue
            assert target.startswith('/runtime/') or target == '/seccomp.bpf'
        for path in ("/other-source", "/metadata", "/input/other.bin", "/home", "/sys/fs/cgroup"):
            assert not Path(path).exists()
        for path in ("/input/source.bin", "/input/job.json", "/runtime/bin/knowkit-worker"):
            try:
                with open(path, "ab") as stream:
                    stream.write(b"escape")
            except OSError as exc:
                assert exc.errno in (errno.EROFS, errno.EACCES)
            else:
                raise AssertionError("readonly mount writable")
        assert not any(k in os.environ for k in ("DATABASE_URL", "PGPASSWORD", "AWS_SECRET_ACCESS_KEY", "HOME"))
        assert not Path("/publish").exists()
    if mode == "temp":
        try:
            for number in range(64):
                Path(f"/tmp/fill-{number}").write_bytes(b"x" * (1 << 20))
        except OSError as exc:
            assert exc.errno == errno.ENOSPC
        else:
            raise AssertionError("temp unlimited")
    if mode == "output-flood":
        with open("/output/fill", "wb") as stream:
            while True:
                stream.write(b"x" * (1 << 20))
    if mode == "pids":
        children = []
        try:
            for _ in range(128):
                child = os.fork()
                if child == 0:
                    time.sleep(60)
                    os._exit(0)
                children.append(child)
        except OSError as exc:
            assert exc.errno == errno.EAGAIN and 1 <= len(children) < 64
        else:
            raise AssertionError("pids unlimited")
        finally:
            for child in children:
                os.kill(child, signal.SIGKILL)
            for child in children:
                os.waitpid(child, 0)
    ir = {
        "schemaVersion": "ir-1.0", "tenantId": envelope.value["tenantId"],
        "sourceVersionId": envelope.value["sourceVersionId"], "parserSignature": PROFILE.signature,
        "pages": [], "segments": [{"segmentId": "s1", "text": mode, "textDigest": sha256(source), "status": "ok"}],
        "blocks": [], "assets": [], "coverage": {"state": "complete", "counts": {
            "expected": 1, "processed": 1, "empty": 0, "failed": 0, "skipped": 0},
            "missingFeatures": [], "diagnostics": []},
    }
    assets = ()
    if mode == "asset":
        data = bytes.fromhex('89504e470d0a1a0a')
        ir['assets'] = [{'assetId': 'a1', 'mediaType': 'image/png', 'digest': sha256(data),
                         'byteLength': str(len(data)), 'pageId': None}]
        assets = (Asset('assets/image.png', data, 'image/png'),)
    return ParsedDocument(ir, assets)


PROFILE = Profile("protocol-fixture-v1", "synthetic", "1.0.0",
                  {"fixture": "worker-protocol-1.0.0", "ocr": False}, parse)


if __name__ == "__main__":
    raise SystemExit(main(profiles={PROFILE.id: PROFILE}))
