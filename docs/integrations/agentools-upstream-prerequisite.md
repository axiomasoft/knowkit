# Agentools evidence-context delivery prerequisite

Upstream seam status: **pending**. P8.4 delivers
[KAT-EVIDENCE-CONTEXT-1](../../contracts/agentools/evidence-context/change-request.v1.json),
the [exact contract](../../specs/integrations/agentools-evidence-context.md),
versioned dev fixtures and read-only qualification tooling. It does not claim
Agentools merge, installation, real host integration or v0.6 readiness.

The owning Agentools task is **KAT-EVIDENCE-CONTEXT-1**. No upstream PR URL or
merged commit has been supplied; the pin matrix deliberately records null values.
The Agentools owner must deliver a separately authorized task/PR in that repository:

1. Accept the exact contributor/current-source interfaces, immutable DTOs and
   optional constructor additions; retain native host/profile/history guards.
2. Implement native compiler, SnapshotPins, retention/hash and saved-context
   bindings; invoke current-source checks at every required boundary, including
   after durable intent and actual delivery. Keep owner IO outside transactions.
3. Prove empty-default native request layout/hash compatibility and real isolated
   owner/compiler/runtime/relay A01–A18 behavior; ship upstream docs and tests.
4. Return merged commit, exact package version, signature/schema digests, installed
   schema manifest path and source-file SHA-256 manifest. Update the versioned
   proposal and bridge pins explicitly if any accepted surface differs.

After delivery, install the exact pin and compile the production bridge adapter
against the real public FQNs. Run installed reflection/schema/hash parity and real
isolated owner/compiler/runtime/relay tests before P8.2 runtime or the v0.6 bridge
gate can pass. Fixtures never replace a missing Composer production dependency;
their namespace exists only in `autoload-dev`, with
`EffectiveProfile.environment=test`, `production=false`, `fixture=true`.

Run `php packages/agentools-bridge/bin/probe-evidence-context.php` to inspect the
installed driver without mutating repositories or making provider calls. Missing
merge, missing installed package or wrong surface/hash returns exit 2 and
`upstream_seam=unavailable`, never a successful skip. Probe success proves only an
installed public surface, not native runtime behavior; it always leaves
`runtimeQualified=false`. The scripted harness is a separate command:
`php tests/bridge/upstream-contract/run.php`.

If delivery is still missing during a future runtime execution, block only the
affected P8.2 runtime/host item with the probe evidence and owning Agentools
task/PR. Do not edit the neighboring Agentools/Edufy/Chatom repositories or add a
generic approval gate. This optional v0.6 dependency does not block core, answer,
worker, HTTP or direct-route milestones.

Rollback before adoption retains the empty default. After evidence-bearing saved
contexts exist, removing a contributor or authority must refuse those contexts.
Never relabel replayed unguarded text as grounded, silently substitute latest
sources or recompute evidence during a retry. Requalification and an explicit
new invocation/snapshot are required for a changed pin.

The local reuse record is
[P8.4-reuse.json](../../tests/bridge/upstream-contract/evidence/P8.4-reuse.json).
Its plan-artifact copy belongs to the coordinator because this executor cannot
mutate plan state. No upstream publication or external repository change is part
of this delivery.
