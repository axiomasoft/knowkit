"""Build disposable real sandbox runtime with a synthetic parser, not a contract double."""

import importlib.util
from pathlib import Path
import shutil
import sys


def prune_unused_stdlib(root: Path) -> None:
    stdlib = root / "runtime/lib/python3.12"
    for name in ("idlelib", "tkinter", "turtledemo", "lib2to3", "pydoc_data"):
        path = stdlib / name
        if path.is_dir():
            shutil.rmtree(path)
    for name in ("turtle.py", "pydoc.py"):
        path = stdlib / name
        if path.is_file():
            path.unlink()
    for path in stdlib.glob("config-*"):
        if path.is_dir():
            shutil.rmtree(path)


def main() -> None:
    repo = Path(__file__).resolve().parents[3]
    spec = importlib.util.spec_from_file_location("build_runtime", repo / "deploy/worker/build_runtime.py")
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    root = Path(sys.argv[1])
    # This synthetic parser exercises only the wire protocol and sandbox, not PDF/OCR.
    # Keep its runtime small; production builds retain the full locked worker closure.
    builder.build(root, repo, requirements=("jsonschema",))
    shutil.copy2(Path(__file__).with_name("fixture_parser.py"),
                 root / "runtime/lib/python3.12/site-packages/knowkit_worker/_protocol_fixture.py")
    (root / "runtime/bin/knowkit-worker").write_text(
        "#!/runtime/bin/python\nimport sys\nsys.path.insert(0, '/runtime/lib/python3.12/site-packages')\n"
        "from knowkit_worker._protocol_fixture import main, PROFILE\n"
        "raise SystemExit(main(profiles={PROFILE.id: PROFILE}))\n")
    # The protocol fixture never imports these. Dropping them keeps BOM verification on the real
    # sandbox path without hashing a static libpython and unused stdlib trees on every job.
    prune_unused_stdlib(root)
    builder.write_bom(root, repo)


if __name__ == "__main__":
    main()
