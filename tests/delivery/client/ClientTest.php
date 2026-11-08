<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Client;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Http\Client as C;
use KnowKit\Http\Server as H;
use KnowKit\Tests\Delivery\Http\Fixture as F;
use KnowKitExample\Runtime;
use KnowKitExample\Sandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';
require_once dirname(__DIR__, 3) . '/examples/plain-php/bootstrap.php';

#[Group('delivery:client')]
final class ClientTest extends TestCase
{
    public function testTimeoutAfterCommittedWriteRetriesOneImmutableMutationAndReplayReauthorizes(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            $f = new F();
            $f->host = $runtime->host;
            $application = new H\CoreCommands(
                remaining: $f, catalog: $runtime->catalogCommands, sources: $runtime->sources, lifecycle: $runtime->lifecycle,
            );
            $server = $f->server(application: $application, clock: $runtime->host, ids: $runtime->host);
            $time = new TestTime();
            $transport = new ScriptedTransport(function (H\Request $request, int $timeout, int $attempt) use ($server, $time): H\Response {
                $response = $server->handle($request);
                if ($attempt === 1) {
                    self::assertSame(201, $response->status, $response->body);
                    $time->now += 10;
                    throw new C\TransportFailure(timedOut: true);
                }

                return $response;
            });
            $client = new C\Client(transport: $transport, time: $time);
            $create = self::mutation(operation: 'createKnowledgeBase', body: new JsonObject([
                'name' => 'Client conformance', 'policyRef' => 'host-policy:1', 'defaultRetrievalProfile' => 'postgres-fts-simple-v1',
            ]), key: 'client-create-kb');
            $created = $client->execute($create);
            self::assertSame(D\Outcome::Success, $created->outcome);
            self::assertCount(Cases::regression('committedWriteTimeout')->get('expectedAttempts'), $transport->seen);
            self::assertSame($transport->seen[0][0], $transport->seen[1][0]);
            self::assertSame([5000, 4890], array_column($transport->seen, 1));
            self::assertSame([100], $time->sleeps);
            self::assertFalse($runtime->host->db->pdo->inTransaction());
            self::assertSame(Cases::regression('committedWriteTimeout')->get('expectedWrites'), (int) $runtime->host->db->execute('SELECT count(*) FROM knowledge_bases')->fetchColumn());
            $count = (int) $runtime->host->db->execute('SELECT count(*) FROM idempotency_receipts')->fetchColumn();
            self::assertSame(1, $count);
            self::assertEquals($created->data(), $client->execute($create)->data());
            self::assertSame($count, (int) $runtime->host->db->execute('SELECT count(*) FROM idempotency_receipts')->fetchColumn());
            $conflict = self::mutation(operation: 'createKnowledgeBase', body: new JsonObject([
                'name' => 'Conflicting payload', 'policyRef' => 'host-policy:1', 'defaultRetrievalProfile' => 'postgres-fts-simple-v1',
            ]), key: 'client-create-kb');
            self::assertSame(D\Outcome::Incompatible, $client->execute($conflict)->outcome);
            $runtime->host->decision = D\Decision::Deny;
            $denied = $client->execute($create);
            self::assertSame(D\Outcome::Denied, $denied->outcome);
            self::assertNull($denied->data());
        });
    }

    public function testExpectedHeadersAndExactBodyCannotChangeBetweenRetries(): void
    {
        $time = new TestTime();
        $f = new F();
        $server = $f->server();
        $transport = new ScriptedTransport(static function (H\Request $request, int $timeout, int $attempt) use ($server): H\Response {
            if ($attempt === 1) {
                throw new C\TransportFailure();
            }

            return $server->handle($request);
        });
        $call = self::mutation(
            operation: 'createCollection', body: new JsonObject(['collectionId' => F::id(503)]), key: 'client-collection',
            epoch: '0', revision: '3', parameters: ['kb' => $f->identity->scope->kbId],
        );
        (new C\Client(transport: $transport, time: $time))->execute($call);
        self::assertSame($transport->seen[0][0], $transport->seen[1][0]);
        $request = $transport->seen[1][0];
        self::assertSame('client-collection', $request->header('Idempotency-Key'));
        self::assertSame('0', $request->header('Expected-Epoch'));
        self::assertSame('3', $request->header('Expected-Revision'));
        self::assertSame(F::id(500), $request->header('Request-ID'));
        self::assertEquals(new JsonObject(['collectionId' => F::id(503)]), StrictJson::decode($request->read(262144)));
    }

    public function testQuotaResponseRetriesWithinDeadlineAndNeverHidesFinalQuota(): void
    {
        $time = new TestTime();
        $transport = new ScriptedTransport(static fn (): H\Response => self::quota('1'));
        $response = (new C\Client(transport: $transport, time: $time))->execute(self::search());
        self::assertSame(Cases::regression('quota')->get('status'), $response->status);
        self::assertNull($response->outcome, 'Transport quota must not become empty or denied');
        self::assertSame(Cases::regression('quota')->get('errorCode'), $response->errorCode);
        self::assertNull($response->data());
        self::assertCount(Cases::regression('quota')->get('maxAttempts'), $transport->seen);
        self::assertSame([1000, 1000], $time->sleeps);
        foreach (['60', 'Wed, 01 Jan 2099 00:00:00 GMT', '-1'] as $after) {
            $time = new TestTime();
            $transport = new ScriptedTransport(static fn (): H\Response => self::quota($after));
            $response = (new C\Client(transport: $transport, time: $time, options: new C\Options(timeoutMs: 20)))->execute(self::search());
            self::assertSame(429, $response->status);
            self::assertCount(1, $transport->seen);
            self::assertSame([], $time->sleeps);
        }
    }

    public function testDeadlineAndAttemptLimitBoundTransportFailure(): void
    {
        foreach ([false, true] as $late) {
            $time = new TestTime();
            $server = (new F())->server();
            $transport = new ScriptedTransport(static function (H\Request $request) use ($time, $server, $late): H\Response {
                if ($late) {
                    $time->now += 20;
                    return $server->handle($request);
                }
                throw new C\TransportFailure(timedOut: true);
            });
            try {
                (new C\Client(transport: $transport, time: $time, options: new C\Options(timeoutMs: 20, retryDelayMs: 10)))->execute(self::search());
                self::fail('Timeout accepted');
            } catch (C\TransportFailure $failure) {
                self::assertTrue($failure->timedOut);
                self::assertCount($late ? 1 : 2, $transport->seen);
            }
        }
        $transport = new ScriptedTransport(static function (): H\Response {
            throw new C\TransportFailure();
        });
        try {
            (new C\Client(transport: $transport, time: new TestTime()))->execute(self::search());
            self::fail('Attempt limit ignored');
        } catch (C\TransportFailure) {
            self::assertCount(3, $transport->seen);
        }
    }

    public function testPlaintextOperationsNeverRetryAutomatically(): void
    {
        foreach (F::cases()['routes'] as $case) {
            if (!in_array($case['operation'], ['disclose', 'resolveCitation', 'readSource', 'readPage', 'readRegion', 'readTable', 'readAsset'], true)) {
                continue;
            }
            $transport = new ScriptedTransport(static function (): H\Response {
                throw new C\TransportFailure(timedOut: true);
            });
            $parameters = $case['operation'] === 'resolveCitation' ? ['opaque' => new D\Ulid(basename($case['path']))] : [];
            $call = C\Call::operation(
                operation: $case['operation'], body: StrictJson::decode(json_encode((object) $case['body'], JSON_THROW_ON_ERROR)),
                requestId: new D\Ulid(F::id(500)), parameters: $parameters,
            );
            try {
                (new C\Client(transport: $transport, time: new TestTime()))->execute($call);
                self::fail('Plaintext timeout was retried');
            } catch (C\TransportFailure) {
                self::assertCount(1, $transport->seen, $case['operation']);
            }
        }
    }

    public function testVersionNegotiationAndProtocolFailuresStopWithoutRetryOrContentLeak(): void
    {
        $f = new F();
        $unsupported = $f->server()->handle(F::request(
            path: '/v1/search', body: ['query' => 'gravity'], headers: ['KnowKit-Schema-Version' => '2.0'],
        ));
        self::assertSame(409, $unsupported->status);
        self::assertSame('schema_mismatch', F::wire($unsupported)['error']['code']);
        self::assertSame([], $f->seen);
        foreach ([null, '2.0'] as $version) {
            $headers = $unsupported->headers;
            unset($headers['KnowKit-Schema-Version']);
            if ($version !== null) {
                $headers['KnowKit-Schema-Version'] = $version;
            }
            $transport = new ScriptedTransport(static fn (): H\Response => new H\Response(status: 409, body: 'SECRET', headers: $headers));
            try {
                (new C\Client(transport: $transport))->execute(self::search());
                self::fail('Unnegotiated response accepted');
            } catch (C\VersionMismatch $failure) {
                self::assertStringNotContainsString('SECRET', (string) $failure);
                self::assertCount(1, $transport->seen);
            }
        }
        foreach ([
            '{"outcome":"denied","data":{"text":"SECRET"},"error":{"code":"denied","retryable":false},"degraded":false,"warnings":[]}',
            '{"outcome":"empty","data":{},"degraded":false,"warnings":[]}',
            '{"outcome":"unknown","degraded":false,"warnings":[]}',
            '{"outcome":"success","data":{},"degraded":true,"warnings":[]}',
            'SECRET',
        ] as $body) {
            $headers = $unsupported->headers;
            $headers['Request-ID'] = F::id(500);
            $transport = new ScriptedTransport(static fn (): H\Response => new H\Response(status: 200, body: $body, headers: $headers));
            try {
                (new C\Client(transport: $transport))->execute(self::search());
                self::fail('Malformed response accepted');
            } catch (C\ProtocolFailure $failure) {
                self::assertStringNotContainsString('SECRET', (string) $failure);
                self::assertCount(1, $transport->seen);
            }
        }
        $transport = new ScriptedTransport(static fn (): H\Response => new H\Response(
            status: $unsupported->status, body: $unsupported->body, headers: $headers,
        ));
        try {
            (new C\Client(transport: $transport))->execute(self::search());
            self::fail('Server schema mismatch accepted');
        } catch (C\VersionMismatch) {
            self::assertCount(1, $transport->seen);
        }
    }

    public function testTypedSearchPreservesFiltersAndDoesNotAcceptIdentityOrTrustedUsage(): void
    {
        $f = new F();
        $server = $f->server();
        $transport = new ScriptedTransport(static fn (H\Request $request): H\Response => $server->handle($request));
        $client = new C\Client(transport: $transport);
        $client->search(
            query: new D\Query(text: 'gravity', sourceVersionIds: [new D\SourceVersionId(F::id(601))]),
            limit: new D\Limit(3), requestId: new D\Ulid(F::id(500)),
        );
        self::assertSame([F::id(601)], array_map(static fn (D\Ulid $id): string => $id->value, $f->seen[0][0]->query->sourceVersionIds));
        self::assertSame('1.0', $transport->seen[0][0]->header('KnowKit-Schema-Version'));
        foreach (['tenantId', 'actorId', 'hostScopeHash', 'usageContext'] as $field) {
            try {
                $client->execute(C\Call::operation(
                    operation: 'search', body: new JsonObject(['query' => 'gravity', $field => 'spoofed']),
                    requestId: new D\Ulid(F::id(500)),
                ));
                self::fail('Authority accepted from content');
            } catch (H\InputFailure) {
                self::assertCount(1, $transport->seen);
            }
        }
        try {
            $client->execute(C\Call::operation(operation: 'createKnowledgeBase', body: new JsonObject([
                'name' => 'Missing key', 'policyRef' => 'host-policy:1', 'defaultRetrievalProfile' => 'postgres-fts-simple-v1',
            ]), requestId: new D\Ulid(F::id(500))));
            self::fail('Mutation without key accepted');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $transport->seen);
        }
    }

    private static function search(): C\Call
    {
        return C\Call::operation(operation: 'search', body: new JsonObject(['query' => 'gravity']), requestId: new D\Ulid(F::id(500)));
    }

    private static function quota(string $retryAfter): H\Response
    {
        $wire = H\Response::error(status: 429, code: 'rate_quota', requestId: new D\Ulid(F::id(500)));

        return new H\Response(status: $wire->status, body: $wire->body, headers: $wire->headers + ['Retry-After' => $retryAfter]);
    }

    public static function mutation(
        string $operation,
        JsonObject $body,
        string $key,
        ?string $epoch = null,
        ?string $revision = null,
        array $parameters = [],
    ): C\Call {
        $wire = new JsonObject(['idempotencyKey' => $key, 'requestId' => F::id(500)]);
        if ($epoch !== null) {
            $wire = $wire->with('expectedEpoch', $epoch);
        }
        if ($revision !== null) {
            $wire = $wire->with('expectedRevision', $revision);
        }

        return C\Call::operation(
            operation: $operation, body: $body, requestId: new D\Ulid(F::id(500)), parameters: $parameters,
            mutation: new D\MutationContext(value: $wire, validator: F::validator()),
        );
    }
}
