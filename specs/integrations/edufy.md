# Edufy first-adopter contract fixture, v1

Status: **fixture=true; HOST QUALIFICATION PENDING**. This specification qualifies
synthetic composition only. It does not approve an installed Edufy/Chatom host,
real provider, Library/Files integration, publication or rollout.

## Ownership and package boundary

The runnable composition is `examples/edufy-first-adopter/run.php`.
`KnowKitExample\Edufy` contains standalone typed fixture interfaces and adapters;
runtime packages import no Edufy classes. The example reuses plain-PHP source
ingestion/publication, core Selections/Disclosures, PostgreSQL stores and the answer
package's admission, pins, replay, payload custody, budget and bounded dispatch.
The core package DAG and Composer boundaries do not change.

Actual future adapters belong to the existing ContentStudio/AiCore/Messenger
owning modules in their actual namespaces. There is no `knowkit/edufy` package.
Content owns source visibility, current authority/publication/legal checks and
topic/age/KB eligibility. Identity/School owns current learner/class/consent state.
Tutor and lesson state remains in the host. Chatom owns history, membership and
publication persistence; AgenTools owns its orchestrated runtime and journals.

## Typed host bindings

`TutorBindings::modelFor(tutorInstance):ModelBindingRef` and
`LessonBindings::conversationFor(lesson):LessonConversationBinding` are distinct.
A lesson binding contains lesson, channel, KB, tutor instance, route and revision;
it does not contain a mutable provider/model deployment. The example selects one
`caller_managed` response owner for a binding. Direct Chatom and
`agentools_orchestrated` are separate host composition lanes and are not
implemented by this fixture.

A tutor model reference resolves to an explicit provider/account/region/model,
model revision, transport, capability snapshot, credential reference, generation
settings and binding digest. Existing invocation keys load the admitted pin before
any mutable resolution. A changed tutor deployment affects newly admitted keys.
It does not rewrite historical answers, citations, conversation messages or
embedding identities. Same key with changed original request conflicts; it does
not generate a replacement. Model binding revision is distinct from host authority
revisions, so changing only the deployment does not change the admitted scope.

## Eligibility is an upper bound before top-k

The standalone `KbResolverPort::resolve(topicId,KbContext{scopeIds,studentAge})`
returns ordered typed `KbResolution` entries. Fixture rules run general to
specific: an applicable override replaces previous candidates; additive rules
append/deduplicate entries. All applicable explicit KB exclusions remain a deny
set even across a later addition or override. Topic, required scope IDs and
bounded age are checked before a rule contributes entries. Unknown age cannot
satisfy an age-constrained rule. `teacher_only` entries never enter a
`student_safe` universe. This algorithm is an executable synthetic illustration
of the scoped Content contract, not discovery of a mature Library API.

CoreEvidence passes the resolved source-version universe separately to core
Selections. Core intersects it with the current QueryGrant and typed filters before
the lexical backend's ORDER BY/LIMIT. The example intentionally supplies a broader
read grant to prove it cannot reintroduce an overridden/excluded KB. Resolver
entries and selected references are not disclosure or processing grants. Fresh
checks apply even to cached selections and completed answers.

## Passage ownership and immutable identity

`MaterialPassageReader::read(learner,class,topic,entry,section,requestedRevision)`
models the **AiCore → Content remote consumer**. `MaterialAdapter` belongs to the
owning host; Content must not call back through AiCore and create a dependency
cycle. Only `available` with the exact requested revision and expected hash, also
verified against returned text, permits an allowed read. `empty` carries no text;
missing, unpublished, withdrawn, stale, denied, unavailable, unknown and exceptions
are fail-closed. Available read does not establish missing processing/egress rights.

The fixture pins `passage:1`. A response containing `passage:2` or a different hash
denies; it never redirects the old pin. Core citations retain tenant, source,
source version/digest, document revision, IR digest, content digest and bounded
canonical UTF-8 byte spans. A correction or revoke never substitutes latest
content into a historical citation. Answer payload custody has a separate source
owner so evidence revocation still allows recording a metadata-only outcome.

## Trusted scope and current authority

The host creates Scope; model/tool input cannot supply identity, grants or epochs.
Scope retains tenant, KB, actor, business purpose, policyEpoch and opaque
hostScopeHash. Business purpose such as `lesson.read` or `handbook.read` is distinct
from Operation (`retrieve`, `disclose`, `publish`, `model_egress_context`).

