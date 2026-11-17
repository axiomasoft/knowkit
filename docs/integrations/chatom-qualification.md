# Direct Chatom qualification v1

This qualification exercises an **installed Chatom driver in a disposable local
Laravel host**. Host authority, evidence retrieval, answer ledger and model
transport remain synthetic. No production provider, Edufy host or deployment is
qualified. `pendingLibraryFiles` remains pending.

The normative executable matrix is
[`matrix.json`](../../tests/chatom/qualification/v1/matrix.json). The required-case
manifest is
[`chatom-qualification.json`](../../tests/fixtures/check-groups/chatom-qualification.json).
The QA runner requires every mapped PHPUnit identity, including each PG17/18
data set, and fails for missing, skipped, failed, duplicate or zero cases.

| Tier | Local proof | Qualification boundary |
| --- | --- | --- |
| Fixture | Per-binding route guard, synthetic all-content egress and production fixture rejection | Synthetic authority/model only |
| Core | All 13 core migrations, empty catalog, separate schemas on PG16/17/18 | Schema smoke; complete core conformance is a separate lane |
| Installed driver | Native migrations/doctor, authorized viewer history, real durable invocation/reply, native mutation policy, message/receipt/slot/outbox persistence | Local Laravel host with synthetic source/audience authority and model |
| Provider | Missing configuration returns metadata-only Unavailable; injected HTTP client gets zero calls | Paid provider qualification pending |
| Live host | No production network or deployment | Edufy owner HTTP, tenant/account mapping, current rights and original Library files pending |

## Exact profile

The observed runtime is PHP **8.4.1**, Laravel **v13.40.0** at
`1449bf241017087fbd38c118ecaabef3f6797fdc`, and Chatom at
`909d11e148ec9991bc83527891f8c3b4f5552f8f`. The Chatom package requires PHP
`^8.4` and Laravel `^13.0`; KnowKit core retains its independent PHP floor.
Only the listed runtime was executed here; PHP 8.5 or another Laravel release
requires a separately versioned qualification.

The package lock SHA-256 is
`7599acbe41c907977a845bc142c0ef6286b80bce92d82458e47f07cc12907e3c`.
RuntimePins checks the lock, installed Composer references, PHP version and
complete installed Chatom tree before host boot. The tree digest algorithm is
SHA-256 of sorted relative paths, each followed by NUL, its file SHA-256 and LF.
This also detects a changed local path distribution whose advertised Composer
reference stayed the same. The tests load this checkout's KnowKit sources and
the installed Chatom/Laravel classes; source presence alone is insufficient.

| PostgreSQL | Exact local server | Core schema | Installed Chatom |
| --- | --- | --- | --- |
| 16 | 16.14, Debian 16.14-1.pgdg13+1 | Pass | Edufy combination explicitly unqualified |
| 17 | 17.11, Debian 17.11-1.pgdg13+2 | Pass | Pass for this local profile |
| 18 | 18.6, Debian 18.6-1.pgdg13+2 | Pass | Pass for this local profile |

The Compose profile pins immutable image digests; the harness rejects a different
server version even if the major matches. Port overrides change only local
connectivity. These versions describe the installed qualification artifacts,
not a recommendation to upgrade any host.

Edufy's PG16 combination has its own test. Native migrations run, but native
`chatom:schema:doctor --json` exits 1 with unverified catalog definitions.
The expected negative test passes while **host readiness remains false**.
PG16 is never redirected to PG17/18. Native driver success on another database
does not establish Edufy readiness.

## Schedules and ownership

`InstalledDriverTest` sends a learner message through public `Channels` and
`Messages` contracts. The real native relay constructs the actual
`TransactionalAgentReply`; KnowKit returns the immutable `final.v1` document
through that object. Native authorized `ViewerMessageReader::history` supplies
the trigger and history. No raw snapshot fallback or replacement reply store
is used. Existing P7.5 tests separately cover bounded history and immutable
citation navigation; this item does not claim to requalify those entire suites.

