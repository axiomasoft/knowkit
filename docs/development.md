# Development environment

## PHP package bootstrap and verification

The mandatory library is `knowkit/core`, PHP >=8.3, namespace `KnowKit\`.
Its `Domain`, `Application`, `Port`, `Registry` and singular `Adapter` layers live
in `packages/core/src`. It requires only PHP and ext-pdo/json/mbstring;
the PostgreSQL adapter additionally requires ext-pdo_pgsql. The root Composer
project is development tooling. Optional answer, pgvector, HTTP, Laravel, Chatom
and AgenTools bridge Composer projects are implemented under `packages/`, with
their own dependency graphs and lockfiles. Laravel targets version 12; the
separate pinned Chatom host targets Laravel 13. Do not add their host dependencies
to the root or core. Chatom and bridge lanes require PHP >=8.4.

Install PHP 8.3, 8.4 or 8.5, Composer 2, ext-pdo/pdo_pgsql/json/mbstring/dom/xml/
xmlwriter, Python 3.12 for architecture contract checks, and Docker with Compose.
Run from the checkout; no ecosystem agent tooling, model keys, Python worker,
Redis, GPU or framework installation is required for the core bootstrap:

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

The test database is PostgreSQL 16 by default. It uses tmpfs and loopback-only
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
runs. PostgreSQL 17 qualification uses
`TEST_POSTGRES_IMAGE=postgres:17 docker compose -p knowkit-test up -d --wait postgres`
on a fresh project. CI qualifies PHP8.3/8.4/8.5 against PostgreSQL16/17. To start
the separate optional vector backend, run
`docker compose -p knowkit-test --profile vector up -d --wait pgvector`.
It listens on loopback port 55433 and uses `pgvector/pgvector:pg16`; the pgvector package and semantic tests provide extension and adapter qualification.
These image tags receive upstream maintenance updates; exact resolved revisions
are reported with `docker image inspect postgres:16 pgvector/pgvector:pg16`.

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
continues to target bootstrap tooling at its declared level.

## Local ecosystem setup

KnowKit uses the same local ecosystem as AgenTools: workspace `axioma`,
marketplace delivery through Swiss Knife Man, scoped mAInd MCP, Serena, and
Perplexity MCP. Project memory is scoped to `project:knowkit`; topology namespaces
are `axioma` and `knowkit`. Shared memory access is not enabled.

The selected Swiss Knife Man plugins are `core`, `oss-dev`, and `planner` for
Claude Code and Codex. The initial empty-repository setup selected no language-specific stack or agent
pack. That is a historical setup choice, not the current application architecture.
Serena was provisioned without language servers; configure PHP/Python support
when enabling semantic navigation in a local environment.

The tracked `.swissknifeman/project.json` identifies this project. The local
`plans/` and `tasks/` symlinks point into the workspace and are excluded through
`.git/info/exclude`. Machine settings live in the workspace project's
`local/config.toml`; `.swissknifeman/config.example.toml` records their portable
bootstrap values. MCP configurations with machine paths remain ignored;
`.mcp.json.example` documents their shape.

The local Claude Code permission mode and auto-approval mode match AgenTools.
Installed hook files are supplied by the local harness installation.

Run these commands from the checkout to inspect or refresh the environment:

```sh
skiller status --json
skiller budget --first-turn
harness doctor
maind health --cwd
swissknifeman status knowkit
maind sync --project knowkit --only mcp,ecosystem-skill,setup-skill,gitignore
```

Setup verification on 2026-11-05 confirmed all three MCP servers initialize and
list their tools: mAInd (11), Serena (21), and Perplexity (4). A scoped topology
graph request for KnowKit succeeded. Codex's offline prompt inspection loaded
the project instructions and the three selected plugins. Repeated MCP sync
reported no pending changes. The memory SessionStart hook exited successfully
when the shared daemon was unavailable.

When changing the Codex native plugin selection, run
`skiller connect --plugins core,oss-dev,planner --native-codex-only` followed by
`maind sync --project knowkit --only mcp --mode repair`. The native selection
writer reformats the MCP block; the repair restores the owning renderer's format
while preserving the plugin selection.

## Infrastructure limitation observed during setup

On 2026-11-05, the shared mAInd memory daemon was unavailable for AgenTools and
KnowKit. `maind start --only memory` refuses to start the existing LAN listener
because it has neither `AGENTMEMORY_SECRET` nor `memory.secret_file`. Starting
memory requires repairing the shared host's authentication configuration; this
bootstrap does not change shared credentials or network binding. Until repaired,
`maind health --cwd` reports `partial`, and persistent memory calls are unavailable.
Repository work, planning files, and the topology graph remain usable.

## Installer observations

The 2026-11-05 onboarding run selected all buckets for this empty repository even
though the onboarding guide describes a minimal fallback. The project now has an
explicit plugin selection, and surplus Claude Code entries are disabled.

The first onboarding pass also reported deployment while provider preflight
refused the initial Context7-only `.mcp.json`. The generated initial files were
archived under the workspace project's ignored local backups, then MCP was
provisioned from an absent target. Subsequent native Codex selection was followed
by the owning MCP repair. These are follow-up items for the skiller integration
and maind deployment modules; no library source was changed during project setup.
