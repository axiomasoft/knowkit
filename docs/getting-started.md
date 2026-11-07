# Standalone PHP and CLI

KnowKit core runs on PHP 8.3+ with PDO, JSON and mbstring, without Laravel,
AgenTools, Python workers or model services. The local TXT example also needs
pdo_pgsql and PostgreSQL 16+. It uses the core catalog, durable jobs, immutable
local blobs, PostgreSQL lexical index, publication, selection cache, disclosure,
citations and lifecycle use cases.

From a checkout, start the disposable database and run the example:

```sh
docker compose -p knowkit-demo up -d --wait postgres
php examples/plain-php/run.php
docker compose -p knowkit-demo down --volumes --remove-orphans
```

The defaults match `compose.yaml`. If that port is occupied, set `TEST_PG_PORT`
for Compose and export the corresponding `TEST_PG_DSN` for PHP. Authentication
comes from the host environment (`TEST_PG_USER`, `TEST_PG_PASSWORD`); never put
credentials in command arguments or tracked files. This example refuses a
database whose name does not end in `_test`. Setup creates a random schema,
applies core migrations explicitly and switches to a restricted runtime role.
The invoking database role must be allowed to create that disposable schema and
role. Cleanup removes only this run's schema, role and private temporary blob
directory, including on failure.

The executable prints one JSON report. Its assertions run this sequence through
the CLI parser and dispatcher:

1. Create a KB and collection; register a source and attach its membership.
2. Upload the versioned local TXT fixture and start a local ingestion job.
3. Validate and activate the exact generation with pointer revision `0`.
4. Search twice, exercising a reference cache hit with a new selection token.
5. Disclose with a fresh attempt; resolve its immutable citation and read the
   authorized original source. Check the original bytes against the citation.
6. Revoke the source and attempt disclosure from the cached selection again.
   Require `denied` with no data.

The example runs synchronously in one PHP process. The host keeps verified
chunksets, canonical IR buffers and checkpoint handles in that process; durable
catalog/blobs/jobs remain in core adapters. Its worker lease spans build through
publication. `ingest status` reports the actual leased state until publication
completes the job. Use a host supervisor, persisted artifact loading and periodic
heartbeats for a long-running or restartable installation. This example does
not qualify crash recovery or a multi-user authentication provider.

Migration `009_empty_snapshot_members` supports confirmed-empty TXT generations.
Its additional membership path requires a sealed manifest, matching immutable
source/revision/chunkset identities and digests, and an actually empty chunkset.
A missing nonempty index projection cannot qualify as an empty member. Earlier
migrations and their stored checksums are preserved.

## CLI contract

After installing `knowkit/core`, Composer exposes `vendor/bin/knowkit`. In a
checkout use `packages/core/bin/knowkit`. Install has no migration, route, worker
or provider side effects. The executable supports these exact command routes:

```text
kb create
collection create
source register|upload
ingest start|status|cancel|reprocess
publication validate|activate
search
disclose
read source|page|region|table|asset
lifecycle correct|revoke|delete|hold
purge status
capabilities
restore verify
reconcile
```

All routes accept `--json` and always emit one JSON envelope to stdout. Supply
business data using `--input <local-json-file>` or `--input -` for stdin. Input
objects are closed: unknown fields, duplicate keys, wrong types, malformed IDs,
extra positional arguments and unknown/duplicate flags fail before host code.
Stream wrappers are rejected. Input is bounded to 64 KiB. Original uploads use
`--file <local-file>` separately; no document text is placed in argument strings.

Every mutation requires `--idempotency-key`. Upload, ingest start/reprocess/cancel,
publication validate/activate and lifecycle mutations require `--expected-epoch`.
Publication activation also requires `--expected-revision` for the active
pointer. Optional revision checks on KB/collection creation, source registration
and upload pass through to their application mutation context. The epoch for a
collection mutation is the KB policy epoch; the upload/ingest/lifecycle epoch is
the source lifecycle epoch. IDs and decimal revisions stay strings in JSON.

For example, an authenticated host can search without placing the query in argv:

```sh
printf '%s\n' '{"query":"support"}' | packages/core/bin/knowkit search --json --input -
```

Exit codes are `0` for success/empty, `2` for syntax/schema/usage, `3` for denied,
`4` for unknown/unavailable, `5` for incompatible/conflict and `70` for unexpected
internal failure. Error envelopes contain safe machine codes, never exception
messages, stack traces, credentials, machine paths or source text. Stderr carries
only a safe failure diagnostic. Empty has `data: []`; failure has no data. Host
output accidentally printed during dispatch is discarded.

## Host wiring

`KNOWKIT_HOST_BOOTSTRAP` identifies a trusted, host-owned PHP bootstrap returning
a `Closure(Command): Response`. The executable loads it after command validation.
The callback constructs application DTOs, calls the application use cases and
maps their typed results to `Response::result`. The host authenticates the caller,
constructs trusted `Scope`/`PolicyContext`, supplies credentials, owns artifact
loading and enforces mutation idempotency and optimistic checks. The CLI command
contains only transport data and validated mutation flags. Inspect
`examples/plain-php/Runtime.php`, `TextDelivery.php` and `QueryDelivery.php` for
the concrete local composition used by the runnable walkthrough.

Tenant, actor, audience, grants, policy claims, host scope hash and disclosure
usage context cannot be supplied as top-level command input. `disclose` takes
`selectionId`, exact `chunkRefs`, `disclosureAttemptId` and the opaque search
`token`. The host loads the original query/snapshot from its selection record;
it never trusts replacement query or policy fields from the disclosure input.
`read` takes the issued `citationId` and a new `attemptId`, plus an exact selector
for page/region/table/asset. Source reads return base64 bytes with media type.
Historical citation identity never resolves through the latest source version.
An existing disclosure attempt replays metadata only; each new delivery needs
a fresh attempt and fresh lifecycle/access checks.

The TXT example advertises local text processing. It has no recovery journal
integration: `restore verify` and `reconcile` return typed unavailable until a
host supplies that integration. Page/table/asset reads delegate to the existing
reading service and require those exact objects in the ingested IR. A TXT fixture
does not invent pages, tables or images. Optional HTTP conformance R01-A02 belongs
to HTTP delivery, as accepted in P4.5 Q1=a; it is not reported as passed here.

## Verification

The coordinator runs `delivery:cli` acceptance. Diagnose individual files with:

```sh
vendor/bin/phpunit --configuration phpunit.xml tests/delivery/cli/TransportTest.php
vendor/bin/phpunit --configuration phpunit.xml tests/delivery/cli/JourneyTest.php
vendor/bin/phpunit --configuration phpunit.xml tests/delivery/cli/FixtureTest.php
```

The required-case manifest is `tests/fixtures/check-groups/delivery-cli.json`.
It binds versioned fixture digests and actual PHPUnit methods for every required
positive/negative case. A missing backend fails; it is never a skipped success.
Source reuse decisions and qualification limits are recorded in
`tests/delivery/cli/evidence/P4.5-reuse.json`.
