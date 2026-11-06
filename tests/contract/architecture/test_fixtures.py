"""Versioned design fixtures; no PHP runtime or PostgreSQL concurrency claims."""
from __future__ import annotations

import copy
import hashlib
import json
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from _support import (ROOT, case, evaluate_trace, load_checker, main,
                      sources_are_immutable)

CHECKER = load_checker()
CORPUS = CHECKER.validate_fixture_manifest(ROOT)
AXES = ("sourceVersionId", "documentRevisionId", "chunkSetId", "generationId")
EXPECTED_CHANGES = {
    "re-ocr": ["documentRevisionId", "chunkSetId", "generationId"],
    "re-chunk": ["chunkSetId", "generationId"],
    "re-embed": ["generationId"],
    "changed-original": list(AXES),
}


def canonical_digest(payload):
    # These fixture IR payloads contain one ASCII key and one Unicode string,
    # without numbers or normalization. This is not a general JCS implementation.
    return hashlib.sha256(json.dumps(payload, ensure_ascii=False, sort_keys=True,
                                    separators=(",", ":")).encode("utf-8")).hexdigest()


def resolve_exact(citation, revisions):
    candidates = [revision for revision in revisions
                  if revision["documentRevisionId"] == citation["documentRevisionId"]
                  and revision["sourceVersionId"] == citation["sourceVersionId"]]
    if len(candidates) != 1:
        raise ValueError("missing_exact_revision")
    revision = candidates[0]
    if canonical_digest(revision["irPayload"]) != citation["irDigest"]:
        raise ValueError("canonical_digest_mismatch")
    canonical = revision["irPayload"]["canonicalText"].encode("utf-8")
    if hashlib.sha256(canonical).hexdigest() != citation["contentDigest"]:
        raise ValueError("canonical_digest_mismatch")
    return canonical[citation["byteStart"]:citation["byteEnd"]].decode("utf-8")


