<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Tooling\Qa;

/** Trusted development IPC. Configuration is never placed in argv or measurement reports. */
final class Actor
{
    public const CONFIG_MAX_BYTES = 16777216;
    public const RESPONSE_MAX_BYTES = 1048576;

    private mixed $process = null;
    private array $pipes = [];
    public readonly int $backend;

    public function __construct(Connection $db, array $config)
    {
        $config += ['schema' => $db->schema, 'role' => $db->execute('SELECT current_user')->fetchColumn()];
        $frame = self::configurationFrame($config);
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/actor.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        Qa::ensure(is_resource($this->process), 'load actor unavailable');
        try {
            stream_set_timeout($this->pipes[1], 120);
            self::write($this->pipes[0], $frame);
            $ready = $this->receive();
            Qa::ensure($ready['event'] === 'ready', 'missing actor ready barrier');
            $this->backend = $ready['backend'];
        } catch (\Throwable $failure) {
            $this->stop();
            throw $failure;
        }
    }

    public function go(): void
    {
        self::write($this->pipes[0], "GO\n");
    }

    public static function configurationFrame(array $config): string
    {
        $encoded = base64_encode(serialize($config));
        Qa::ensure(strlen($encoded) <= self::CONFIG_MAX_BYTES, 'load actor configuration exceeds IPC bound');
        return $encoded . "\n";
    }

    /** @param resource $stream Trusted local process only; serialized domain objects are required. */
    public static function readConfiguration(mixed $stream): array
    {
        $frame = \KnowKit\Tooling\LocalIO::line($stream, self::CONFIG_MAX_BYTES + 2);
        Qa::ensure(str_ends_with($frame, "\n") && strlen($frame) <= self::CONFIG_MAX_BYTES + 1,
            'incomplete or oversized load actor configuration');
        $config = @unserialize(\KnowKit\Tooling\LocalIO::decode(substr($frame, 0, -1)), ['allowed_classes' => true]);
        Qa::ensure(is_array($config), 'invalid load actor configuration');
        return $config;
    }

    /** @param resource $stream */
    private static function write(mixed $stream, string $frame): void
    {
        $offset = 0;
        while ($offset < strlen($frame)) {
            $written = @fwrite($stream, substr($frame, $offset));
            Qa::ensure($written !== false && $written > 0, 'load actor IPC write failed');
            $offset += $written;
        }
        Qa::ensure(fflush($stream), 'load actor IPC flush failed');
    }

    private function receive(): array
    {
        return self::readResponse($this->pipes[1], $this->pipes[2]);
    }

    /**
     * @param resource $output
     * @param resource $errors
     */
    public static function readResponse(mixed $output, mixed $errors): array
    {
        $line = fgets($output, self::RESPONSE_MAX_BYTES + 2);
        if ($line === false) {
            // A child may close stdout while keeping stderr open. Diagnostics must not
            // block failure cleanup or retain an unbounded amount of child output.
            stream_set_blocking($errors, false);
            $diagnostic = stream_get_contents($errors, 8192);
            throw new \RuntimeException('Load actor exited without a result: ' . ($diagnostic === false ? '' : $diagnostic));
        }
        Qa::ensure(str_ends_with($line, "\n") && strlen($line) <= self::RESPONSE_MAX_BYTES + 1,
            'incomplete or oversized load actor response');
        $result = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        Qa::ensure(is_array($result), 'invalid load actor response');
        Qa::ensure(!isset($result['failure']), 'load actor failure: ' . ($result['failure'] ?? ''));

        return $result;
    }

    public function result(): array
    {
        $result = $this->receive();
        Qa::ensure($result['event'] === 'result', 'missing actor result barrier');
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        Qa::ensure(proc_close($this->process) === 0, 'load actor nonzero exit');
        $this->process = null;

        return $result['data'];
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
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
        $this->process = null;
    }

    public static function connect(array $config): Connection
    {
        Connection::schema($config['schema']);
        Connection::schema($config['role']);
        $pdo = new \PDO(getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
            getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET ROLE ' . $config['role']);
        $pdo->exec('SET search_path TO ' . $config['schema']);

        return new Connection(pdo: $pdo, schema: $config['schema']);
    }

    public static function emit(array $message): void
    {
        self::write(STDOUT, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
    }
}
