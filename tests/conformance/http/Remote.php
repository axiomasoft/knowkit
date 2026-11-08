<?php

declare(strict_types=1);

namespace KnowKit\Tests\Conformance\Http;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Http\Client as C;
use KnowKit\Http\Server as H;
use KnowKit\Tooling\Qa;

require_once __DIR__ . '/Host.php';

/** A persistent child owns its fixtures and database connections for the entire scenario. */
final class Remote
{
    private mixed $process;
    private array $pipes = [];
    private string $temporary;
    public C\StreamTransport $transport;
    public C\Client $client;

    public function __construct(string $kind = 'search')
    {
        $this->temporary = Qa::temporary();
        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/worker.php', $kind],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->temporary . '/stderr', 'w']],
            $this->pipes, dirname(__DIR__, 3),
        );
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Remote fixture could not start');
        }
        stream_set_timeout($this->pipes[1], 20);
        $address = trim((string) fgets($this->pipes[1]));
        if (preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address) !== 1) {
            $error = file_get_contents($this->temporary . '/stderr');
            $this->close();
            throw new \RuntimeException('Required remote backend unavailable: ' . $error);
        }
        $this->transport = new C\StreamTransport('http://' . $address);
        $this->client = new C\Client(transport: $this->transport);
    }

    public function control(string $action): JsonObject
    {
        $wire = $this->transport->send(request: new H\Request(
            method: 'POST', target: '/v1/fixture-control', headers: ['Content-Type' => 'application/json'],
            body: json_encode(['action' => $action], JSON_THROW_ON_ERROR),
        ), timeoutMs: 5000);

        return StrictJson::decode($wire->body);
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            fclose($this->pipes[0]);
            fclose($this->pipes[1]);
            proc_close($this->process);
        }
        Qa::remove($this->temporary);
    }
}