class ArchitectureFixtureTests(unittest.TestCase):
    @case("architecture.core-independent")
    def test_core_has_no_optional_host_runtime_dependency(self):
        fixture = CORPUS["core-independent"]
        self.assertTrue(fixture["modelOnly"])
        self.assertEqual(fixture["packages"], CHECKER.APPROVED_PACKAGES)
        self.assertEqual(fixture["layers"], CHECKER.APPROVED_LAYERS)
        core = fixture["packages"]["core"]
        self.assertEqual(core["dependsOn"], [])
        self.assertEqual(core["externalRuntime"], [])
        self.assertEqual(core["minPhp"], "8.3")
        self.assertIn("Laravel", fixture["optionalAbsent"])
        self.assertIn("Agentools", fixture["optionalAbsent"])
        CHECKER.check_dag({key: value["dependsOn"]
                           for key, value in fixture["packages"].items()}, "fixture")
        self.assertEqual(fixture["expected"]["adapterPath"], "src/Adapter")

    def states(self):
        fixture = CORPUS["version-axes"]
        self.assertEqual(fixture["expectedChangedAxes"], EXPECTED_CHANGES)
        states = {state["scenario"]: state for state in fixture["states"]}
        for name, expected in EXPECTED_CHANGES.items():
            self.assertEqual([axis for axis in AXES
                              if states[name][axis] != states["baseline"][axis]], expected)
        # Distinct ID domains may not be collapsed into one generic version counter.
        identities = [{state[axis] for state in states.values()} for axis in AXES]
        for index, values in enumerate(identities):
            for later in identities[index + 1:]:
                self.assertTrue(values.isdisjoint(later))
        return states

    @case("architecture.version-source")
    def test_changed_original_requires_new_source_version_and_rejects_mutation(self):
        fixture = CORPUS["version-axes"]
        states = self.states()
        sources_are_immutable(fixture["sources"])
        self.assertNotEqual(states["changed-original"]["sourceVersionId"],
                            states["baseline"]["sourceVersionId"])
        conflicting = dict(fixture["sources"][0])
        conflicting["text"] = fixture["negative"]["changedText"]
        conflicting["sourceDigest"] = hashlib.sha256(conflicting["text"].encode()).hexdigest()
        with self.assertRaisesRegex(ValueError, fixture["negative"]["expectedOutcome"]):
            sources_are_immutable([fixture["sources"][0], conflicting])

    @case("architecture.version-document")
    def test_reocr_changes_revision_without_changing_original(self):
        fixture = CORPUS["version-axes"]
        states = self.states()
        before, after = states["baseline"], states["re-ocr"]
        self.assertEqual(before["sourceVersionId"], after["sourceVersionId"])
        self.assertNotEqual(before["documentRevisionId"], after["documentRevisionId"])
        for revision in fixture["revisions"]:
            self.assertEqual(canonical_digest(revision["irPayload"]), revision["irDigest"])
        self.assertNotEqual(fixture["revisions"][0]["irDigest"],
                            fixture["revisions"][1]["irDigest"])

    @case("architecture.version-chunkset")
    def test_rechunk_preserves_source_and_document_revision(self):
        states = self.states()
        before, after = states["baseline"], states["re-chunk"]
        self.assertEqual(before["sourceVersionId"], after["sourceVersionId"])
        self.assertEqual(before["documentRevisionId"], after["documentRevisionId"])
        self.assertNotEqual(before["chunkSetId"], after["chunkSetId"])

    @case("architecture.version-generation")
    def test_reembed_changes_only_generation(self):
        states = self.states()
        before, after = states["baseline"], states["re-embed"]
        for axis in AXES[:-1]:
            self.assertEqual(before[axis], after[axis])
        self.assertNotEqual(before["generationId"], after["generationId"])

    @case("architecture.immutable-citation")
    def test_historical_citation_resolves_exact_bytes_or_refuses(self):
        fixture = CORPUS["version-axes"]
        citation = fixture["citation"]
        for field in ("tenantId", "sourceId", "sourceVersionId", "documentRevisionId",
                      "documentId", "segmentId", "blockId"):
            self.assertRegex(citation[field], r"^[0-7][0-9A-HJKMNP-TV-Z]{25}$")
        self.assertEqual(resolve_exact(citation, fixture["revisions"]), citation["expectedText"])
        original = next(s for s in fixture["sources"]
                        if s["sourceVersionId"] == citation["sourceVersionId"])
        self.assertEqual(citation["sourceDigest"], original["sourceDigest"])
        self.assertNotEqual(citation["sourceVersionId"], fixture["latest"]["sourceVersionId"])
        newer_only = fixture["revisions"][2:]
        with self.assertRaisesRegex(ValueError, "missing_exact_revision"):
            resolve_exact(citation, newer_only)
        corrupted = copy.deepcopy(fixture["revisions"])
        corrupted[0]["irPayload"]["canonicalText"] = "Forged index snippet"
        with self.assertRaisesRegex(ValueError, "canonical_digest_mismatch"):
            resolve_exact(citation, corrupted)

    def trace(self, identity):
        fixture = CORPUS["lifecycle-window"]
        self.assertEqual(fixture["lockOrder"], ["tenant", "kb", "source", "generation", "job"])
        self.assertEqual(fixture["transactionIsolation"], "READ COMMITTED")
        trace = next(trace for trace in fixture["traces"] if trace["id"] == identity)
        actual = evaluate_trace(trace)
        self.assertEqual(actual, trace["expected"])
        return actual

    @case("architecture.local-revoke")
    def test_revoke_after_selection_blocks_disclosure_without_projection_cleanup(self):
        outcome = self.trace("local-revoke")
        self.assertEqual(outcome["outcome"], "denied")
        self.assertFalse(outcome["textReleased"])

    @case("architecture.disclosure-before-revoke")
    def test_later_revoke_does_not_retract_confirmed_bounded_buffer(self):
        outcome = self.trace("disclosure-before-revoke")
        self.assertTrue(outcome["usageCommitted"])
        self.assertTrue(outcome["textReleased"])

    @case("architecture.unseen-source-revoke")
    def test_tombstone_before_registration_blocks_unseen_source(self):
        self.assertEqual(self.trace("unseen-source-revoke")["outcome"], "denied")

    @case("architecture.external-owner-window")
    def test_remote_change_within_valid_receipt_window_is_explicit(self):
        outcome = self.trace("external-owner-window")
        self.assertTrue(outcome["externalWindow"])
        fixture = CORPUS["lifecycle-window"]
        trace = next(t for t in fixture["traces"] if t["id"] == "external-owner-window")
        self.assertIn("no distributed atomicity", trace["guarantee"])
        self.assertTrue(fixture["modelOnly"])

    @case("architecture.external-owner-unknown")
    def test_unknown_expired_or_uncontracted_owner_cannot_disclose(self):
        for identity in ("owner-unknown", "owner-expired", "owner-no-contract"):
            with self.subTest(trace=identity):
                outcome = self.trace(identity)
                self.assertEqual(outcome["outcome"], "unknown")
                self.assertFalse(outcome["textReleased"])
        self.assertEqual(self.trace("owner-denied")["outcome"], "denied")

    @case("architecture.disclosure-replay-uncertainty")
    def test_uncertain_commit_and_replay_never_release_new_plaintext(self):
        uncertain, replay = self.trace("uncertain-commit"), self.trace("attempt-replay")
        self.assertEqual(uncertain["outcome"], "unknown")
        self.assertFalse(uncertain["textReleased"])
        self.assertTrue(replay["metadataOnly"])
        self.assertFalse(replay["textReleased"])


if __name__ == "__main__":
    raise SystemExit(main(sys.modules[__name__]))
