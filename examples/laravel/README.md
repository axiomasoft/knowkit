# Explicit Laravel integration

Install `knowkit/laravel` in the host application's Composer project. It supports
PHP 8.3+ and Illuminate 12; the repository qualifies the locally available PHP lane
separately. Core stays vendor-free. The package has no Laravel autodiscovery entry,
Composer scripts or autoload files. Installing it creates no database objects,
routes, commands, schedules, queue work or model requests.

Register `KnowKit\Laravel\KnowKitServiceProvider` manually in the host's
`bootstrap/providers.php`. Bind the host's explicitly populated and booted
`AdapterRegistry` **before** registering this provider. Set `knowkit.profile` to
canonical `host-profile:1` JSON. Its existing core validation rejects unknown
adapters, unsupported capabilities, incompatible kinds and unsafe settings without
constructing adapters. Configured port bindings resolve lazily. There are no
implicit storage, model or authorization adapters. With no profile configured,
registration is inert and does not pretend the application is ready.

Publish configuration with `php artisan vendor:publish --tag=knowkit-config`.
Keep only portable settings and credential references in configuration. For each
credential, `knowkit.credentials` maps an opaque handle to a process environment
variable name. `EnvironmentCredentials::get()` reads that variable at use time;
it never stores the secret in a profile. Supply the environment through the host's
secret deployment mechanism. No secret belongs in command arguments or examples.

Enable capabilities independently in the published config:

```php
'http' => ['enabled' => true, 'registrar' => App\KnowKit\Routes::class],
'migrations' => ['publish' => true],
'commands' => ['enabled' => true],
'jobs' => ['enabled' => true],
```

Each flag defaults to the boolean `false`. Turning on HTTP requires an explicit
`RouteRegistrar`. Its routes are wrapped in `AuthenticateContext`; host
authentication must provide an authenticated Laravel principal. Bind
`HttpIdentity` to a host adapter that resolves current policy for that principal.
The factory uses core `RequestBoundary` and rejects trusted identity fields in
request JSON. Headers, route IDs, tool arguments and serialized scope values never
establish identity. The request's `knowkit.trusted_context` attribute contains the
host-created `AccessContext`; recheck it before invoking application services.
Those services retain authoritative lifecycle/access checks before disclosure.

Copy `SearchRoutes.php` and `SearchController.php` into the host and adjust their
namespace. They demonstrate one `/v1/search` route delegating to `HostApplication`.
They are not a replacement for the optional HTTP package's full endpoint/schema
implementation. Bind `HostApplication` to the host composition that maps validated
core CLI commands to the existing application DTOs/use cases. The same composition
serves Artisan and the plain CLI. Controllers contain no domain business rules.

Migration opt-in only exposes the `knowkit-migrations` publishing tag. Explicitly
run `php artisan vendor:publish --tag=knowkit-migrations`; it copies immutable core
PostgreSQL SQL into `database/knowkit/postgres`. It does not execute SQL, load
Laravel migrations or modify Laravel's application tables. An administrator then
invokes core `Migrate::apply()` against an explicitly selected KnowKit schema and
the published `001_metadata.sql` entry point. Keep PDO credentials in environment.

Command opt-in registers `php artisan knowkit`. Bind core `HostIdentity` to the
host's authenticated console integration. The command forwards core grammar,
idempotency/epoch/revision arguments and `--input=<file>` (or `--input=-` for stdin).
For example, `php artisan knowkit search --json --input=query.json` uses an ordinary
local JSON file. Outputs and exits retain core success/empty, denied, unknown,
unavailable and incompatible semantics; errors redact inputs and internal details.
Terminal arguments pass through the core grammar unchanged, so duplicate flags
and invalid command/option ordering produce the same usage errors as the core CLI.

Jobs require host bindings for `JobIdentity`, `JobHandler` and Laravel's bus
`Dispatcher`. Only an explicit `Jobs::dispatch($jobRef)` queues a `RunJob`. Its
payload contains only an opaque durable job reference. The host identity adapter
must authorize that durable work for its owner on every execution; it must never
grant authority merely because a caller knows a job reference. `JobHandler`
delegates to application services and keeps their existing tenant, lease and
lifecycle checks. A revoked identity prevents execution, including retries. The
host owns starting/supervising queue workers and any outbox scheduler; provider
registration and capability enablement start neither.

Regression fixtures and assertions live in `tests/delivery/laravel`. Dependencies
are isolated in `packages/laravel/composer.json` and its lock. The root/core tooling
graph contains no Illuminate or Laravel dependency.
