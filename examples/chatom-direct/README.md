# Local direct Chatom example

Run the [qualified local host](../../docs/integrations/chatom-qualification.md)
with the pinned [Compose profile](../../deploy/profiles/chatom/compose.yaml).
It exercises the installed Chatom driver and KnowKit direct handler with authored
synthetic host authority, evidence and model output. `fixture=true`;
**HOST QUALIFICATION PENDING** for Edufy and production providers.

```sh
docker compose -p knowkit-chatom-qualification -f deploy/profiles/chatom/compose.yaml up -d --wait
composer qa -- chatom:qualification
```

The runnable composition is
[`NativeHost.php`](../../tests/chatom/qualification/NativeHost.php) and
[`InstalledHandler.php`](../../tests/chatom/qualification/InstalledHandler.php).
It creates no HTTP listener and dispatches no paid request. A native learner
message captures a durable invocation, the explicit relay calls the direct
handler, and `final.v1` is committed through the native reply pipeline. Running
the recovery schedule reuses the same saved answer and reply slot.

An actual host supplies its current `ConversationBindingResolver`,
`ConversationAudience`, `ConversationAnswerFactory` and `CitationViewerContext`.
Preserve the entire trusted Scope, including hostScopeHash and business purpose.
Use the same request-scoped `ChatomReplyBoundary` instance as the native
`ChannelAccess` decorator and the handler boundary; install it before resolving
native pipelines. The answer factory must install the supplied authorization in
both generation and replay. Replace synthetic authority only with current owning
host contracts after separate host qualification.

Declare every route owner in one `RouteRegistrationAssertion` before activating
listeners. Exactly one direct/orchestrated/caller owner may handle a binding.
Different bot/binding tuples may use different routes. This example does not
replace the existing Agentools intake, runtime, journal or delivery owners.

The PHP/Laravel and PG17/18 driver lane is explicit. Core PG16/17/18 schema proof
is independent. The Edufy PG16 test expects native catalog qualification to be
rejected and leaves readiness false; it never changes the host database version.
Original Library files, real owner HTTP and provider/live-host proofs stay pending.

```sh
docker compose -p knowkit-chatom-qualification -f deploy/profiles/chatom/compose.yaml down --volumes --remove-orphans
```