The same request-scoped `ChatomReplyBoundary` decorates native `ChannelAccess`
before pipeline services resolve. An installed test plugin changes synthetic
learner/consent/source/binding authority immediately before native sender
reauthorization. Every denied, unknown or stale case produces one model send,
zero reply messages and zero committed slots. Native channel archival after
generation independently proves native policy can reject the reply. Removing
native bot membership before delivery cancels the invocation with zero model
calls and zero replies.

ACK loss is injected **after the actual native reply commit**. The test advances
only the pending delivery's local due timestamp, then explicitly runs the owning
relay again. Saved message, body, hash, slot, native invocation key and receipts
remain unchanged; the completed answer is reused and model send count stays one.
Native same-client-key/same-body send returns `replayed`; changed body raises
`ReceiptConflictException(fingerprint_mismatch)` without a second message.
Revoked audience on recovery blocks publication despite the historical receipt.
No KnowKit retry loop, repair generation, second journal or relay was introduced.

One registration tuple is
`tenant + space + channel + bot + binding`. Register all owners together through
`RouteRegistrationAssertion` before installing triggers. Two owners on the same
binding, including duplicate registrations, fail. A direct binding and a distinct
orchestrated binding coexist. A route mismatch creates no answer admission or
model call. Two installed native bots receive distinct invocation keys, checked
answers and reply slots. The test does not install an Agentools runtime or claim
its orchestrated delivery has been qualified by this item.

The production readiness test injects an HTTP client that must never be called.
Missing explicit host transport configuration yields metadata-only Unavailable.
Fixture descriptors are rejected in production; denied QUESTION or HISTORY
egress yields zero sends and zero outbound body bytes. No DNS discovery,
fallback, automatic provider retry or paid readiness probe is configured.

Remote owner revocation after its last authoritative check remains a distributed
race. This local qualification proves the supplied checks and native transaction
boundary; it cannot prove global atomicity across owners.

## Reproduce

Use the existing exact `packages/chatom/composer.lock` install; do not run
`composer update` or replace the native driver with a stub. The existing Composer
path distribution needs the authorized pinned Chatom source available to the
installer. All test data is authored synthetic content. No API key is needed.

```sh
docker compose -p knowkit-chatom-qualification -f deploy/profiles/chatom/compose.yaml up -d --wait
composer qa -- chatom:qualification
docker compose -p knowkit-chatom-qualification -f deploy/profiles/chatom/compose.yaml down --volumes --remove-orphans
```

The conductor owns the acceptance command. Addressed test-file proofs can be run
separately with the Chatom Composer bootstrap, for example:

```sh
KNOWKIT_QA_COMPOSER_PROJECT=packages/chatom packages/chatom/vendor/bin/phpunit --configuration phpunit.xml tests/chatom/qualification/FixtureTierTest.php
KNOWKIT_QA_COMPOSER_PROJECT=packages/chatom packages/chatom/vendor/bin/phpunit --configuration phpunit.xml tests/chatom/qualification/InstalledDriverTest.php
KNOWKIT_QA_COMPOSER_PROJECT=packages/chatom packages/chatom/vendor/bin/phpunit --configuration phpunit.xml tests/chatom/qualification/ProfilesTest.php
KNOWKIT_QA_COMPOSER_PROJECT=packages/chatom packages/chatom/vendor/bin/phpunit --configuration phpunit.xml tests/chatom/qualification/PinsTest.php
```

Each host uses a random schema in `knowkit_chatom_test`, drops only that schema
in `finally`, and removes its temporary Laravel directory. Containers use tmpfs,
loopback ports and trust authentication only for these disposable databases.
Keep run output outside `tests/**/evidence/`; its only new file is the tracked
[`P7.6-reuse.json`](../../tests/chatom/qualification/evidence/P7.6-reuse.json).
The coordinator mirrors that receipt into plan artifacts; the executor does not
mutate plan state.