HostScope hashes canonical structured values with the example domain
`example:edufy:host-scope:v1` plus NUL. It includes project/tenant, actor, acting-as,
subject, learner, class, lesson, topic, audience kind and members, consent,
session, data scopes, business purpose and identity/school/membership/lesson/topic/
audience/consent/session/data-scope/source-rights/publication/egress revisions.
Audience and data-scope sets are sorted. Null non-school fields remain explicit.
Core separately binds KB and local policy epochs. In the AgenTools route, its
trusted ExecutionContext scopeHash must be used verbatim; the example's
`agentoolsHash` demonstrates preservation, not a second scope-hashing scheme.

HostAuthority checks the full current scope and authoritative lesson revision,
class, consent, passage and source lifecycle before disclosure, before model send
including after durable sending intent, during completed replay and at the reply
mutation boundary. Unknown current facts disclose no protected plaintext.
Host-supplied protected history requires a current matching HistoryReadAttestation
and current visibility of its dependencies. Historical evidence, outcome bytes,
attestations or publication receipts never grant current access.

Egress separately checks all QUESTION, HISTORY and EVIDENCE manifest entries and
the pinned provider/account/region/model destination. Denied question/history or
missing processing rights yields zero transport calls and zero outbound bytes.
The model adapter constructs one explicit ScriptedTransport result for that exact
outbound request; it is fixture-only and never contacts a provider. No automatic
retry, repair, fallback, paid probe or streaming path exists.

The answer is buffered and citation-checked before a fixture reply. ReplyOwner
requires the exact persisted completed response hash and original request, current
audience/consent/source/history/publication and unchanged lesson binding after
owner IO. Its stable `final.v1` slot issues the same receipt after a lost ACK;
an altered body cannot replace the checked response. These are in-memory fixture
observations, not new Chatom persistence. Partial model output stays unknown and
cannot be resent by a later/stale worker. Local source checks and SQL usage guards
retain core linearization; separate external owner IO is not globally atomic.

## Required corpus and proof boundary

`tests/fixtures/check-groups/adoption-edufy.json` maps all ten required IDs to
executable PHPUnit methods in `tests/adoption/edufy`. The existing QA runner reads
actual JUnit results and rejects missing, skipped, failed, duplicate or zero cases.
The versioned corpus and profiles are SHA-256 pinned in the manifest. Negative
runner tests prove that an incomplete run cannot claim coverage.

| Required ID | Observation |
| --- | --- |
| `edufy:eligibility-override` | Override excludes default before core top-k despite broad read grant |
| `additive-with-deny` | Ordered additive entries cannot restore an explicit exclusion |
| `age-topic-scope` | Age/topic/scope restrictions and full host hash inputs |
| `current-passage-revision` | Exact immutable revision/hash; all unavailable statuses deny |
| `teacher-only-deny` | Student gets no evidence/send; teacher uses the same core |
| `class-consent-revoke` | Fresh checks at send/replay/reply; source/history/egress negatives |
| `model-change-newkey` | New deployment only for new key; conflict/partial/stale/lost-ACK boundaries |
| `lesson-vs-tutor-binding` | Independent bindings and changed lesson denies publication |
| `non-school-profile` | Workplace profile without learner/class/lesson uses identical services |
| `cross-project-leakage` | Same local IDs/key/query, distinct tenants/scopes; no shared-cache/citation/usage/answer adoption |

The independent-process ledger/crash schedules delivered by P4.11 remain owned by
that item. This fixture uses those existing services; it does not claim to review
or replace that proof. Local PHP tests do not prove the PHP 8.5/Laravel/installed
Chatom or real provider lanes.

## Pending host qualification

Real Library/Files original immutable file/version/hash, processing purpose,
region/time rights and Content↔Files callback/binding remain unaccepted. No new
Library facade, upload capability, grants or scan-state API is asserted here.
Future consumer tests must qualify current owner HTTP behavior, tenant/account
mapping, source visibility and installed package pins independently of the
fixture/core/provider proofs. PHP 8.5/Laravel 13.8 and PostgreSQL 16 are a separate
Edufy host qualification; core retains PHP 8.3 compatibility. Record the actual
owner check time/version and its bounded distributed race rather than claim
atomicity with remote dispatch or publication. No real Edufy write/deploy or
release is authorized by this example.
