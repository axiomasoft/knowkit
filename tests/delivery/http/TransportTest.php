<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Http;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Http\Server as H;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:http')]
final class TransportTest extends TestCase
{
    public function testUnauthenticatedPrecedesInputAndNeverBuildsPrivilegedContext(): void
    {
        $f = new Fixture();
        $f->authenticated = false;
        $server = $f->server();
        foreach (Fixture::cases()['identity'] as $body) {
            $response = $server->handle(Fixture::request('/v1/search', $body));
            self::assertSame(401, $response->status);
            self::assertSame('unauthenticated', Fixture::wire($response)['error']['code']);
            self::assertArrayNotHasKey('data', Fixture::wire($response));
        }
        $response = $server->handle(new H\Request(method: 'POST', target: '/v1/sources', body: str_repeat('x', 1403001)));
        self::assertSame(401, $response->status);
        self::assertSame([], $f->seen);
    }

    public function testInapplicableExpectedRevisionAndInvalidSelectorsCannotBeSilentlyIgnored(): void
    {
        $f = new Fixture();
        $server = $f->server();
        self::assertSame(400, $server->handle(Fixture::request('/v1/lifecycle/revocations', [
            'sourceId' => Fixture::id(1), 'reason' => 'source_revoked',
        ], ['Idempotency-Key' => 'revoke', 'Expected-Epoch' => '0', 'Expected-Revision' => '0']))->status);
        self::assertSame(400, $server->handle(Fixture::request('/v1/reads/page', [
            'citationId' => Fixture::id(1), 'attemptId' => Fixture::id(2),
        ]))->status);
        self::assertSame(400, $server->handle(Fixture::request('/v1/reads/region', [
            'citationId' => Fixture::id(1), 'attemptId' => Fixture::id(2), 'pageId' => 'p1',
            'bbox' => ['x0' => 100, 'y0' => 100, 'x1' => 0, 'y1' => 0],
        ]))->status);
        self::assertSame([], $f->seen);
    }

    public function testTrustedHostIdentityPrevailsAndSpoofedFieldsNeverReachApplication(): void
    {
        $f = new Fixture();
        $server = $f->server();
        foreach (Fixture::cases()['identity'] as $body) {
            $response = $server->handle(Fixture::request('/v1/search', $body));
            self::assertSame(403, $response->status);
            self::assertStringNotContainsString('FOREIGN_SECRET', $response->body);
            self::assertArrayNotHasKey('data', Fixture::wire($response));
        }
        self::assertSame([], $f->seen);
        self::assertSame(200, $server->handle(Fixture::request('/v1/search', ['query' => 'support']))->status);
        self::assertSame($f->identity->scope, $f->seen[0][1]->scope());
        $wrongKb = $server->handle(Fixture::request('/v1/knowledge-bases/' . Fixture::id(99) . '/collections',
            ['collectionId' => Fixture::id(10)], ['Idempotency-Key' => 'collection', 'Expected-Epoch' => '0']));
        self::assertSame(404, $wrongKb->status);
        self::assertCount(1, $f->seen);
    }

    public function testEveryVersionedRouteValidatesAndForwardsTypedCommand(): void
    {
        $f = new Fixture();
        $server = $f->server();
        $cases = Fixture::cases()['routes'];
        foreach ($cases as $case) {
            $response = $server->handle(Fixture::request(
                path: $case['path'], body: $case['body'], headers: $case['headers'], method: $case['method'],
            ));
            self::assertSame($case['status'], $response->status, $case['operation'] . ': ' . $response->body);
            [$command, $context] = $f->seen[array_key_last($f->seen)];
            self::assertSame($case['operation'], $command->route->operation);
            self::assertSame($f->identity->scope, $context->scope());
            self::assertSame($case['headers']['Idempotency-Key'] ?? null, $command->mutation?->idempotencyKey()->value);
            self::assertSame($case['headers']['Expected-Epoch'] ?? null, $command->mutation?->expectedEpoch()?->value);
            self::assertSame($case['headers']['Expected-Revision'] ?? null, $command->mutation?->expectedRevision()?->value);
            if ($command->query !== null) {
                self::assertSame('support', $command->query->text());
                self::assertInstanceOf(D\SourceVersionId::class, $command->query->sourceVersionIds[0]);
                self::assertInstanceOf(D\CollectionId::class, $command->query->collectionIds[0]);
            }
            if ($command->upload !== null) {
                self::assertSame('synthetic HTTP original', $command->upload->bytes());
                self::assertSame($f->identity->scope->tenantId, $command->upload->ref->tenantId);
            }
            if ($command->disclosure !== null) {
                self::assertNull($command->disclosure->runRef());
            }
        }
        self::assertSame(array_column($cases, 'operation'), array_map(static fn (H\Route $r): string => $r->operation, (new H\Contract())->routes()));
        self::assertCount(29, $f->seen);
    }

