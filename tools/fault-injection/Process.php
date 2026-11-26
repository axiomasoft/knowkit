<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__) . '/LocalIO.php';

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
        try {
            stream_set_timeout($this->pipes[1], 15);
            // fgets() stores at most length-1 bytes. A short write would otherwise stall the worker on a partial frame.
            $frame = base64_encode(serialize($config)) . "\n";
            if (strlen($frame) > \KnowKit\Tooling\LocalIO::LINE_LIMIT - 1) {
                throw new \RuntimeException('fault worker configuration exceeds IPC bound');
            }
            self::write($this->pipes[0], $frame);
            $ready = $this->receive('READY');
            $this->pid = $ready['pid'];
            $this->backend = $ready['backend'];
            Assert::assertNotSame(getmypid(), $this->pid);
            Assert::assertNotSame((int) $db->execute('SELECT pg_backend_pid()')->fetchColumn(), $this->backend);
        } catch (\Throwable $failure) {
            // A constructor exception does not run __destruct, so the child must be stopped here.
            $this->stop();
            throw $failure;
        }
    }

    public function go(): void
    {
        self::write($this->pipes[0], "GO\n");
    }

    /** @param resource $stream */
    private static function write(mixed $stream, string $frame): void
    {
        $offset = 0;
        $length = strlen($frame);
        while ($offset < $length) {
            $written = fwrite($stream, substr($frame, $offset));
            if ($written === false || $written < 1) {
                throw new \RuntimeException('fault worker IPC write failed');
            }
            $offset += $written;
        }
        if (!fflush($stream)) {
            throw new \RuntimeException('fault worker IPC flush failed');
        }
    }

    public function receive(string $event): array
    {
        $deadline = hrtime(true) + 15_000_000_000;
        $stderrOpen = true;
        while (true) {
            $read = $stderrOpen ? [$this->pipes[1], $this->pipes[2]] : [$this->pipes[1]];
            $write = $except = null;
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException('IPC barrier deadline exceeded: ' . $event);
            }
            $selected = stream_select($read, $write, $except, 0, min(100000, (int) ceil($remaining / 1000)));
            if ($selected === false) {
                throw new \RuntimeException('Cannot monitor IPC barrier: ' . $event);
            }
            if ($selected === 0) {
                continue;
            }
            if (in_array($this->pipes[2], $read, true)) {
                $error = fread($this->pipes[2], 8192);
                if ($error === false) {
                    throw new \RuntimeException('Cannot read fault worker stderr');
                }
                if ($error !== '') {
                    throw new \RuntimeException('Fault worker failed: ' . $error);
                }
                $stderrOpen = !feof($this->pipes[2]);
            }
            if (!in_array($this->pipes[1], $read, true)) {
                continue;
            }
            $line = fgets($this->pipes[1], 65536);
            if ($line === false) {
                throw new \RuntimeException('Worker exited before ' . $event);
            }
            break;
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
        $this->stop();
    }

    private function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        proc_terminate($this->process, 9);
        $this->close();
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
