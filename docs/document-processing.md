# Document processing walkthrough

The executable example is `php examples/worker/walkthrough.php`. It uses the
project-authored English scan, a disposable PostgreSQL schema, real LocalBlob
storage and the qualified `docling-pdf-ocr-eng-v1` subprocess. It prints identity,
coverage and resource metadata; document text and provider inputs stay out of
logs. The schema and private objects are removed when the example exits.

Install the repository's PHP test dependencies and Python 3.12 environment first.
PostgreSQL 16/17 must be reachable through the existing test configuration
(`TEST_PG_DSN`, `TEST_PG_USER`, `TEST_PG_PASSWORD`); the database name must end in
`_test`. Follow [PDF runtime setup](../python/knowkit-worker/PDF.md) and
[OCR runtime qualification](../python/knowkit-worker/OCR.md) to prepare pinned
models and the runtime BOM. Runtime preparation is a controlled build step.
The test environment's Python executables must be physical copies inside `.venv`
as required by the QA runner. No model download occurs during processing.

Linux, nonroot execution, bubblewrap, user namespaces, seccomp and delegated
cgroup v2 `cpu`, `memory`, `pids` controllers are mandatory. Set
`KNOWKIT_TEST_CGROUP_PARENT` if automatic delegation discovery is inappropriate.
`KNOWKIT_OCR_RUNTIME` selects the prepared local runtime. An absent required
backend or sandbox causes failure; the walkthrough never skips or runs an
unsandboxed fallback. The host access-policy receipt issuer is a test double;
the catalog, resource ledger, jobs, indexing and disclosure adapters are real.

## What the example executes

1. Reserve and upload immutable PDF bytes; register their exact source version
   and enqueue a processing identity binding parser, OCR, chunker and index.
2. Lease the job and reserve its private resource writer. The production PHP
   supervisor verifies the runtime BOM, creates cgroup limits and launches the
   fixed worker argv in bubblewrap. Only private input/output and the qualified
   runtime enter that namespace. The host renews the lease through the fenced
   queue heartbeat on the first guard and every 15 seconds during worker polls.
   Expired leases and old fences cannot be renewed; current access/lifecycle
   checks still run on every guard. The original envelope expiry and the
   supervisor/worker wall, CPU and resource bounds remain enforced.
3. Negotiate the exact parser profile, execute `job-envelope-1.0` and accept only
   exit zero plus a terminal `worker-result-1.0`. PHP independently checks IR,
   every asset, digests, lengths, coverage, immutable identity and current fence.
4. Import immutable artifacts and checkpoint extraction. Build structural chunks
   from canonical spans, persist document/chunk metadata and checkpoint chunking.
5. Stage a manifest, persist the lexical projection and probe complete backend
   memberships/resources before sealing. Checkpoint indexing. Publish through a
   separate policy/lifecycle/fence transaction, then complete the ingest job.
6. Search the published snapshot using PostgreSQL FTS. Selection contains refs;
   disclosure freshly reloads canonical bytes, rechecks lifecycle/access and
   commits Usage lineage before returning evidence.
7. Resolve the issued citation to its exact version, IR, document and spans.
   Fetch the page-render PNG through protected asset reading with another fresh
   check and committed Usage. A preview is returned as bounded bytes, not a URL.

## Crash, replay and coverage assertions

`tests/fixtures/check-groups/worker-e2e.json` lists mandatory case IDs and maps
them to executed PHPUnit/pytest identities. The existing runner rejects missing,
skipped, failed, duplicate and zero executions. Fixture hashes and provenance
are in `tests/worker/e2e/fixtures/v1/manifest.json`. Changing expected bytes
requires a new version and a semantic rationale; do not refresh snapshots merely
to obtain a green test.

`FailureTest.php` kills separate PHP processes with SIGKILL at barriers before
upload, after accepted upload, before checkpoint acceptance and after checkpoint.
Its host-boundary cases share a private extraction fixture cache created empty
for that test class and removed afterward. Setup populates it through the real
qualified sandbox before the leased crash cases begin. This bounded qualification
envelope has a separate synthetic job ID, no catalog job capability and a
600-second dispatch window; the same 55-second worker wall and other resource
limits remain enforced. Assertions verify that preparation creates no job
checkpoint or publication. Each hit reopens the original job/manifest/IR/assets through
independent ingress validation and reuses `ExtractionCacheEntry` with the full
processing identity, tenant/source binding, revision, epoch and limits. Only
attempt fields in the result envelope are rebound; canonical IR/assets stay
immutable. Corrupt or incompatible entries fail instead of being regenerated.
Cold producer unavailability fails the test. This avoids repeating the same OCR
qualification for every host upload/publication crash and keeps the fixed
300-second check budget useful. Walkthrough, parser-change and in-flight OCR
revocation still start fresh real workers.

