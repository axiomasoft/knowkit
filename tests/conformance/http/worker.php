<?php

declare(strict_types=1);

use KnowKit\Domain\Contract\Jcs;
use KnowKit\Http\Server as H;
use KnowKit\Tests\Conformance\Http\Host;
use KnowKit\Tests\Retrieval\Reading\Fixture as ReadingFixture;

require_once __DIR__ . '/Host.php';
require_once dirname(__DIR__, 3) . '/tools/qa.php';

/** Test-only HTTP framing, separate from production SAPI. No framework or model service. */
function serve(Host $host): void
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if (!is_resource($listener)) {
        throw new RuntimeException('Loopback unavailable');
    }
    fwrite(STDOUT, stream_socket_get_name($listener, false) . "\n");
    fflush(STDOUT);
    stream_set_blocking(STDIN, false);
    try {
        while (!feof(STDIN)) {
            $read = [$listener, STDIN];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 1);
            if (in_array(STDIN, $read, true) && fread(STDIN, 8192) === '' && feof(STDIN)) {
                break;
            }
            if (!in_array($listener, $read, true)) {
                continue;
            }
            $socket = stream_socket_accept($listener, 1);
            try {
                stream_set_timeout($socket, 5);
                [$method, $target] = explode(' ', trim(fgets($socket)), 3);
                $headers = [];
                while (($line = fgets($socket)) !== false && trim($line) !== '') {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[$name] = trim($value);
                }
                $body = '';
                $length = (int) ($headers['Content-Length'] ?? 0);
                while (strlen($body) < $length) {
                    $body .= fread($socket, $length - strlen($body));
                }
                if ($target === '/v1/fixture-control') {
                    $action = json_decode($body, true, 32, JSON_THROW_ON_ERROR)['action'];
                    $response = new H\Response(status: 200, body: Jcs::canonicalize($host->control($action)), headers: []);
                } else {
                    ++$host->requests;
                    $response = $host->server->handle(new H\Request(method: $method, target: $target, headers: $headers, body: $body));
                    $response = fault(response: $response, kind: $host->wireFault, stream: $socket);
                }
                $output = 'HTTP/1.1 ' . $response->status . " Fixture\r\nConnection: close\r\nContent-Length: " . strlen($response->body) . "\r\n";
                foreach ($response->headers as $name => $value) {
                    $output .= $name . ': ' . $value . "\r\n";
                }
                $output .= "\r\n" . $response->body;
                while ($output !== '') {
                    $written = @fwrite($socket, $output);
                    if ($written === false || $written === 0) {
                        break;
                    }
                    $output = substr($output, $written);
                }
            } finally {
                fclose($socket);
            }
        }
    } finally {
        fclose($listener);
    }
}

function fault(H\Response $response, string $kind, mixed $stream): H\Response
{
    if ($kind === 'slow') {
        // The client deadline is far below a fixed 200ms hold. Wait until that peer closes, and cap
        // the wait so a missed EOF cannot exceed the caller's one-second bound. A non-blocking
        // fread of '' is not EOF.
        stream_set_blocking($stream, false);
        $deadline = hrtime(true) + 250_000_000;
        while (!feof($stream) && hrtime(true) < $deadline) {
            $read = [$stream];
            $write = null;
            $except = null;
            $left = $deadline - hrtime(true);
            $ready = @stream_select($read, $write, $except, intdiv($left, 1_000_000_000), max(0, intdiv($left % 1_000_000_000, 1_000)));
            if ($ready === false) {
                break;
            }
            if ($ready > 0 && (@fread($stream, 8192) === false || feof($stream))) {
                break;
            }
        }
        stream_set_blocking($stream, true);
    }
    return match ($kind) {
        'redirect' => new H\Response(status: 307, body: '', headers: ['Location' => '/v1/search']),
        'oversized' => new H\Response(status: 200, body: str_repeat('x', 8388609), headers: $response->headers),
        'duplicate-header' => new H\Response(status: 200, body: $response->body, headers: $response->headers + ['content-type' => 'text/plain']),
        default => $response,
    };
}

if (($argv[1] ?? '') === 'reading') {
    ReadingFixture::run(static fn (ReadingFixture $fixture) => serve(new Host($fixture)));
} else {
    serve(new Host());
}
