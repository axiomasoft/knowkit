"""Cross-language wire and parser-failure oracles; real sandbox is mandatory."""

import json
import os
from pathlib import Path
import subprocess
from types import SimpleNamespace

import pytest

from knowkit_worker.parsers.pdf.engine import map_document, preflight
from knowkit_worker.profiles import DOCLING, PROFILES
from knowkit_worker.protocol import Contracts, Envelope, Refusal, canonical, sha256
from knowkit_worker.rendering.pdf import open_pdf

ROOT = Path(__file__).resolve().parents[4]
FIXTURES = ROOT / "tests/worker/e2e/fixtures/v1"


def test_versioned_fixtures_pin_provenance_digests_and_failure_outcomes():
    manifest = json.loads((FIXTURES / "manifest.json").read_bytes())
    assert manifest["fixtureVersion"] == "1.2.0"
    assert manifest["versionRationale"] and manifest["rights"]["license"] and manifest["rights"]["origin"]
    for entry in manifest["fixtures"]:
        assert sha256((ROOT / entry["path"]).read_bytes()) == entry["sha256"]
        assert entry["expected"]["outcome"]
    profiles = json.loads((FIXTURES / "profiles.json").read_bytes())
    for name, descriptor in profiles.items():
        assert PROFILES[name].signature == descriptor["signature"]
        assert PROFILES[name].options == descriptor["options"]
    ir = json.loads((FIXTURES / "nine-of-ten.ir.json").read_bytes())
    Contracts(ROOT / "schemas").validate(ir, "document-ir")
    assert ir["coverage"]["counts"] == dict(expected=10, processed=9, failed=1, skipped=0, empty=0)
    assert manifest["expected"]["missingPages"] == [10]


def test_native_mapper_preserves_absent_tenth_page_as_failure_not_complete():
    source = (FIXTURES / "ten-pages.pdf").read_bytes()
    job = json.loads((ROOT / "tests/worker/protocol/fixtures/v1/envelope.json").read_bytes())
    job.update(parserProfile=DOCLING.id, parserSignature=DOCLING.signature,
               profileFingerprint=DOCLING.signature["profileDigest"], inputDigest=sha256(source))
    job["limits"]["maxPages"] = 500

    def item(number):
        text = f"Lifecycle page {number}."
        box = SimpleNamespace(to_top_left_origin=lambda height: SimpleNamespace(as_tuple=lambda: (30, 30, 200, 50)))
        return SimpleNamespace(text=text, label="text", prov=[SimpleNamespace(
            page_no=number, charspan=(0, len(text)), bbox=box)]), 0

    native = SimpleNamespace(pages={number: object() for number in range(1, 10)},
                             iterate_items=lambda: iter(item(number) for number in range(1, 10)))
    with open_pdf(source, 500) as document:
        parsed = map_document(native, document, preflight(document), job)
    Contracts(ROOT / "schemas").validate(parsed.ir, "document-ir")
    assert parsed.ir["coverage"]["state"] == "partial"
    assert parsed.ir["coverage"]["counts"] == dict(expected=10, processed=9, failed=1, skipped=0, empty=0)
    assert parsed.ir["pages"][9]["status"] == "failed"
    assert parsed.ir["pages"][9]["text"] == ""
    assert "page_extraction" in parsed.ir["coverage"]["missingFeatures"]
    result = Envelope(job).result(parsed.ir, canonical(parsed.ir))
    assert result["status"] == "partial"


def test_network_denied_in_real_runtime_namespace_and_seccomp():
    runtime = Path(os.environ.get("KNOWKIT_OCR_RUNTIME", ROOT / "python/knowkit-worker/.local-checks/ocr-runtime"))
    # Failure of bwrap/runtime/namespace support is a hard test failure, never a skip.
    assert (runtime / "bom.json").is_file()
    probe = """
import errno, socket
try:
    socket.socket().connect(('198.51.100.1', 9))
except OSError as error:
    assert error.errno == errno.EPERM
else:
    raise AssertionError('network was available')
print('NETWORK_DENIED')
"""
    descriptor = os.open(runtime / "seccomp.bpf", os.O_RDONLY)
    try:
        completed = subprocess.run([
            "/usr/bin/bwrap", "--unshare-all", "--die-with-parent", "--new-session", "--cap-drop", "ALL",
            "--ro-bind", str(runtime), "/", "--tmpfs", "/tmp", "--proc", "/proc", "--dev", "/dev",
            "--clearenv", "--setenv", "PATH", "/runtime/bin", "--seccomp", str(descriptor),
            "--", "/runtime/bin/python", "-I", "-c", probe,
        ], pass_fds=(descriptor,), capture_output=True, timeout=15, check=False)
    finally:
        os.close(descriptor)
    assert completed.returncode == 0, completed.stderr.decode()
    assert completed.stdout == b"NETWORK_DENIED\n"


def test_parser_change_is_exact_and_never_falls_back_to_old_signature():
    from knowkit_worker.profiles import lookup

    profiles = json.loads((FIXTURES / "profiles.json").read_bytes())
    old = profiles["docling-pdf-ocr-eng-v1"]["signature"]
    with pytest.raises(Refusal, match="profile_mismatch"):
        lookup("docling-pdf-text-v1", old, PROFILES)
    with pytest.raises(Refusal, match="unsupported_profile"):
        lookup("docling-next-unqualified-v2", old, PROFILES)
