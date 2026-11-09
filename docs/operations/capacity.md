# Capacity profile v1

P9.4 measures the fixed workload in [capacity.v1.json](../../deploy/profiles/capacity.v1.json).
The versioned fixture manifest is
[ops-performance.json](../../tests/fixtures/check-groups/ops-performance.json).
Measured observations are kept separately in
[capacity-observed.v1.json](../../deploy/profiles/capacity-observed.v1.json).
Measurements on the current shared development host do not qualify the dedicated
4 vCPU / 8 GiB / local SSD reference machine. `referenceQualified` must remain
false until that environment and every required profile have evidence. A failed
threshold remains a failed qualification; workload size and percentiles do not
change to obtain a passing result.

Observed run: PHP 8.4.1, PostgreSQL 16.14 for the reference lexical workload,
28 host logical CPUs and 33,395,838,976 host memory bytes. This is a shared host;
these resources are not a dedicated reference allocation. Full fingerprints,
PostgreSQL/pgvector versions and report digests are in the observation artifact.

| Measured operation | p50 ms | p95 ms | p99 ms |
| --- | ---: | ---: | ---: |
| 4 MiB TXT document, sixteen sequential inputs | 217.16 | 246.45 | 246.45 |
| Lexical, warm, 20 clients / 100 samples | 198.04 | 251.13 | 260.49 |
| Hybrid, pinned small-vector profile / 20 samples | 195.66 | 205.67 | 206.79 |
| Bounded disclosure, warm / 19 samples | 32.09 | 38.20 | 38.20 |
| Hot-tenant graph writes / 64 samples | 7.20 | 10.14 | 14.18 |
| Twenty-tenant graph writes / 320 samples | 5.79 | 9.20 | 11.28 |
| Healthy outbox lag / 64 events | 140.90 | 196.18 | 203.29 |
| Exact local purge / 32 resources | 7.48 | 9.58 | 10.05 |
| OCR, complete sandbox job / 3 one-page inputs | 19081.45 | 19345.93 | 19345.93 |

The TXT batch peak was 54,116,352 bytes (51.6 MiB); the oversized-input rejection
peak was 95,772,672 bytes (91.3 MiB). OCR cgroup peak was 958,844,928 bytes
(914.4 MiB), with 37,789.685 ms total worker CPU. Both outbox and purge backlogs
drained to zero. Graph guard wait p95 was 7.71 ms for the hot tenant and 0.98 ms
for twenty tenants. The separate 4,096-source permission-prefilter sample is
reported without a latency SLO or a population tail claim. The immutable manifest
cache reduced measured warm lexical p95 from 1,352.99 ms to 251.13 ms with the
same positive workload and unchanged 500 ms target.

The operating envelope preserves existing contracts:

| Work | Bound and admission rule |
| --- | --- |
| TXT | One document at a time, at most 4 MiB each. Batch: 16 documents, 64 MiB total. Peak process RSS/allocated PHP memory must stay within 256 MiB, including extraction and immutable original storage. |
| SourceBytes | Absolute original-input ceiling 64 MiB. TXT above 4 MiB is rejected without truncation; SourceBytes above 64 MiB is rejected before digest validation. These rejection paths also stay below 256 MiB. |
| Search | Reference corpus: 10,000 source manifests / 100,000 chunks, alternating RU/EN; 20 independent clients, compact top-10, 64 allowed source versions per client. Five warm requests follow each client's first request. |
| Permission prefilter | Separate maximal 4,096-version grant over the same corpus. A 4,097-version grant is rejected by the existing QueryGrant schema. Empty grants return no candidates. |
| Hybrid | Real PostgreSQL/pgvector, pinned synthetic vectors, three indexed sources and two allowed sources. This adapter measurement does not qualify reference-scale semantic throughput or external model latency. |
| Graph | Four independent writers in one hot tenant; comparison: twenty independent tenants. Each writer registers sixteen exact source nodes. Tenant guards and ancestor checks remain active. |
| OCR | Three sequential real scanned-PDF jobs, 2 GiB per worker, 500 pages, 2,000 assets maximum. Existing tenant admission remains at two workers. A real one-byte cgroup budget must yield unavailable/worker_oom. |
| Outbox | Explicitly launched loop, batch at most 64; measure durable local inbox delivery lag and replay every event once. Duplicate delivery must not repeat its effect. |
| Purge | Explicitly launched loop; measure 32 real immutable local objects through durable deletion attempts and fsync receipts. No remote-provider deletion guarantee is inferred. |

