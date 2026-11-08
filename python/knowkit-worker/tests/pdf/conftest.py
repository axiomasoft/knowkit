import copy
import json
import os
import socket
from unittest.mock import patch
from pathlib import Path

import pytest

from knowkit_worker.profiles import DOCLING
from knowkit_worker.protocol import Envelope, sha256
from knowkit_worker.parsers.pdf.engine import parse

ROOT = Path(__file__).resolve().parents[4]
CORPUS = ROOT / "tests/fixtures/documents/pdf/v1"
MODELS = Path(os.environ.get("KNOWKIT_PDF_MODELS", ROOT / "python/knowkit-worker/.local-checks/pdf-models"))


def job_for(source: bytes) -> Envelope:
    base = json.loads((ROOT / "tests/worker/protocol/fixtures/v1/envelope.json").read_text())
    base.update(parserProfile=DOCLING.id, parserSignature=DOCLING.signature,
                profileFingerprint=DOCLING.signature["profileDigest"], inputDigest=sha256(source))
    return Envelope(base)


@pytest.fixture(scope="module")
def extracted():
    cache = {}

    def load(name):
        if name not in cache:
            source = (CORPUS / f"{name}.pdf").read_bytes()
            with patch.object(socket.socket, "connect", side_effect=AssertionError("runtime network attempted")):
                cache[name] = parse(source, job_for(source), MODELS)
        return copy.deepcopy(cache[name])

    return load
