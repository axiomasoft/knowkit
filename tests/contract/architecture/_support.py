"""Test-only architecture harness; no production authorization or database code."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import runpy
import shutil
import sys
from types import SimpleNamespace
import unittest

ROOT = Path(__file__).resolve().parents[3]


def load_checker(root=ROOT):
    return SimpleNamespace(**runpy.run_path(str(root / "tools/check-spec-contract.py"),
                                           run_name="architecture_checker"))


def case(identity):
    def decorate(function):
        function.case_id = identity
        return function
    return decorate


class CaseResult(unittest.TextTestResult):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.cases = []

    def startTest(self, test):
        super().startTest(test)
        identity = getattr(getattr(test, test._testMethodName), "case_id", test.id())
        self.cases.append({"id": identity, "status": "running"})

    def addSuccess(self, test):
        super().addSuccess(test)
        self.cases[-1]["status"] = "passed"

    def addFailure(self, test, error):
        super().addFailure(test, error)
        self.cases[-1]["status"] = "failed"

    def addError(self, test, error):
        super().addError(test, error)
        self.cases[-1]["status"] = "failed"

    def addSkip(self, test, reason):
        super().addSkip(test, reason)
        self.cases[-1]["status"] = "skipped"

    def addSubTest(self, test, subtest, error):
        super().addSubTest(test, subtest, error)
        if error is not None:
            self.cases[-1]["status"] = "failed"


def main(module):
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path)
    parser.add_argument("tests", nargs="*")
    args = parser.parse_args()
    loader = unittest.defaultTestLoader
    suite = (loader.loadTestsFromNames(args.tests, module)
             if args.tests else loader.loadTestsFromModule(module))
    result = unittest.TextTestRunner(verbosity=2, resultclass=CaseResult).run(suite)
    report = {"cases": result.cases, "count": result.testsRun,
              "failures": len(result.failures) + len(result.errors),
              "skips": len(result.skipped)}
    if args.report:
        args.report.write_text(json.dumps(report) + "\n", encoding="utf-8")
    return 0 if result.wasSuccessful() and result.testsRun else 1


def copy_candidate(destination):
    checker = load_checker()
    for relative in checker.input_digests(ROOT):
        source, target = ROOT / relative, destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)


def write_json(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sources_are_immutable(records):
    accepted = {}
    for source in records:
        digest = hashlib.sha256(source["text"].encode("utf-8")).hexdigest()
        if source["sourceDigest"] != digest:
            raise ValueError("digest_mismatch")
        identity = source["sourceVersionId"]
        if identity in accepted and accepted[identity] != digest:
            raise ValueError("immutable_conflict")
        accepted[identity] = digest
    return accepted


def evaluate_trace(trace):
    """Sequential specification model: guards/commit ordering, not real locks."""
    revoked, committed = False, False
    receipt = None
    owner_revision = None
    result = {"outcome": "empty", "textReleased": False,
              "usageCommitted": False, "externalWindow": False}
    previous_time = -1
    for event in trace["events"]:
        assert event["at"] >= previous_time, "non-monotonic contract trace"
        previous_time = event["at"]
        action = event["action"]
        if action == "owner_check":
            receipt = event
            owner_revision = event["ownerRevision"]
        elif action == "owner_change":
            owner_revision = event["ownerRevision"]
        elif action == "revoke":
            revoked = True
        elif action == "register":
            result["outcome"] = "denied" if revoked else "success"
        elif action == "commit_disclosure":
            if revoked:
                result["outcome"] = "denied"
            elif (receipt is None or receipt["decision"] == "unknown"
                  or receipt.get("maxAge") is None
                  or event["at"] >= receipt["expiresAt"]
                  or event["at"] - receipt["at"] > receipt["maxAge"]):
                result["outcome"] = "unknown"
            elif receipt["decision"] == "deny":
                result["outcome"] = "denied"
            else:
                assert receipt["decision"] == "allow"
                committed = True
                result.update(outcome="success", usageCommitted=True,
                              externalWindow=owner_revision != receipt["ownerRevision"])
        elif action == "uncertain_commit":
            # Even if the database may have committed, no confirmed usage permits bytes.
            committed = False
            result.update(outcome="unknown", textReleased=False)
        elif action == "release":
            result["textReleased"] = committed
        elif action == "replay":
            result.update(textReleased=False, metadataOnly=True)
        else:
            raise AssertionError("unknown trace action")
    return result