The TXT interpretation follows owner answer Q1: 64 MiB is aggregate valid TXT
volume, not permission to widen the single-document parser profile. The ingest
measurement includes registration, original blob fsync, version acceptance and
IR extraction. It excludes publication/index throughput. Reindex independently
replays a building generation from two processes and rejects mutation after seal.
Object-store outage returns unavailable without committed usage; a committed
revoke returns denied without disclosure. Historical immutable identities remain
unchanged.

Run an individual workload with `php tools/load/run.php <case>`; case names are
`catalog`, `limits`, `lexical`, `hybrid`, `disclosure`, `reindex`, `hot-tenant`,
`many-tenants`, `outbox`, `purge`, and `ocr`. Every case writes its JSON report
under `.phpunit.cache/P9.4/`. SQL corpus loading is fixture setup, excluded from
search latency and catalog-ingest measurements; its bounded loader uses a separate
1 GiB PHP ceiling. Kernel worker cgroups enforce the OCR budget. No backend is
substituted when PostgreSQL, pgvector, OCR runtime or cgroup delegation is missing.
Connection credentials and runtime paths come from the existing test environment
variables, never tracked profile files. Tests use disposable `*_test` databases
and randomly isolated schemas with an unprivileged runtime role.

For a single test file use, for example,
`vendor/bin/phpunit --configuration phpunit.xml tests/ops/performance/CatalogTest.php`.
The coordinator's group runner uses `composer qa -- ops:performance`; required
case IDs must all execute without failures or skips. The fixture tests exercise
missing, skipped, failed, duplicate, empty, unknown-group and nested-error
failures. Required-case checks are independent of capacity qualification:
`php tools/load/qualify.php` exits nonzero for missing reports, failed SLOs,
unqualified hardware or the unqualified reference-scale hybrid profile.
`php tools/load/summarize.php` exports only a complete set of reports whose
fingerprints still match the candidate tree.

Reports contain p50/p95/p99 using nearest rank, sample counts, process/worker
memory, measured CPU time, graph guard wait, outbox lag, backlog and digest refs.
Cold means the first request in a fresh process/connection; OS and PostgreSQL
caches are not flushed. One-sample large-prefilter and three-sample OCR tails
are descriptive observations, not population estimates. Monetary cost is unknown
without an operator tariff; local provider calls and tokens are zero. PostgreSQL
server CPU/memory attribution is not instrumented and must not be inferred from
PHP process counters. Reports bind the corpus/configuration and loaded production
PHP files, dependency lock, PostgreSQL/pgvector versions and OCR runtime BOM.

Full-scale loading exposed linear tenant scans in the existing exact block and
snapshot-member predicates. Additive migration 012 adds two non-unique indexes
for those same predicates; existing migrations, tenant predicates, immutable
identities and validation functions retain their semantics. The workload runs
positive indexed query plans and negative forced-linear counterfactuals against
the full corpus. Fixture v1.2.0 records the rationale; golden values are not
rewritten from engine responses. Source reuse decisions are recorded in
[P9.4-reuse.json](../../tests/ops/performance/evidence/P9.4-reuse.json).
The lexical manifest reader also uses the existing schema-bound 16 MiB limit
instead of the generic JSON decoder's 1 MiB default. Full-scale search proves a
valid manifest above 1 MiB; a negative boundary test still rejects above 16 MiB.
Migration 013 removes a redundant legacy 4 MiB database guard while retaining
the existing 16 MiB guard, bringing persistence into agreement with that same
wire contract. SQL boundary tests cover both acceptance above 4 MiB and rejection
above 16 MiB. The explicit migration set now contains thirteen entries.
The lexical adapter retains one validated immutable manifest per instance, keyed
by the current stored bytes SHA-256 and declared digest. Each request still reads
the authoritative scoped snapshot/generation. Fresh lifecycle/access checks before
disclosure remain mandatory. Changed bytes/digest cannot reuse validation;
committed revocation still prevents disclosure after a cache hit. This cache stores no search or authorization
result and replaces its previous entry rather than growing per tenant.
