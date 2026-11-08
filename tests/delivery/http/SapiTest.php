<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Http;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:http')]
final class SapiTest extends TestCase
{
    public function testRealHttpRequestsReachExplicitSapiAndKeepStatusAndCorrelation(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertIsResource($listener, $message);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $process = proc_open(
            [PHP_BINARY, '-S', $address, 'tests/fixtures/http/v1/router.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Fixture::root(),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        try {
            $this->ready($address);
            $unauthenticated = $this->http(address: $address, method: 'POST', path: '/v1/search', body: '{"tenantId":"foreign"}');
            self::assertStringStartsWith('HTTP/1.1 401', $unauthenticated);
            self::assertStringContainsString('"code":"unauthenticated"', $unauthenticated);
            $search = $this->http(address: $address, method: 'POST', path: '/v1/search', body: '{"query":"support"}', authenticated: true);
            self::assertStringStartsWith('HTTP/1.1 200', $search);
            self::assertStringContainsString('Request-ID: ' . Fixture::id(50), $search);
            self::assertStringContainsString('Cache-Control: no-store', $search);
            self::assertStringContainsString('"operation":"search"', $search);
            $invalid = $this->http(address: $address, method: 'POST', path: '/v1/search', body: '{"query":"support","filters":{"sql":"SELECT"}}', authenticated: true);
            self::assertStringStartsWith('HTTP/1.1 400', $invalid);
            $foreign = $this->http(address: $address, method: 'GET', path: '/v1/citations/%2e%2e%2fsecret', authenticated: true);
            self::assertStringStartsWith('HTTP/1.1 400', $foreign);
            self::assertStringNotContainsString('secret', $foreign);
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    private function ready(string $address): void
    {
        $deadline = microtime(true) + 5;
        do {
            $socket = @stream_socket_client('tcp://' . $address, $error, $message, 0.05);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('HTTP fixture server did not start');
    }

    private function http(string $address, string $method, string $path, string $body = '', bool $authenticated = false): string
    {
        $socket = stream_socket_client('tcp://' . $address, $error, $message, 5);
        self::assertIsResource($socket, $message);
        stream_set_timeout($socket, 5);
        try {
            $headers = "{$method} {$path} HTTP/1.1\r\nHost: {$address}\r\nConnection: close\r\nContent-Type: application/json\r\n";
            $headers .= 'Request-ID: ' . Fixture::id(50) . "\r\nContent-Length: " . strlen($body) . "\r\n";
            if ($authenticated) {
                $headers .= "Authorization: Bearer synthetic-http-test\r\n";
            }
            fwrite($socket, $headers . "\r\n" . $body);

            return stream_get_contents($socket);
        } finally {
            fclose($socket);
        }
    }
}
