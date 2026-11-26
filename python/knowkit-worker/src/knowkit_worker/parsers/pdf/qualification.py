"""Closed code/weights inventory, verified before activating a PDF capability."""

import importlib.metadata
from pathlib import Path
import stat

from ...protocol import Refusal, decode, sha256

ROOT = Path(__file__).parent


def record() -> dict:
    return decode((ROOT / "qualification.json").read_bytes())


def verify_dependencies() -> None:
    qualification = record()
    if (qualification["verdict"] not in ("allowed", "restricted")
            or not qualification["distributionConstraints"]["localUseAllowed"]
            or any(not r["fulfilled"] for r in qualification["distributionConstraints"]["requirements"])):
        raise Refusal("profile_not_qualified", "unavailable")
    for component in qualification["transitiveComponents"]:
        try:
            version = importlib.metadata.version(component["id"])
        except importlib.metadata.PackageNotFoundError:
            raise Refusal("backend_missing", "unavailable") from None
        if version != component["version"]:
            raise Refusal("backend_version_mismatch")


def verify_models(root: Path) -> None:
    if root.is_symlink() or not root.is_dir():
        raise Refusal("model_assets_missing", "unavailable")
    assets = record()["modelAssets"]
    expected = {asset["path"] for asset in assets}
    paths = list(root.rglob("*"))
    if any(path.is_symlink() for path in paths):
        raise Refusal("model_assets_invalid")
    actual = {p.relative_to(root).as_posix() for p in paths if not p.is_dir()}
    if actual - expected:
        raise Refusal("model_assets_invalid")
    for asset in assets:
        relative = Path(asset["path"])
        if relative.is_absolute() or ".." in relative.parts:
            raise Refusal("model_assets_invalid")
        path = root
        for part in relative.parts:
            path = path / part
            if path.is_symlink():
                raise Refusal("model_assets_invalid")
        try:
            info = path.stat()
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise Refusal("model_assets_invalid")
            if info.st_size != asset["byteLength"] or sha256(path.read_bytes()) != asset["sha256"]:
                raise Refusal("model_assets_mismatch")
        except OSError:
            raise Refusal("model_assets_missing", "unavailable") from None


def ready(root: Path = ROOT / "models") -> bool:
    try:
        verify_dependencies()
        verify_models(root)
    except (Refusal, OSError):
        return False
    return True
