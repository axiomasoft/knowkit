# Independent acceptance walkthrough

Use the fixed checkout, root Composer lock, optional HTTP/Laravel locks and frozen
worker lock. No AgenTools installation or P8 output is required. There is no
publication or live deployment in this walkthrough.

1. Install root development tooling with Composer and optional package projects
   separately (`composer install --working-dir=packages/http` and
   `composer install --working-dir=packages/laravel`, using their locks). Do not
   merge optional host requirements into root/core. Prepare the qualified OCR
   runtime and sandbox using the worker build instructions; no mock fallback.
2. Start disposable PostgreSQL 16/17/18 using
   `docker compose -p knowkit-delivery -f deploy/profiles/chatom/compose.yaml up -d --wait`.
   These images are digest-pinned; their trust authentication/loopback ports and
   tmpfs are test-only. Set `CHATOM_TEST_PG16_PORT`, `CHATOM_TEST_PG17_PORT`,
   `CHATOM_TEST_PG18_PORT` to unused ports if needed. The reused infrastructure
   does not select Chatom or AgenTools. Override the matching
   `KNOWKIT_DELIVERY_PG16_DSN`/`PG17_DSN`/`PG18_DSN` and `_USER`/`_PASSWORD` as
   needed. Each DB name must end in `_test`. `TEST_PG_DSN` separately selects
   the operations/worker/service lane (default loopback port 55432).
3. Have PHP 8.3/8.4/8.5 CLI executables available. Set host-owned OCR runtime,
   staging and delegated cgroup configuration; missing required backend is a
   failure. Never reuse a production schema for these tests.
4. The coordinator runs `composer qa -- delivery:readiness`. The versioned
   required-case manifest maps every ID to actual tests; missing/skipped/empty
   cases or nested failures return nonzero. JUnit stays in runner-private temp
   storage. The profile report is explicitly `agentoolsBridge: NOT_SELECTED/PENDING`;
   that is not bridge PASS. Do not replace this command with `qa all`.
5. Inspect executed case IDs and lane outputs, the local artifact inventory,
   notices and SBOM. Clean-install removes the artifact's original core source
   after Composer mirrors it, then runs TXT ingestion/search/exact citation and
   revoke with no Python/framework executable path. Service uses installed
   Laravel/HTTP and real core commands with synthetic host authentication, no
   worker. Full-worker uses real sandboxed OCR through persistence/publication,
   exact source/IR citation/read and revoke. Negative cases exercise wrong adapter,
   missing journal tail, unsupported purge and the external-owner race boundary.
6. Tear down only the dedicated disposable Compose project with
   `docker compose -p knowkit-delivery -f deploy/profiles/chatom/compose.yaml down --volumes`.

Core TXT lanes qualify each of the nine PHP/PostgreSQL combinations. Optional
service/worker scenarios qualify the invoking lane only. Versioned expected
digests come from the existing immutable synthetic TXT and qualified OCR fixtures;
changing them requires a new fixture version and semantic rationale. Metadata-only
donor pins preserve exact commits/SHA-256 without vendoring full donor source.
No provider implementation is qualified by a contract double. No universal latency,
reference capacity, production auth, global atomicity, bridge conformance or full
59-item roadmap completion follows from this local acceptance.
