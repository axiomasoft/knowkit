# Development environment

## PHP package bootstrap and verification

The mandatory library is `knowkit/core`, PHP >=8.3, namespace `KnowKit\`.
Its `Domain`, `Application`, `Port`, `Registry` and singular `Adapter` layers live
in `packages/core/src`. It requires only PHP and ext-pdo/json/mbstring;
the PostgreSQL adapter additionally requires ext-pdo_pgsql. The root Composer
project is development tooling. Optional answer, pgvector, HTTP, Laravel,
context-compilers, mcp-bridge, distill, training-export, Chatom and AgenTools
bridge Composer projects are implemented under `packages/`, with
their own dependency graphs and lockfiles. Laravel targets version 12; the
separate pinned Chatom host targets Laravel 13. Do not add their host dependencies
to the root or core. Chatom and bridge lanes require PHP >=8.4.

Install PHP 8.3, 8.4 or 8.5, Composer 2, ext-pdo/pdo_pgsql/json/mbstring/dom/xml/
xmlwriter, Python 3.12 for architecture contract checks, and Docker with Compose.
Run from the checkout; no model keys, Python worker, Redis, GPU or framework
installation is required for the core bootstrap:

```sh
composer install --no-interaction --prefer-dist
composer validate --strict
composer validate --strict --working-dir=packages/core
vendor/bin/phpstan analyse --configuration phpstan.neon --no-progress
php tools/qa.php --check dag
php tools/qa.php --check config
docker compose -p knowkit-test up -d --wait postgres
composer qa -- tooling:baseline
composer qa -- --profile local-developed --manifest tests/tooling/baseline/profile.json
docker compose -p knowkit-test down --volumes --remove-orphans
```

The test database is PostgreSQL 17 by default. It uses tmpfs and loopback-only
port 55432 with trust authentication strictly for this disposable local database;
no password is tracked. Do not use this Compose configuration for a deployed
database. Each integration assertion creates a random `knowkit_qa_*` schema in
an explicit `*_test` database, resets only that schema, and drops it in `finally`,
including after exceptions and open transactions. Other schemas/tables are
preserved. An unavailable mandatory backend is a failing test, never a skip.
Compose teardown removes the server and all its data. `.env.example` lists
portable settings. Compose reads `.env`; PHP does not load it automatically:
export matching `TEST_PG_DSN`, `TEST_PG_USER` and, if needed, `TEST_PG_PASSWORD`
in the invoking shell. Keep any local credentials out of tracked files.

Override `TEST_PG_PORT` and the exported DSN together when the port is occupied.
Override `TEST_NETWORK_SUBNET` with an unused private subnet when it overlaps a
local network. Explicit IPAM also supports hosts whose default Docker address
pool is exhausted. Use a distinct Compose project, port and subnet for concurrent
runs. CI qualifies PHP 8.3/8.4/8.5 against PostgreSQL 17. To start
the separate optional vector backend, run
`docker compose -p knowkit-test --profile vector up -d --wait pgvector`.
It listens on loopback port 55433 and uses `pgvector/pgvector:pg17`; the pgvector package and semantic tests provide extension and adapter qualification.
Override `TEST_VECTOR_PORT`, `TEST_VECTOR_IMAGE`, and the exported `TEST_VECTOR_PG_DSN` together. `TEST_PG_TMPFS` and `TEST_VECTOR_TMPFS` default to `/var/lib/postgresql/data`. PostgreSQL 18+ images store data under `/var/lib/postgresql`, so set `TEST_PG_TMPFS=/var/lib/postgresql` with the image tag. The default mount stays a tmpfs entry, not a volume.
These image tags receive upstream maintenance updates; exact resolved revisions
are reported with `docker image inspect postgres:17 pgvector/pgvector:pg17`.

`tools/qa-groups.json` explicitly maps the entire roadmap to real commands,
Composer projects, PHP floors and required-case manifests. The runner uses argv,
not a shell, creates private temporary JUnit files, checks child exit codes,
nonzero executed counts and every required case ID, and rejects skipped, missing,
duplicate or failing cases. Its JSON report binds executed IDs to actual
PHPUnit/pytest case names. Fixture digests are versioned authored expectations;
change their version and rationale when deliberately changing expected behavior.
The bootstrap IDs `BOOTSTRAP-R42-A01/A02` prove installation/discovery only;
product and adapter conformance audits R42-A01/A02 remain required at P9.5.

`composer qa -- all` is deliberately strict across the complete registry,
including the optional installed bridge. It also runs Composer strict validation,
PHPStan, the package/import DAG validator and portable configuration lint. It requires every selected suite, backend, runtime and installed host to be
available. Source implementation alone does not satisfy these prerequisites.
The commands above retain the original bootstrap acceptance scope. `--profile core-php83` explicitly selects
all root Core groups plus architecture and reports the other groups
`NOT_SELECTED/PENDING`; it requires all selected Core suites and their backend prerequisites.
`--profile local-developed --manifest <repository-relative JSON file>` requires
`schemaVersion: qa-profile:1`, explicit `selected` completed group IDs and
`notSelected` covering every remaining registry ID exactly once. The checked-in
bootstrap profile selects only P1.1/P1.2. No profile permits skipping a required
case inside a selected group. Standalone delivery readiness has no installed
Agentools bridge prerequisite; unselected bridge groups remain pending.

Named PHPUnit suites isolate Core/Answer/Chatom/Bridge/Semantic/Worker/Evaluation/
Ops and the additional Laravel/Http/installed-host lanes. Core discovery does
not load optional test classes or optional Composer autoloaders. The runner
selects each group's declared install and executes it with the root phpunit.xml
from the repository root. Missing suites, tests or vendor installs are failures.
Worker manifests, `pyproject.toml`, `uv.lock` and real tests are implemented.
Install the locked Python 3.12 runtime with
`uv sync --project python/knowkit-worker --frozen`. PDF/OCR tests additionally
require their qualified local model/native assets; see the worker guides below. Declared worker checks then run locked `uv run --project
python/knowkit-worker --frozen pytest ... --strict-markers -q`; pytest exit 5
remains a failure. No successful worker/adapter placeholders are provided.

To diagnose one bootstrap component, execute one test file, for example
`vendor/bin/phpunit --configuration phpunit.xml tests/tooling/baseline/RunnerTest.php`.
All new dependencies are locked in composer.lock, resolved with PHP8.3.0 as
Composer's platform floor. Optional package manifests and lockfiles live under their respective package directories;
install only the projects needed for the selected lanes.

## Continuous integration and coverage

Workflows live in `.github/workflows`. They verify the checkout; they do not
publish releases. The verification workflow runs the core matrix on PHP 8.3,
8.4 and 8.5 with PostgreSQL 17, the optional profile on PHP 8.3, 8.4
and 8.5, core coverage, a locked root `composer audit`, the offline license
audit, and the Python protocol tests. CodeQL, dependency review, Dependabot
and OpenSSF Scorecard are separate workflows. Actions are pinned to commit
SHAs. Report vulnerabilities through GitHub private security advisories, as
described in `SECURITY.md`.

`--profile ci-core` selects all 36 portable Core/architecture groups. It excludes
`delivery:readiness`, whose cross-version host and real worker qualifications need
additional installations. The historical `tests/tooling/baseline/profile.json`
continues to describe only the original bootstrap scope. Core CI runs that
profile once per PHP version. It does not repeat the suite with `ext-zstd`
loaded: the zstd readiness case proves the extension is absent and fails closed,
and PHPUnit rejects a skip.

`--profile ci-optional` selects 20 Answer, pgvector, HTTP, Laravel, adoption,
evaluation, observability, context-compiler, MCP, distill and training-export
groups. Install those package Composer projects first and set `TEST_VECTOR_PG_DSN`
for the vector backend. Chatom and the installed AgenTools bridge are not in
this profile. Their checkouts are outside this repository, so a missing sibling
must not block the portable profile.

Both profiles explicitly list all unselected groups and retain the common strict
Composer, PHPStan, package DAG and configuration checks. CI reports are uploaded
even when a selected group fails; child failure diagnostics retain a bounded tail
of both stdout and stderr. Superseded CI runs are cancelled.

CI separately runs the Python protocol/regression tests without model assets.
PDF/OCR/structure, installed AgenTools, pinned Chatom hosts, full delivery,
resilience and capacity qualifications remain explicit environment-dependent
checks; a green portable CI does not qualify those lanes.

Core coverage is collected in one PHPUnit process rather than re-running each
group with a coverage driver. The source filter includes all Core production
files, including unexecuted ones. With PCOV enabled or `XDEBUG_MODE=coverage`:

```sh
vendor/bin/phpunit --testsuite Core --exclude-group delivery:readiness \
  --coverage-clover coverage/clover.xml --coverage-text=coverage/summary.txt
