<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use PHPUnit\Framework\Assert;

/** Trusted test IPC only. Capabilities/configuration go through stdin, never argv or evidence. */
final class Process
{
    private mixed $process;
    private array $pipes = [];
    public readonly int $pid;
    public readonly int $backend;

    public function __construct(Connection $db, string $operation, array $options = [])
    {
        $config = $options + [
            'schema' => $db->schema,
            'role' => $db->execute('SELECT current_user')->fetchColumn(),
            'operation' => $operation,
        ];
        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
        );
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Independent PHP interpreter required');
        }
        stream_set_timeout($this->pipes[1], 15);
        fwrite($this->pipes[0], base64_encode(serialize($config)) . "\n");
        fflush($this->pipes[0]);
        $ready = $this->receive('READY');
        $this->pid = $ready['pid'];
        $this->backend = $ready['backend'];
        Assert::assertNotSame(getmypid(), $this->pid);
        Assert::assertNotSame((int) $db->execute('SELECT pg_backend_pid()')->fetchColumn(), $this->backend);
    }

    public function go(): void
    {
        fwrite($this->pipes[0], "GO\n");
        fflush($this->pipes[0]);
    }

    public function receive(string $event): array
    {
        $read = [$this->pipes[1], $this->pipes[2]];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 15) === 0) {
            throw new \RuntimeException('IPC barrier deadline exceeded: ' . $event);
        }
        if (in_array($this->pipes[2], $read, true)) {
            $error = fread($this->pipes[2], 8192);
            if ($error !== '') {
                throw new \RuntimeException('Fault worker failed: ' . $error);
            }
        }
        $line = fgets($this->pipes[1], 65536);
        if ($line === false) {
            throw new \RuntimeException('Worker exited before ' . $event);
        }
        $message = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        Assert::assertSame($event, $message['event'], $line);

        return $message;
    }

    public function result(): array
    {
        $result = $this->receive('RESULT');
        Assert::assertSame(0, $this->close());

        return $result['result'];
    }

    public function kill(): void
    {
        Assert::assertTrue(proc_terminate($this->process, 9));
        // EOF is a process-exit barrier, not a timed guess that SIGKILL has arrived.
        Assert::assertFalse(fgets($this->pipes[1]));
        $deadline = hrtime(true) + 1_000_000_000;
        do {
            $status = proc_get_status($this->process);
        } while ($status['running'] && hrtime(true) < $deadline);
        Assert::assertFalse($status['running'], 'Killed worker did not terminate');
        Assert::assertTrue($status['signaled']);
        Assert::assertSame(9, $status['termsig']);
        $this->close();
    }

    private function close(): int
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        $exit = proc_close($this->process);
        $this->process = null;

        return $exit;
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            $this->close();
        }
    }

    public static function emit(string $event, array $data = []): void
    {
        fwrite(STDOUT, json_encode(['event' => $event] + $data, JSON_THROW_ON_ERROR) . "\n");
        fflush(STDOUT);
    }

    public static function barrier(string $event, array $data = []): void
    {
        self::emit(event: $event, data: $data);
        $read = [STDIN];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 15) !== 1 || trim((string) fgets(STDIN)) !== 'GO') {
            throw new \RuntimeException('Interrupted IPC barrier: ' . $event);
        }
    }
}
