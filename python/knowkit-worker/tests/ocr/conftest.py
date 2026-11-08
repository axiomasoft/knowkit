import copy
import json
import os
from pathlib import Path
import socket
from unittest.mock import patch

import pytest

from knowkit_worker.ocr.engine import parse
from knowkit_worker.ocr.profile import descriptor
from knowkit_worker.protocol import Envelope, sha256

ROOT = Path(__file__).resolve().parents[4]
CORPUS = ROOT / "tests/fixtures/documents/scans/v1"
MODELS = Path(os.environ.get("KNOWKIT_PDF_MODELS", ROOT / "python/knowkit-worker/.local-checks/pdf-models"))
NATIVE = Path(os.environ.get("KNOWKIT_OCR_NATIVE", ROOT / "python/knowkit-worker/.local-checks/ocr-native"))


def job_for(source: bytes, language="eng", max_pages=500):
    profile = descriptor(language)
    job = json.loads((ROOT / "tests/worker/protocol/fixtures/v1/envelope.json").read_text())
    job.update(parserProfile=profile.id, parserSignature=profile.signature,
               profileFingerprint=profile.signature["profileDigest"], inputDigest=sha256(source))
    job["limits"]["maxPages"] = max_pages
    job["limits"].update(wallSeconds=60, cpuSeconds=45, memoryBytes="2147483648",
                         tempBytes="4294967296", maxOutputBytes="67108864")
    return Envelope(job)


@pytest.fixture(scope="module")
def extracted():
    cache = {}

    def load(name, language="eng", max_pages=500):
        key = name, language, max_pages
        if key not in cache:
            source = (CORPUS / f"{name}.pdf").read_bytes()
            with patch.object(socket.socket, "connect", side_effect=AssertionError("runtime network attempted")):
                cache[key] = parse(source, job_for(source, language, max_pages), language=language,
                                   native_root=NATIVE, model_root=MODELS)
        return copy.deepcopy(cache[key])

    return load
