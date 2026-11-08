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
                    $response = fault(response: $response, kind: $host->wireFault);
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

function fault(H\Response $response, string $kind): H\Response
{
    if ($kind === 'slow') {
        usleep(200000);
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
