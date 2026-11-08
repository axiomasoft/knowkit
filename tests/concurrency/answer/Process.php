<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Domain\Contract\Jcs;

/** Separate PHP interpreter and PostgreSQL session, controlled by bounded pipe barriers. */
final class Process
{
    private mixed $process = null;
    private array $pipes = [];

    public function __construct(
        ReplayFixture $fixture,
        string $mode,
        string $worker,
    ) {
        $role = $fixture->db->pdo->query('SELECT current_user')->fetchColumn();
        $command = [PHP_BINARY, __DIR__ . '/worker.php', $fixture->db->schema, $role, $fixture->blobRoot,
            Jcs::canonicalize($fixture->scope->toWire()), $mode, $worker];
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Cannot start independent PHP process');
        }
    }

    public function line(): string
    {
        $read = [$this->pipes[1]];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 15) !== 1) {
            throw new \RuntimeException('Process barrier timed out');
        }
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new \RuntimeException('Process ended before barrier: ' . stream_get_contents($this->pipes[2]));
        }

        return trim($line);
    }

    public function send(string $message = 'GO'): void
    {
        fwrite($this->pipes[0], $message . "\n");
        fflush($this->pipes[0]);
    }

    public function result(): array
    {
        return json_decode($this->line(), true, 32, JSON_THROW_ON_ERROR);
    }

    public function close(bool $kill = false): int
    {
        if (!is_resource($this->process)) {
            return 0;
        }
        if ($kill) {
            proc_terminate($this->process, 9);
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($this->process);
        $this->process = null;

        return $exit;
    }

    public function __destruct()
    {
        $this->close(true);
    }
}
