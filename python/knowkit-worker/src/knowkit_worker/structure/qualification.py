"""Retention qualification inherits the exact frozen PDF code/weights inventory."""

from pathlib import Path

from ..parsers.pdf.qualification import record as pdf_record
from ..protocol import Refusal, decode, fingerprint


def record() -> dict:
    return decode(Path(__file__).with_name("qualification.json").read_bytes())


def verify() -> None:
    qualification = record()
    if (qualification["schemaVersion"] != "knowkit-structure-qualification-1.0"
            or qualification["version"] != "1" or qualification["id"] != "docling-pdf-structure-v1"
            or qualification["verdict"] != "restricted"
            or qualification["baseQualificationDigest"] != fingerprint("knowkit:pdf-qualification:v1", pdf_record())
            or qualification["enabledCapabilities"] != ["parser.text", "parser.images"]
            or qualification["settings"] != dict(tableRecognition=False, formulaEnrichment=False,
                ocr=False, network=False, structureRetention="native-ir-retention-v1")):
        raise Refusal("structure_qualification_mismatch", "unavailable")


def ready() -> bool:
    try:
        verify()
    except (Refusal, OSError):
        return False
    return True