    public function testSchemaMismatchAndMalformedBodiesStopBeforeApplication(): void
    {
        $f = new Fixture();
        $server = $f->server();
        $mismatch = $server->handle(Fixture::request('/v1/search', ['query' => 'support'], ['KnowKit-Schema-Version' => '2.0']));
        self::assertSame(409, $mismatch->status);
        self::assertSame('schema_mismatch', Fixture::wire($mismatch)['error']['code']);
        foreach (Fixture::cases()['invalid'] as $body) {
            self::assertSame(400, $server->handle(Fixture::request('/v1/search', $body))->status);
        }
        foreach (['{"query":"one","query":"two"}', '[]', '{', '{"query":"\u0000"}'] as $bytes) {
            self::assertSame(400, $server->handle(new H\Request(
                method: 'POST', target: '/v1/search', headers: ['Content-Type' => 'application/json'], body: $bytes,
            ))->status);
        }
        self::assertSame([], $f->seen);
    }

    public function testUnsafeOriginPathAndSqlFiltersAreRejectedBeforePrivilegedWork(): void
    {
        $f = new Fixture();
        $server = $f->server();
        foreach (Fixture::cases()['unsafeOrigins'] as $origin) {
            $response = $server->handle(Fixture::request('/v1/sources', [
                'sourceId' => Fixture::id(10), 'ownerRef' => 'host:owner', 'origin' => $origin,
            ], ['Idempotency-Key' => 'register']));
            self::assertSame(400, $response->status);
        }
        foreach (['/v1/citations/../../private', '/v1/citations/%2e%2e', '/v1/search?sql=SELECT', 'https://127.0.0.1/v1/search'] as $path) {
            self::assertContains($server->handle(Fixture::request($path))->status, [400, 404]);
        }
        foreach ([['sql' => 'SELECT * FROM secrets'], ['sourceVersionIds' => ["x' OR 1=1"]], ['where' => 'true']] as $filter) {
            self::assertSame(400, $server->handle(Fixture::request('/v1/search', ['query' => 'support', 'filters' => $filter]))->status);
        }
        self::assertSame([], $f->seen);
    }

