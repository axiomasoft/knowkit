"""Negative regressions for the P1.1 gate.

Adapted fresh-process and mandatory-case patterns; modified for KnowKit.
Donor revisions and license notices: docs/architecture/reuse.md.
"""
from __future__ import annotations

import copy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from _support import ROOT, case, copy_candidate, load_checker, main, write_json

CHECKER = load_checker()


class ManifestTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="knowkit-manifest-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        copy_candidate(self.root)

    def mutate_manifest(self, change):
        path = self.root / CHECKER.SPEC
        data = json.loads(path.read_text(encoding="utf-8"))
        change(data)
        write_json(path, data)

    def rejects(self, marker):
        with self.assertRaisesRegex(CHECKER.ContractError, marker):
            CHECKER.validate_contract(self.root)

    @case("architecture.manifest-valid")
    def test_manifest_and_all_normative_links(self):
        manifest = CHECKER.validate_contract(self.root)
        self.assertGreaterEqual(len(manifest["anchors"]), 40)
        self.assertEqual(len(manifest["anchors"]), 66)
        self.assertEqual(manifest["authority"]["blockingDecisions"], [])

    @case("architecture.forbidden-dependency")
    def test_forbidden_package_and_layer_backimports(self):
        path = self.root / CHECKER.SPEC
        original = json.loads(path.read_text(encoding="utf-8"))
        changes = [
            lambda m: m["packages"]["agentools-bridge"]["dependsOn"].append("answer"),
            lambda m: m["packages"]["chatom"]["dependsOn"].append("agentools-bridge"),
            lambda m: m["coreLayers"]["Application"].append("Adapter"),
        ]
        for change in changes:
            with self.subTest(change=changes.index(change)):
                data = copy.deepcopy(original)
                change(data)
                write_json(path, data)
                self.rejects("approved .*DAG")

    @case("architecture.dependency-cycle")
    def test_dependency_cycle_is_rejected(self):
        self.mutate_manifest(lambda m: m["packages"]["core"]["dependsOn"].append("answer"))
        self.rejects("dependency cycle")

    @case("architecture.core-runtime-dependency")
    def test_vendor_runtime_dependency_is_rejected(self):
        self.mutate_manifest(
            lambda m: m["packages"]["core"]["externalRuntime"].append("illuminate/support"))
        self.rejects("approved package DAG/runtime")

    @case("architecture.core-php-floor")
    def test_optional_host_cannot_raise_core_floor(self):
        self.mutate_manifest(lambda m: m["packages"]["core"].update(minPhp="8.4"))
        self.rejects("approved package DAG/runtime")

    @case("architecture.required-anchor")
    def test_removing_anchor_cannot_hide_behind_total_count(self):
        self.mutate_manifest(lambda m: m["anchors"][0].update(id="ARCH-UNRELATED"))
        self.rejects("mandatory architecture anchor absent")

    @case("architecture.anchor-obligation")
    def test_marker_and_normative_obligation_cannot_disappear(self):
        path = self.root / "specs/architecture/architecture.md"
        original = path.read_text(encoding="utf-8")
        for old, new in [("Core MUST NOT import answer DTOs", "Core may import answer DTOs"),
                         ("AccessPolicy::authorizeQuery", "AccessPolicy::someOtherMethod")]:
            with self.subTest(marker=old):
                self.assertIn(old, original)
                path.write_text(original.replace(old, new), encoding="utf-8")
                self.rejects("mandatory contract marker absent")
        path.write_text(original.replace("MUST", "may"), encoding="utf-8")
        self.rejects("no normative obligation")

    @case("architecture.adr-reference")
    def test_missing_or_unmapped_adr_reference_fails(self):
        path = self.root / "docs/adr/0002-immutable-identity-and-authority.md"
        text = path.read_text(encoding="utf-8")
        path.write_text(text.replace("#arch-citation", "#arch-nonexistent"), encoding="utf-8")
        self.rejects("missing document anchor")

    @case("architecture.adr-pending")
    def test_pending_adr_and_blocking_decision_fail(self):
        path = self.root / "docs/adr/0001-independent-core-and-delivery.md"
        original = path.read_text(encoding="utf-8")
        path.write_text(original.replace("Status: accepted", "Status: proposed"), encoding="utf-8")
        self.rejects("ADR is not accepted")
        path.write_text(original, encoding="utf-8")
        self.mutate_manifest(lambda m: m["authority"]["blockingDecisions"].append("Q-new"))
        self.rejects("unresolved blocking decision")

    @case("architecture.fixture-digest")
    def test_changed_fixture_without_digest_rationale_is_rejected(self):
        path = self.root / CHECKER.TEST_DIR / "fixtures/v1/version-axes.json"
        path.write_bytes(path.read_bytes() + b" ")
        self.rejects("fixture digest mismatch")

    @case("architecture.fixture-file")
    def test_missing_fixture_fails(self):
        (self.root / CHECKER.TEST_DIR / "fixtures/v1/lifecycle-window.json").unlink()
        self.rejects("missing file")

    @case("architecture.fixture-version-rationale")
    def test_missing_fixture_metadata_and_unknown_schema_fail(self):
        path = self.root / CHECKER.CORPUS
        original = json.loads(path.read_text(encoding="utf-8"))
        for change in [
            lambda m: m.update(versionRationale=""),
            lambda m: m["entries"][0]["rights"].pop("license"),
            lambda m: m["entries"][0]["rights"].pop("origin"),
            lambda m: m["entries"][0].update(schemaId="future-schema"),
            lambda m: m["entries"][0]["expected"].pop("scenarioOutcomes"),
        ]:
            with self.subTest(change=change):
                data = copy.deepcopy(original)
                change(data)
                write_json(path, data)
                self.rejects("fixture")

    @case("architecture.unknown-manifest-version")
    def test_unknown_manifest_version_fails(self):
        self.mutate_manifest(lambda m: m.update(schemaVersion="knowkit-architecture:99"))
        self.rejects("unsupported architecture manifest version")

    @case("architecture.required-group-cases")
    def test_declared_required_case_cannot_be_removed(self):
        path = self.root / CHECKER.GROUP_FILE
        data = json.loads(path.read_text(encoding="utf-8"))
        data["requiredCaseIds"].remove("architecture.external-owner-window")
        write_json(path, data)
        self.rejects("required case inventory differs")


class RunnerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="knowkit-runner-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        copy_candidate(self.root)
        # Tiny child programs exercise CLI contracts without recursively running this suite.
        for name in CHECKER.TEST_CASES:
            self.stub(name)

    def stub(self, name, cases=None, *, exit_code=0, mutate=False):
        if cases is None:
            cases = [{"id": identity, "status": "passed"} for identity in CHECKER.TEST_CASES[name]]
        report = {"cases": cases, "count": len(cases), "failures": 0, "skips": 0}
        program = (
            "import json,sys\nfrom pathlib import Path\n"
            + ("p=Path('docs/architecture/reuse.md'); p.write_text(p.read_text()+'\\n')\n"
               if mutate else "")
            + "Path(sys.argv[sys.argv.index('--report')+1]).write_text("
            + repr(json.dumps(report)) + ")\nraise SystemExit(" + str(exit_code) + ")\n"
        )
        (self.root / CHECKER.TEST_DIR / name).write_text(program, encoding="utf-8")

    def cli(self, *arguments):
        return subprocess.run(
            [sys.executable, "-I", "-B", str(self.root / "tools/check-spec-contract.py"),
             "--root", str(self.root), *arguments],
            cwd=self.root, capture_output=True, text=True, timeout=15, check=False)

    def cli_rejects(self, marker):
        result = self.cli()
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn(marker, json.loads(result.stdout)["error"])

    @case("architecture.unknown-group")
    def test_unknown_group_fails_in_fresh_interpreter(self):
        for option in ("--group", "--check"):
            with self.subTest(option=option):
                result = self.cli(option, "unregistered")
                self.assertNotEqual(result.returncode, 0)

    @case("architecture.explicit-cli")
    def test_explicit_specs_and_architecture_check_select_the_required_group(self):
        for directory in ("specs", str(self.root / "specs")):
            with self.subTest(directory=directory):
                result = self.cli("--specs", directory, "--check", "architecture")
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                report = json.loads(result.stdout)
                self.assertEqual(report["group"], "contract:architecture")
                self.assertEqual(report["outcome"], "success")
                CHECKER.validate_case_report(
                    report, [identity for cases in CHECKER.TEST_CASES.values()
                             for identity in cases])

    @case("architecture.specs-directory")
    def test_explicit_specs_directory_is_not_silently_ignored(self):
        for directory in ("missing-specs", "other-specs"):
            with self.subTest(directory=directory):
                if directory == "other-specs":
                    (self.root / directory).mkdir()
                result = self.cli("--specs", directory, "--check", "architecture")
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("--specs must select the specs directory under --root",
                              json.loads(result.stdout)["error"])

    @case("architecture.missing-tests")
    def test_missing_test_file_fails(self):
        (self.root / CHECKER.TEST_DIR / "test_fixtures.py").unlink()
        self.cli_rejects("missing file")

    @case("architecture.zero-cases")
    def test_zero_selected_cases_fail(self):
        self.stub("test_fixtures.py", cases=[])
        self.cli_rejects("zero selected test cases")

    @case("architecture.skipped-case")
    def test_required_skip_fails(self):
        cases = [{"id": identity, "status": "passed"}
                 for identity in CHECKER.TEST_CASES["test_fixtures.py"]]
        cases[0]["status"] = "skipped"
        self.stub("test_fixtures.py", cases=cases)
        self.cli_rejects("failed/skipped required case")

    @case("architecture.missing-case")
    def test_missing_executed_case_fails(self):
        cases = [{"id": identity, "status": "passed"}
                 for identity in CHECKER.TEST_CASES["test_fixtures.py"][:-1]]
        self.stub("test_fixtures.py", cases=cases)
        self.cli_rejects("missing/unexpected executed case IDs")

    @case("architecture.duplicate-case")
    def test_duplicate_executed_case_fails(self):
        cases = [{"id": identity, "status": "passed"}
                 for identity in CHECKER.TEST_CASES["test_fixtures.py"]]
        cases.append(dict(cases[0]))
        self.stub("test_fixtures.py", cases=cases)
        self.cli_rejects("duplicate executed case ID")

    @case("architecture.child-failure")
    def test_child_failure_beats_a_valid_looking_report(self):
        self.stub("test_fixtures.py", exit_code=7)
        self.cli_rejects("nested check failed: test_fixtures.py (exit 7)")

    @case("architecture.input-stability")
    def test_child_cannot_change_the_candidate_being_checked(self):
        self.stub("test_fixtures.py", mutate=True)
        self.cli_rejects("architecture inputs changed during check")


if __name__ == "__main__":
    raise SystemExit(main(sys.modules[__name__]))
