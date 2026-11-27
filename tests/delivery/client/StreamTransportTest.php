<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Client;

use KnowKit\Domain as D;
use KnowKit\Http\Client as C;
use KnowKit\Http\Server\Request;
use KnowKit\Tests\Conformance\Http\Remote;
use KnowKit\Tests\Delivery\Http\Fixture as F;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/conformance/http/Remote.php';

#[Group('delivery:client')]
final class StreamTransportTest extends TestCase
{
    public function testNativeHttpTypedSearchAndImmutableSelection(): void
    {
        $remote = new Remote();
        try {
            $response = $remote->client->search(query: new D\Query('gravity'), limit: new D\Limit(1), requestId: new D\Ulid(F::id(800)));
            self::assertSame(D\Outcome::Success, $response->outcome);
            self::assertSame(F::id(800), $response->requestId->value);
            $selection = $response->selection(F::validator());
            self::assertCount(1, $selection->candidateRefs());
            self::assertSame(str_repeat('a', 64), $selection->toWire()->get('hostScopeHash'));
            $changed = $response->data()->with('selection', null);
            self::assertNull($changed->get('selection'));
            self::assertNotNull($response->selection(F::validator()));
        } finally {
            $remote->close();
        }
    }

    public function testRedirectOversizeAndAmbiguousHeadersFailClosed(): void
    {
        foreach (['redirect', 'oversized', 'duplicate-header'] as $fault) {
            $remote = new Remote();
            try {
                $remote->control('wire:' . $fault);
                try {
                    $remote->client->search(query: new D\Query('gravity'), limit: new D\Limit(1), requestId: new D\Ulid(F::id(800)));
                    self::fail('Unsafe remote response accepted: ' . $fault);
                } catch (C\ProtocolFailure) {
                    self::assertSame(1, $remote->control('stats')->get('requests'), 'No redirects or protocol retries');
                }
            } finally {
                $remote->close();
            }
        }
    }

    public function testNativeSocketTimeoutIsBoundedAndHasNoPlaintext(): void
    {
        $remote = new Remote();
        try {
            $remote->control('wire:slow');
            $client = new C\Client(transport: $remote->transport, options: new C\Options(timeoutMs: 20, maxAttempts: 1));
            $start = hrtime(true);
            try {
                $client->search(query: new D\Query('gravity'), limit: new D\Limit(1), requestId: new D\Ulid(F::id(800)));
                self::fail('Late response accepted');
            } catch (C\TransportFailure $failure) {
                self::assertTrue($failure->timedOut);
                self::assertLessThan(1000000000, hrtime(true) - $start);
                self::assertStringNotContainsString('gravity', $failure->getMessage());
            }
        } finally {
            $remote->close();
        }
    }

    public function testCredentialAndHeaderInjectionAreRejectedLocally(): void
    {
        foreach ([
            ['https://user:secret@example.invalid', []],
            ['file:///secret', []],
            ['https://example.invalid#secret', []],
            ['https://example.invalid', ['Authorization' => "Bearer secret\r\nInjected: yes"]],
            ['https://example.invalid', ['KnowKit-Schema-Version' => '2.0']],
        ] as [$url, $headers]) {
            try {
                new C\StreamTransport(baseUrl: $url, authenticationHeaders: $headers);
                self::fail('Invalid transport configuration accepted');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringNotContainsString('secret', $failure->getMessage());
            }
        }
        $transport = new C\StreamTransport(baseUrl: 'https://example.invalid', authenticationHeaders: ['Authorization' => 'Bearer secret']);
        ob_start();
        var_dump($transport);
        $debug = ob_get_clean();
        self::assertStringNotContainsString('secret', $debug);
        self::assertStringNotContainsString('example.invalid', $debug);
    }

    public function testMalformedRequestLinesAreRejectedBeforeNetworkIo(): void
    {
        $transport = new C\StreamTransport(baseUrl: 'http://127.0.0.1:1');
        foreach ([
            ["GET\r\nInjected: yes", '/v1/search'],
            ['GET HTTP/1.1', '/v1/search'],
            ['GET', "/v1/search\r\nInjected: yes"],
            ['GET', '/v1/search HTTP/1.1'],
            ['GET', '/v1/' . str_repeat('a', 2048)],
        ] as [$method, $target]) {
            try {
                $transport->send(new Request(method: $method, target: $target), 20);
                self::fail('Malformed request line accepted');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('Bounded versioned request required', $failure->getMessage());
            }
        }
    }
}
