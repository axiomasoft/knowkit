"""Run one targeted check and retain output without checkout/machine paths."""
import pathlib
import re
import subprocess
import sys

root = pathlib.Path(__file__).resolve().parents[3]
result = subprocess.run(sys.argv[2:], cwd=root, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
output = result.stdout.replace(str(root), "<repo>")
output = re.sub(r"Warning: PHP Startup: Unable to load dynamic library[^\n]*", "Warning: PHP Startup: optional extension could not load (runtime paths redacted).", output)
path = root / "tests/storage/blobs/evidence" / sys.argv[1]
path.write_text(output)
print(output[-8000:], end="")
raise SystemExit(result.returncode)
