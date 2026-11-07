# Plain PHP walkthrough

Run `php examples/plain-php/run.php` from the repository root with the disposable
PostgreSQL database described in [getting started](../../docs/getting-started.md).
No Composer runtime dependencies, framework, worker or model service is used.

`Sandbox` provisions isolated storage explicitly; `Runtime` wires application
use cases to the CLI dispatcher. `Walkthrough` runs executable assertions on a
versioned, project-authored local text fixture. Temporary data is removed on exit.
The sample host is single-user and single-process; its lifecycle and disclosure
barriers are the real PostgreSQL application services.
