# Custom adapter fake host

This executable example wires the vendor-free core through static descriptors,
lazy factories and a validated immutable HostProfile. It uses synthetic in-memory
contract doubles. It does not claim a production storage backend, tokenizer model,
provider, framework integration or external project deployment.

From the repository root, with PHP >=8.3 and json/mbstring/pdo:

```sh
php tools/adapter-conformance.php --profile examples/custom-adapter/profile.json --contract all
php tools/adapter-conformance.php --profile examples/custom-adapter/profile.json --contract policy --factory examples/custom-adapter/negative-factory.php
```

The first emits a JSON report and exits 0. The deliberately broken policy rewrites
Unknown to Allow: the second reports failed `SDK-POLICY` and exits 1. `--report`
can additionally save that same safe JSON report. Unknown contracts or misspelled
adapter IDs fail explicitly. A trusted host PHP factory is supplied by the
developer; the profile cannot select executable paths.

`FakeHost.php` registers metadata without loading `ContractDoubles.php`.
Factories load implementations only when resolved; test corpus setup explicitly
loads reset doubles. `factory.php` is the positive host entry point. Tests also
exercise mutable blob storage, postfilter-only search, stale-fence acceptance,
misleading deletion, cache collisions, space mismatch, unguarded egress, duplicate
outbox, estimated token counts, retargeted parser identity and revoked parents.

To integrate a project, retain the [shared corpus contract](../../specs/10-customization.md),
implement `AdapterHost` with test instances of your actual adapters and wire its
controls/observations to a disposable backend. Run the same manifest through the
plain-PHP harness or an opt-in host PHPUnit/dev suite. Named project profiles in
`tests/fixtures/host-profiles` demonstrate reuse without Domain edits. Changing
processing/search versions, config, capabilities, quotas or package/weights
fingerprints produces a new profile identity and invalidates old approvals.

The UTF-8 codepoint tokenizer is a deterministic test algorithm, not a substitute
for an exact production model vocabulary. Real adapters require reviewed pinned
vectors; `--manifest` preserves all mandatory SDK assertions while supplying
versioned vector data for that identity. Credential presence is never permission
to send provider inputs, and a passing fixture report is not production readiness.
