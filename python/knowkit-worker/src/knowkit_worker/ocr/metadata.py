"""Versioned metadata encoded in IR 1.0 LocalId, without extending its schema."""

from dataclasses import dataclass
import re

from ..protocol import Refusal

PATTERN = re.compile(r"ocr-p([1-9][0-9]*)-b([1-9][0-9]*):lang-(eng|rus):model-([0-9a-f]{64}):conf-(0|[1-9][0-9]{0,3})")


@dataclass(frozen=True)
class OcrMetadata:
    physical_no: int
    ordinal: int
    language: str
    model_fingerprint: str
    confidence: int  # extraction confidence in permille, never truth probability

    def block_id(self) -> str:
        value = (f"ocr-p{self.physical_no}-b{self.ordinal}:lang-{self.language}:"
                 f"model-{self.model_fingerprint}:conf-{self.confidence}")
        if (len(value) > 128 or not PATTERN.fullmatch(value) or not 0 <= self.confidence <= 1000
                or not 1 <= self.physical_no <= 4294967295 or not 1 <= self.ordinal <= 4294967295):
            raise Refusal("invalid_ocr_metadata")
        return value

    @classmethod
    def from_block_id(cls, value: str):
        match = PATTERN.fullmatch(value)
        if match is None or len(value) > 128:
            raise Refusal("invalid_ocr_metadata")
        page, ordinal, language, model, confidence = match.groups()
        metadata = cls(int(page), int(ordinal), language, model, int(confidence))
        metadata.block_id()
        return metadata