Before a durable accepted checkpoint, retry re-enters extraction, including
verified fixture reuse; open writers remain uncertain until reconciliation.
After checkpoint, a new fenced lease reloads and
hashes its immutable receipts. Fresh extraction and replay yield identical IR,
assets and structural chunk manifests; attempt/result fingerprints intentionally
include their distinct lease identity. Old leases cannot checkpoint, complete
or publish. Publication crashes before commit roll back pointer, snapshot and
outbox; crashes after commit replay the same receipt without another event.

Revoke is injected while the actual OCR `run` process is observable, after
negotiation and while Tesseract is processing a PNG. The supervisor stops/reaps
it and removes private stages; no
checkpoint or publication follows. Revocation after preview denies a new read
without rewriting historical evidence. Reprocessing under a different parser
creates a new revision and chunkset. An existing citation continues to resolve
its original IR and spans; urgent correction blocks that exact old version.
Corrupt worker manifests and altered checkpoint bytes cannot advance ingestion.

R16-A01/A02 use an explicitly synthetic parser-output oracle: 9 required pages
succeed and page 10 is `failed`, with a diagnostic and empty canonical text.
The Python native mapper also tests that a missing parser page stays failed.
This is fault injection, not a claim about Docling's recognition accuracy.
Without explicit partial policy, staging/publication fail and the pointer stays
unset. With explicit policy, the stored manifest stays partial and exact missing
page 10 remains visible; completeness admission excludes that source from real
FTS. R16-A03 publishes a separate complete source while preserving the failed
source's coverage/readiness independently from job and batch progress.

The network baseline invokes the qualified runtime inside its real unshared
namespace and seccomp filter and requires socket access to fail with `EPERM`.
Its failure is not waived when bubblewrap or the runtime is unavailable.

## Measured resources and quality limits

The first real walkthrough test writes metadata-only measurements to ignored
`python/knowkit-worker/.local-checks/e2e/resources.json`. It records wall time,
sampled cgroup CPU and memory peak, staging bytes, input/IR/asset sizes and chunk
count. Samples occur at supervisor polls; they may miss the final tail. Staging
measurements exclude the sandbox's private `/tmp` and filesystem overhead.
Child `getrusage` is reported separately and is not worker cgroup usage.

One measured v1.1 English-scan run took 36.040 seconds including BOM checks and
negotiation while other local checks ran concurrently: sampled cgroup memory
peak 883,937,280 bytes (about 843 MiB), CPU 13,002,944 microseconds, peak staged
files 288,497 bytes. The input was 141,361
bytes, canonical IR 66,317 bytes and the single PNG 78,810 bytes; one page
produced two chunks. These observations describe this local fixture run, not
performance requirements or an OCR quality score.

The E2E limit is 55 wall seconds, 45 CPU seconds, 2 GiB memory, 4 GiB temporary
space, 64 MiB output and 126 assets. The job lease is independently bounded.
These are limits, not measured consumption. The small one-page English scan
qualifies this fixture only; it gives no throughput or multi-document capacity
estimate. Recognition confidence and digest integrity do not prove correctness
or entailment. Language/model changes require a distinct qualified fingerprint.
No automatic OCR or parser fallback is permitted. Text-only reprocessing of a
scan can legitimately retain a preview with partial coverage and no chunks.
Tables, formulas, unusual layouts and other languages retain the limitations
documented by their specific qualified profiles.

Focused executable proofs are the individual files
`tests/worker/e2e/{Lifecycle,Failure,Fixture,ExtractionFixtureCache,LeaseHeartbeat}Test.php` and
`python/knowkit-worker/tests/e2e/test_lifecycle.py`. The coordinator owns the full
`worker:e2e` acceptance group and its required-case reconciliation.
