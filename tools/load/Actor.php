<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Tooling\Qa;

/** Trusted development IPC. Configuration is never placed in argv or measurement reports. */
final class Actor
{
    private mixed $process = null;
    private array $pipes = [];
    public readonly int $backend;

    public function __construct(Connection $db, array $config)
    {
        $config += ['schema' => $db->schema, 'role' => $db->execute('SELECT current_user')->fetchColumn()];
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/actor.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        Qa::ensure(is_resource($this->process), 'load actor unavailable');
        stream_set_timeout($this->pipes[1], 120);
        fwrite($this->pipes[0], base64_encode(serialize($config)) . "\n");
        fflush($this->pipes[0]);
        $ready = $this->receive();
        Qa::ensure($ready['event'] === 'ready', 'missing actor ready barrier');
        $this->backend = $ready['backend'];
    }

    public function go(): void
    {
        fwrite($this->pipes[0], "GO\n");
        fflush($this->pipes[0]);
    }

    private function receive(): array
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new \RuntimeException('Load actor exited without a result: ' . stream_get_contents($this->pipes[2]));
        }
        $result = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
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
        if (!is_resource($this->process)) {
            return;
        }
        proc_terminate($this->process, 9);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->process);
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
        fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        fflush(STDOUT);
    }
}
