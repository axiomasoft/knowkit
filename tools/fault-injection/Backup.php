<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use PHPUnit\Framework\Assert;

/** Physical test artifacts are private temporary files; only the disposable schema is dumped/restored. */
final class Backup
{
    public readonly string $directory;
    private array $environment;
    private string $binaryDirectory;

    public function __construct(
        private readonly Connection $db,
    ) {
        $this->directory = sys_get_temp_dir() . '/knowkit-resilience-' . bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        $this->environment = getenv();
        $dsn = getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
        foreach (explode(';', substr($dsn, 6)) as $component) {
            [$name, $value] = explode('=', $component, 2);
            $variable = ['host' => 'PGHOST', 'port' => 'PGPORT', 'dbname' => 'PGDATABASE', 'sslmode' => 'PGSSLMODE'][$name] ?? null;
            if ($variable === null) {
                throw new \RuntimeException('Unsupported disposable PostgreSQL DSN option');
            }
            $this->environment[$variable] = $value;
        }
        $this->environment['PGUSER'] = getenv('TEST_PG_USER') ?: 'knowkit_test';
        $this->environment['PGPASSWORD'] = getenv('TEST_PG_PASSWORD') ?: '';
        $this->environment['PGCONNECT_TIMEOUT'] = '5';
        $configured = getenv('TEST_PG_BIN');
        if ($configured === false || $configured === '') {
            $configured = trim($this->command(['pg_config', '--bindir']));
            $major = intdiv((int) $db->execute('SHOW server_version_num')->fetchColumn(), 10000);
            $matching = dirname($configured, 2) . '/' . $major . '/bin';
            if (is_dir($matching)) {
                $configured = $matching;
            }
        }
        $this->binaryDirectory = rtrim($configured, '/');
    }

    public function capture(string $blob): void
    {
        $this->command([$this->binaryDirectory . '/pg_dump', '--format=custom', '--no-owner', '--no-acl', '--schema=' . $this->db->schema,
            '--file=' . $this->directory . '/metadata.dump']);
        Assert::assertTrue(copy($blob, $this->directory . '/blob'));
        chmod($this->directory . '/blob', 0400);
        Assert::assertSame(hash_file('sha256', $blob), hash_file('sha256', $this->directory . '/blob'));
    }

    public function restore(string $blob): void
    {
        $role = $this->db->execute('SELECT current_user')->fetchColumn();
        if (!is_string($role)) {
            throw new \RuntimeException('Current database role unavailable');
        }
        Connection::schema($role);
        $this->command([$this->binaryDirectory . '/pg_restore', '--clean', '--if-exists', '--no-owner', '--no-acl', '--exit-on-error',
            '--single-transaction', '--dbname=' . $this->environment['PGDATABASE'], $this->directory . '/metadata.dump']);
        $this->db->pdo->exec('RESET ROLE');
        try {
            $this->db->pdo->exec('GRANT USAGE ON SCHEMA ' . $this->db->schema . ' TO ' . $role);
            $this->db->pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA ' . $this->db->schema . ' TO ' . $role);
        } finally {
            $this->db->pdo->exec('SET ROLE ' . $role);
        }
        if (is_file($blob)) {
            unlink($blob);
        }
        Assert::assertTrue(copy($this->directory . '/blob', $blob));
        chmod($blob, 0400);
    }

    /** @param list<string> $arguments */
    private function command(array $arguments): string
    {
        $pipes = [];
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Required PostgreSQL backup executable unavailable');
        }
        $output = $error = '';
        $completed = false;
        try {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $pending = [1 => $pipes[1], 2 => $pipes[2]];
            $deadline = hrtime(true) + 15_000_000_000;
            while ($pending !== []) {
                $read = array_values($pending);
                $write = $except = null;
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    throw new \RuntimeException('Backup command deadline exceeded');
                }
                $selected = stream_select($read, $write, $except, 0, min(100000, (int) ceil($remaining / 1000)));
                if ($selected === false) {
                    throw new \RuntimeException('Cannot monitor backup command');
                }
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Cannot read backup command output');
                    }
                    if ($pipe === $pipes[1]) {
                        $output .= $chunk;
                    } else {
                        $error .= $chunk;
                    }
                    if (feof($pipe)) {
                        unset($pending[$pipe === $pipes[1] ? 1 : 2]);
                    }
                }
            }
            $completed = true;
        } finally {
            if (!$completed) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        }
        Assert::assertSame(0, $exit, $output . $error);

        return $output;
    }

    public function __destruct()
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->directory);
    }
}
