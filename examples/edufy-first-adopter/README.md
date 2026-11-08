# Edufy first-adopter fixture

**fixture=true — HOST QUALIFICATION PENDING**

This standalone fake host uses the existing KnowKit core and optional answer package.
It imports no runtime Edufy, Laravel, Chatom or AgenTools namespaces. School concepts
live entirely under `KnowKitExample\Edufy`; the workplace profile uses the same core.
No Edufy dependency, repository, account, state change or deployment is required.

From the repository root, with PHP 8.3+ and the disposable PostgreSQL test service:

```sh
php examples/edufy-first-adopter/run.php
```

The script imports synthetic text, publishes a lexical snapshot, retrieves eligible
references, freshly discloses canonical spans, executes one ScriptedTransport exchange
and obtains an in-memory fixture publication receipt for each of schoolA, schoolB and
workplace. It prints immutable citation provenance and provider send counts. Setup
creates a random temporary schema, a restricted temporary role and private temporary
blob directory, then removes them. `TEST_PG_DSN` must name a disposable `*_test`
database; `TEST_PG_USER` and `TEST_PG_PASSWORD` are supplied by the local environment.
No production transport or paid readiness probe is configured.

The normative integration specification is [specs/integrations/edufy.md](../../specs/integrations/edufy.md).
Versioned synthetic data lives in `tests/fixtures/adoption/edufy/v1/`; the
`adoption:edufy` QA group already registered by the repository uses
`tests/fixtures/check-groups/adoption-edufy.json` and the `Answer` testsuite.
Every required ID must execute successfully; missing, skipped and zero cases fail.

| Host responsibility | Fixture implementation | Core/answer reuse |
| --- | --- | --- |
| Lesson → conversation, KB, tutor, route, revision | `LessonBindings` | No lesson type in core |
| Tutor instance → explicit model reference | `TutorBindings` | Immutable first-admission model pin |
| Topic, age, scopes, override/additive and KB exclusions | `KbResolverPort` | Eligibility intersected with QueryGrant before top-k |
| Current exact Content passage | `MaterialPassageReader` and `MaterialAdapter` | Fresh source checks before disclosure/send/reply |
| Actor, acting-as, subject, audience, consent and revisions | `HostScope`, `HostAuthority` | Trusted full Scope and hostScopeHash |
| Destination and QUESTION/HISTORY/EVIDENCE processing rights | `HostAuthority` | Whole-content EgressRequest and one bounded send |
| Current reply authority and stable `final.v1` slot | `FixtureReplyOwner` | Existing answer ledger and checked buffered result |

`FixtureReplyOwner` is a synthetic receipt double, not a Chatom store or an
AgenTools delivery owner. Lost-ACK fixtures reuse the saved outcome and the same
slot/body digest; the answer ledger owns generation deduplication. The
caller-managed route shown here must never be installed on an
`agentools_orchestrated` binding alongside its existing response owner.

The teacher-only fixture is tested through the same core. Student-safe audiences
receive no teacher-only candidates, evidence or model call. Read permission and an
available passage do not authorize processing, model egress or publication. The
negative cases cover current passage revision/hash, owner unavailable/unknown,
class/consent/source/history revocation, model changes, same-key conflicts,
partial replies, stale workers, publication mutation guards and project isolation.

Real Edufy **Library/Files rights remain unqualified**: original immutable file,
version/hash, processing purpose/region/time rights and the Content↔Files
callback/binding are not accepted APIs. This example invents no Library facade,
grant, upload capability, scan status or rollout. A future installed-host test must
separately qualify current owner HTTP, tenant/account mapping, source visibility,
package pins and the PHP 8.5/Laravel 13.8/PostgreSQL 16 host lane. Core remains at
the PHP 8.3 floor. A final remote owner check and external IO cannot be globally
atomic; record actual check times/revisions and qualify that distributed race.
