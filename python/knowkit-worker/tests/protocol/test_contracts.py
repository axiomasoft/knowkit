import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys

import pytest

from knowkit_worker.files import read_regular
from knowkit_worker.profiles import DOCLING, PROFILES, lookup
from knowkit_worker.protocol import Contracts, Envelope, Refusal, canonical, decode, fingerprint

ROOT = Path(__file__).resolve().parents[4]
FIXTURES = ROOT / "tests/worker/protocol/fixtures/v1"


def test_schema_copies_and_golden_wire():
    contracts = Contracts(ROOT / "schemas")
    for name in ("common", "job-envelope", "worker-result", "document-ir"):
        assert (ROOT / f"schemas/{name}.schema.json").read_bytes() == (
            ROOT / f"python/knowkit-worker/src/knowkit_worker/schemas/{name}.schema.json").read_bytes()
    job = Envelope.parse((FIXTURES / "envelope.json").read_bytes(), contracts)
    result = decode((FIXTURES / "result.json").read_bytes())
    ir = decode((FIXTURES / "ir.json").read_bytes())
    contracts.validate(result, "worker-result")
    contracts.validate(ir, "document-ir")
    assert result == job.result(ir, canonical(ir))
    assert result["irDigest"] == hashlib.sha256(canonical(ir)).hexdigest()
    assert result["irByteLength"] == str(len((FIXTURES / "ir.json").read_bytes()))


def test_strict_schema_and_json_negative_examples():
    contracts = Contracts(ROOT / "schemas")
    base = decode((FIXTURES / "envelope.json").read_bytes())
    for key, value in (("schemaVersion", "job-envelope-2.0"), ("inputPath", "/input/../etc/passwd"),
                       ("outDir", "/output/../../tmp"), ("inputFile", "/input/source.bin"),
                       ("fence", "9223372036854775808"), ("profileFingerprint", "0" * 64)):
        changed = copy.deepcopy(base)
        changed[key] = value
        with pytest.raises(Refusal):
            Envelope.parse(canonical(changed), contracts)
    for data in (b'{"a":1,"a":2}', b'{"x":1.5}', b'{"x":NaN}', b'{"x":"\\ud800"}',
                 b'{"x":9007199254740992}', b'{}' + b' ' * (1 << 20), b'{"x":' + b'[' * 70 + b'0' + b']' * 70 + b'}'):
        with pytest.raises(Refusal):
            decode(data)
    expired = copy.deepcopy(base)
    expired["leaseExpiresAt"] = "2000-01-01T00:00:00Z"
    with pytest.raises(Refusal, match="stale_lease"):
        Envelope.parse(canonical(expired), contracts)


def test_paths_reject_symlinks_hardlinks_fifo_and_escape(tmp_path):
    source = tmp_path / "source"
    source.write_bytes(b"source")
    (tmp_path / "link").symlink_to(source)
    import os
    os.link(source, tmp_path / "hard")
    os.mkfifo(tmp_path / "fifo")
    for path in ("../source", "/etc/passwd", "link", "hard", "fifo", "a%2fsource", "a\\source"):
        with pytest.raises(Refusal):
            read_regular(tmp_path, path, 64)
    source.unlink()
    (tmp_path / "hard").unlink()
    source.write_bytes(b"source")
    source.chmod(0o400)
    assert read_regular(tmp_path, "source", 64, True) == b"source"
    source.chmod(0o600)
    with pytest.raises(Refusal, match="input_not_readonly"):
        read_regular(tmp_path, "source", 64, True)


def test_profiles_are_exact_and_capabilities_do_not_qualify_pdf():
    with pytest.raises(Refusal, match="unsupported_profile"):
        lookup("unknown-profile", DOCLING.signature, PROFILES)
    with pytest.raises(Refusal, match="profile_not_qualified") as failure:
        lookup(DOCLING.id, DOCLING.signature, PROFILES)
    assert failure.value.outcome == "unavailable"
    changed = dict(DOCLING.signature, version="other")
    with pytest.raises(Refusal, match="profile_mismatch"):
        lookup(DOCLING.id, changed, PROFILES)
    child = subprocess.run([sys.executable, "-m", "knowkit_worker", "capabilities"],
                           capture_output=True, check=True, timeout=10)
    capabilities = json.loads(child.stdout)
    assert capabilities["transport"] == "subprocess"
    assert capabilities["profiles"][0]["parserSignature"]["version"] == "2.148.0"
    assert all(not p["qualified"] and not p["ocr"] and not p["pdf"] for p in capabilities["profiles"])
    refused = subprocess.run([sys.executable, "-m", "knowkit_worker", "run", "--envelope", "/secret/host-path"],
                             capture_output=True, timeout=10)
    assert refused.returncode == 3
    assert json.loads(refused.stderr) == {"code": "path_escape", "outcome": "denied"}
    assert b"/secret" not in refused.stderr


def test_jcs_uses_utf16_and_typed_result_domain():
    assert canonical({"\ue000": 2, "\U00010000": 1}) == '{"\U00010000":1,"\ue000":2}'.encode()
    payload = {"status": "complete"}
    assert fingerprint("knowkit:worker-result:v1", payload) == hashlib.sha256(
        b'knowkit:worker-result:v1\0{"status":"complete"}').hexdigest()
    assert fingerprint("knowkit:worker-result:v1", payload) != hashlib.sha256(canonical(payload)).hexdigest()
