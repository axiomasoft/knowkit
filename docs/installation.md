# Local installation and delivery

KnowKit core is a PHP >=8.3 library and CLI with no vendor runtime dependencies.
Install PDO, JSON and mbstring; select pdo_pgsql for PostgreSQL. Core supports
PostgreSQL 16/17/18 through explicit migrations. A particular release is qualified
only by executed lane results, never by the presence of this document.

From a fixed checkout and its lockfiles, create a reviewable local artifact:

```sh
composer install --no-interaction --prefer-dist --no-scripts --no-plugins
python3 deploy/assemble.py .local-delivery/core-v1
python3 deploy/verify.py .local-delivery/core-v1
```

The destination must be new. Assembly checks `deploy/licensing.v1.json` before
writing, includes package sources, migrations, schemas, synthetic example,
qualifications, SBOM and retained third-party notices. `artifact.json` inventories
every byte with SHA-256 and marks publication unauthorized. Repeating assembly
from unchanged inputs produces identical inventories. No registry release, tag,
download of models or startup operation occurs. The SBOM describes local tooling
and source-pattern reuse; it does not add those components to core runtime.
Keep the exact checkout/lock digests alongside the artifact and independently
verify its inventory before installation. Do not treat a digest as a signature.
The delivery catalog pins the current development lock and revalidates each
dependency/version/license/transitive edge against qualification records. The
historical P1.3 catalog remains frozen; its old lock pin is not used for delivery.

For an application, require `knowkit/core:dev-main` using an explicit Composer
path repository pointing at the artifact's `packages/core`, with `symlink:false`
and `versions:{"knowkit/core":"dev-main"}`. Disable Packagist for offline installs
with `repositories:[{"type":"path","url":"packages/core","options":{"symlink":false,
"versions":{"knowkit/core":"dev-main"}}},{"packagist.org":false}]`.
This local development version is bound by the artifact inventory, not a released
semantic version. Commit the consumer lock to its own release record. Core's
installed graph must contain only `knowkit/core`. The checkout root Composer
project supplies development tools and must not be the deployed runtime graph.

Create a dedicated empty schema using an administrator, then explicitly apply:

```sh
php packages/core/src/Adapter/Postgres/Catalog/migrate.php knowkit
```

Inject `KNOWKIT_PG_DSN`, `KNOWKIT_PG_USER`, `KNOWKIT_PG_PASSWORD` through a host
secret manager/process environment. Provision schema owner and restricted runtime
roles separately. Construction and Composer installation create no tables, routes,
workers or provider calls. The migrator uses an advisory transaction lock and
checksums the complete ordered migration set. Keep existing migration bytes.
Its legacy stdout names only the earliest migrations; query `schema_migrations`
to verify the actual complete set. Rollback procedures are in the runbook.

The application owns trusted identity, policy, blob root, schema loading and
adapter registration. `KNOWKIT_HOST_BOOTSTRAP` is a host-owned file returning a
CLI dispatcher Closure; never accept it from request/model input. Use
`vendor/bin/knowkit ... --json --input -`; mutation commands additionally require
their idempotency key and expected epoch/revision. The synthetic single-process
composition in `examples/plain-php` demonstrates the same application services;
it is not production authentication or a durable worker host.

Optional profiles have isolated dependency graphs and explicit host enablement:

| Profile | Installation and enablement |
| --- | --- |
| Library + CLI | Core, PostgreSQL, local immutable storage, TXT; no Python/framework/model key/Redis/GPU |
| Service without worker | Install `packages/http` and/or `packages/laravel` with their own lockfiles; supply authenticated host context; text remains usable without Python |
| Full document worker | Frozen Python runtime, qualified PDF/OCR assets and notices; staged-file subprocess sandbox, explicit durable job loop |
| Semantic/answer | Own Composer projects, exact provider/model/revision/space and qualified credentials; no silent lexical fallback on mismatch |
| Agentools bridge | NOT_SELECTED/PENDING for standalone delivery; real installed P8.2/P8.3 checks own qualification |

Laravel requires manual `KnowKitServiceProvider` registration. There is no
auto-discovery. Explicitly enable `knowkit.migrations.publish`, then run
`php artisan vendor:publish --tag=knowkit-migrations`; publishing copies SQL and
does not execute it. Routes stay off until `knowkit.http.enabled=true` and an
explicit registrar/authenticator is supplied. Commands and jobs each have their
own enable flag. Host authentication constructs AccessContext; JSON fields never
grant tenant or actor privileges. Start job/outbox/purge processes separately.

See [the independent walkthrough](../examples/acceptance/README.md),
[operations](operations/runbook.md), [backup](operations/backup.md) and
[purge](operations/purge.md). `qa all` remains strict across the full registry;
it is not a prerequisite for standalone delivery. Neither bridge readiness nor
completion of all 59 roadmap items is asserted here. Capacity qualification
remains negative as recorded in [capacity](operations/capacity.md).