    public function testOversizedDeclaredChunkedAndDecodedUploadsAreBounded(): void
    {
        $f = new Fixture();
        $server = $f->server();
        $case = array_values(array_filter(Fixture::cases()['routes'], static fn (array $c): bool => $c['operation'] === 'addVersion'))[0];
        $headers = $case['headers'] + ['Content-Type' => 'application/json'];
        $declared = $server->handle(new H\Request(method: 'POST', target: $case['path'],
            headers: $headers + ['Content-Length' => '9999999999999999999'], body: ''));
        self::assertSame(413, $declared->status);
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, str_repeat('x', 1403002));
            rewind($stream);
            $response = $server->handle(new H\Request(method: 'POST', target: $case['path'], headers: $headers, body: $stream));
            self::assertSame(413, $response->status);
            self::assertSame(1403001, ftell($stream));
        } finally {
            fclose($stream);
        }
        $body = $case['body'];
        $body['contentBase64'] = base64_encode(str_repeat('x', H\CommandFactory::MAX_UPLOAD_BYTES + 1));
        self::assertSame(413, $server->handle(Fixture::request($case['path'], $body, $headers))->status);
        $body['contentBase64'] = '!invalid-base64!';
        self::assertSame(400, $server->handle(Fixture::request($case['path'], $body, $headers))->status);
        self::assertSame([], $f->seen);
    }

    public function testMutationHeadersCannotBeOmittedDuplicatedOrSmuggled(): void
    {
        $f = new Fixture();
        $server = $f->server();
        foreach (Fixture::cases()['routes'] as $case) {
            foreach (['Idempotency-Key', 'Expected-Epoch', 'Expected-Revision'] as $name) {
                if (!isset($case['headers'][$name])) {
                    continue;
                }
                $headers = $case['headers'];
                unset($headers[$name]);
                self::assertSame(400, $server->handle(Fixture::request($case['path'], $case['body'], $headers, $case['method']))->status);
                $headers = $case['headers'] + [strtolower($name) => 'different'];
                self::assertSame(400, $server->handle(Fixture::request($case['path'], $case['body'], $headers, $case['method']))->status);
            }
        }
        self::assertSame(400, $server->handle(Fixture::request('/v1/search', ['query' => 'support'], ['Idempotency-Key' => 'smuggled']))->status);
        self::assertSame([], $f->seen);
    }

    public function testDistinctOutcomesCorrelationAndInternalRedaction(): void
    {
        $f = new Fixture();
        $server = $f->server();
        foreach (Fixture::cases()['outcomes'] as $case) {
            $f->handler = static fn (): H\Reply => new H\Reply(
                result: D\Result::failure(failure: new D\DomainFailure(D\Outcome::from($case['outcome']), 'fixture_failure'), retryable: $case['retryable']),
                data: new JsonObject(['text' => 'FOREIGN_SECRET']),
            );
            $response = $server->handle(Fixture::request('/v1/search', ['query' => 'support'], ['Request-ID' => Fixture::id(50)]));
            self::assertSame($case['status'], $response->status);
            self::assertSame($case['outcome'], Fixture::wire($response)['outcome']);
            self::assertSame($case['retryable'], Fixture::wire($response)['error']['retryable']);
            self::assertSame(Fixture::id(50), $response->headers['Request-ID']);
            self::assertSame('no-store', $response->headers['Cache-Control']);
            self::assertStringNotContainsString('FOREIGN_SECRET', $response->body);
            self::assertArrayNotHasKey('data', Fixture::wire($response));
        }
        $f->handler = static fn (): H\Reply => new H\Reply(D\Result::empty());
        $empty = $server->handle(Fixture::request('/v1/search', ['query' => 'support']));
        self::assertSame(200, $empty->status);
        self::assertSame([], Fixture::wire($empty)['data']);
        $f->handler = static function (): never {
            echo 'FOREIGN_SECRET /private/provider-input';
            throw new \RuntimeException('FOREIGN_SECRET');
        };
        ob_start();
        $failed = $server->handle(Fixture::request('/v1/search', ['query' => 'support'], ['Request-ID' => "unsafe\r\nHeader: value"]));
        self::assertSame('', ob_get_clean());
        self::assertSame(500, $failed->status);
        self::assertStringNotContainsString('FOREIGN_SECRET', $failed->body);
        self::assertInstanceOf(D\Ulid::class, new D\Ulid($failed->headers['Request-ID']));
        self::assertStringNotContainsString('provider-input', $failed->body);
        $f->handler = static function (): never {
            throw new \InvalidArgumentException('internal host validation detail');
        };
        self::assertSame(500, $server->handle(Fixture::request('/v1/search', ['query' => 'support']))->status);
        $f->handler = static function (): never {
            throw new H\InputFailure(status: 429, errorCode: 'rate_quota');
        };
        self::assertSame(429, $server->handle(Fixture::request('/v1/search', ['query' => 'support']))->status);
    }

    public function testOptionalExpectedRevisionIsPreservedOnSupportedMutationRoutes(): void
    {
        $f = new Fixture();
        $server = $f->server();
        foreach (Fixture::cases()['routes'] as $case) {
            if (!in_array($case['operation'], ['createKnowledgeBase', 'createCollection', 'addMembership', 'registerSource', 'addVersion'], true)) {
                continue;
            }
            $case['headers']['Expected-Revision'] = '17';
            self::assertSame($case['status'], $server->handle(Fixture::request($case['path'], $case['body'], $case['headers']))->status);
            [$command] = $f->seen[array_key_last($f->seen)];
            self::assertSame('17', $command->mutation->expectedRevision()->value);
        }
    }
}
