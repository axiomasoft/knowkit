"""Run one targeted command and retain portable output; never invokes acceptance groups."""
import pathlib
import subprocess
import sys

root = pathlib.Path(__file__).resolve().parents[3]
label, *command = sys.argv[1:]
if not label.replace("-", "").isalnum() or not command:
    raise SystemExit("safe evidence label and command required")
result = subprocess.run(command, cwd=root, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
output = result.stdout.replace(str(root), "<repository>")
output = "\n".join(
    "Warning: PHPStan optional turbo extension unavailable (dynamic loading not supported)."
    if line.startswith("Warning: PHP Startup:") and "phpstan_turbo" in line else line.rstrip()
    for line in output.splitlines()
)
if "--log-junit" in command:
    report = root / command[command.index("--log-junit") + 1]
    if report.is_file():
        report.write_text(report.read_text().replace(str(root) + "/", ""))
target = root / "tests/security/policy/evidence" / (label + ".txt")
target.parent.mkdir(parents=True, exist_ok=True)
target.write_text(output + "\nexit=" + str(result.returncode) + "\n")
print(str(target.relative_to(root)) + ": exit=" + str(result.returncode))
raise SystemExit(result.returncode)
