"""Build disposable real sandbox runtime with a synthetic parser, not a contract double."""

import importlib.util
from pathlib import Path
import shutil
import sys

repo = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location("build_runtime", repo / "deploy/worker/build_runtime.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
root = Path(sys.argv[1])
builder.build(root, repo)
shutil.copy2(Path(__file__).with_name("fixture_parser.py"),
             root / "runtime/lib/python3.12/site-packages/knowkit_worker/_protocol_fixture.py")
(root / "runtime/bin/knowkit-worker").write_text(
    "#!/runtime/bin/python\nimport sys\nsys.path.insert(0, '/runtime/lib/python3.12/site-packages')\n"
    "from knowkit_worker._protocol_fixture import main, PROFILE\n"
    "raise SystemExit(main(profiles={PROFILE.id: PROFILE}))\n")
builder.write_bom(root, repo)
