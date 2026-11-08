"""No PATH search, engine fallback, default language, or runtime downloads."""

from pathlib import Path
import stat
import subprocess

from ..protocol import Refusal, decode, fingerprint, sha256

ROOT = Path(__file__).parent
NATIVE = ROOT / "native"


def record() -> dict:
    return decode((ROOT / "qualification.json").read_bytes())


def model_fingerprint(language: str) -> str:
    qualification = record()
    if language not in qualification["languages"]:
        raise Refusal("ocr_language_not_approved")
    return fingerprint("knowkit:ocr-model:v1", {
        "engine": qualification["engine"],
        "weights": [a for a in qualification["modelAssets"] if a["id"] in (language, "osd")],
    })


def verify_native(root: Path = NATIVE) -> None:
    if root.is_symlink():
        raise Refusal("ocr_assets_invalid")
    if not root.is_dir():
        raise Refusal("ocr_assets_missing", "unavailable")
    qualification = record()
    if (qualification["verdict"] != "restricted"
            or not qualification["distributionConstraints"]["localUseAllowed"]):
        raise Refusal("ocr_not_qualified", "unavailable")
    expected_paths = {asset["path"] for asset in qualification["files"]}
    paths = list(root.rglob("*"))
    if any(path.is_symlink() for path in paths):
        raise Refusal("ocr_assets_invalid")
    actual_paths = {p.relative_to(root).as_posix() for p in paths if not p.is_dir()}
    if actual_paths - expected_paths:
        raise Refusal("ocr_assets_invalid")
    if expected_paths - actual_paths:
        raise Refusal("ocr_assets_missing", "unavailable")
    for asset in qualification["files"]:
        path = root
        for part in Path(asset["path"]).parts:
            if part in ("..", ".") or Path(asset["path"]).is_absolute():
                raise Refusal("ocr_assets_invalid")
            path = path / part
            if path.is_symlink():
                raise Refusal("ocr_assets_invalid")
        try:
            info = path.stat()
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise Refusal("ocr_assets_invalid")
            if info.st_size != asset["byteLength"] or sha256(path.read_bytes()) != asset["sha256"]:
                raise Refusal("ocr_assets_mismatch")
        except OSError:
            raise Refusal("ocr_assets_missing", "unavailable") from None
    try:
        result = subprocess.run([str(root / "bin/tesseract"), "--version"],
                                capture_output=True, timeout=5, check=True)
    except (OSError, subprocess.SubprocessError):
        raise Refusal("ocr_backend_missing", "unavailable") from None
    if not result.stdout.splitlines() or result.stdout.splitlines()[0] != b"tesseract 5.5.4":
        raise Refusal("ocr_backend_version_mismatch")


def ready(root: Path = NATIVE) -> bool:
    try:
        verify_native(root)
        return True
    except (Refusal, OSError):
        return False