```

The CI coverage job uploads both reports. This measures Core line coverage;
optional packages, Python and subprocess execution need separate coverage
collection. No percentage threshold is claimed for those unmeasured paths.
See [PHPUnit's coverage documentation](https://docs.phpunit.de/en/11.5/code-coverage.html)
for driver and source-filter semantics.

## Current worker and targeted verification

The worker implements exact Docling text and structure profiles plus separate
Tesseract English/Russian OCR profiles. Read [PDF setup](../python/knowkit-worker/PDF.md),
[OCR setup](../python/knowkit-worker/OCR.md) and [sandbox runtime setup](../deploy/worker/README.md)
for explicit asset builds. No model download occurs during a job.

```sh
uv run --project python/knowkit-worker --frozen pytest python/knowkit-worker/tests -q
python3 -m compileall -q python/knowkit-worker/src deploy
php -l tools/license-audit.php
php tools/license-audit.php
vendor/bin/phpunit --configuration phpunit.xml tests/evaluation/MetricsTest.php
```

Pytest uses importlib mode so PDF, OCR and structure test modules can be collected
together. The default test path remains the protocol suite for compatibility.
The full suite includes real model extraction and a bubblewrap/network isolation
test: missing assets or prohibited namespace/socket operations fail, never skip.
Local Level 8 PHPStan checks can use `--debug --memory-limit=1G` where sandbox
restrictions prevent PHPStan's parallel TCP listener. The root PHPStan config
checks Core, its conformance SDK, pgvector and bootstrap tooling at Level 8.
Optional CI checks Answer, pgvector, HTTP and Laravel separately at Level 8 with
`phpstan.optional.neon` and each package's own autoloader. The shared exclusions
may be unmatched in a package subset; other analysis errors still fail. Chatom
requires its pinned host installation and Bridge retains its separate Level 5
configuration. Python is outside PHPStan's scope.
