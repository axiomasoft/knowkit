import json
from pathlib import Path

import pytest

from build_fixtures import job_for, retain

ROOT = Path(__file__).resolve().parents[4]
CORPUS = ROOT / "tests/fixtures/documents/structure/v1"


@pytest.fixture
def oracle():
    return json.loads((CORPUS / "source.json").read_bytes())


@pytest.fixture
def retained(oracle):
    return retain(oracle, (CORPUS / "original.png").read_bytes(), (CORPUS / "preview.jpg").read_bytes())
