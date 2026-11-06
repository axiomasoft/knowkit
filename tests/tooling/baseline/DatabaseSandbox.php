<?php
declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use KnowKit\Tooling\Qa;
use PDO;

/** Test infrastructure only. It never migrates or resets application/public tables. */
final class DatabaseSandbox
{
    public readonly PDO $connection;
    public readonly string $schema;

    public function __construct()
    {
        $dsn = getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test';
        Qa::ensure((bool) preg_match('/^pgsql:.*(?:^|;)dbname=[a-zA-Z0-9_]+_test(?:;|$)/', $dsn), 'explicit disposable *_test database required');
        $this->connection = new PDO($dsn, getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (int) $this->connection->query('SHOW server_version_num')->fetchColumn();
        Qa::ensure($version >= 160000 && $version < 180000, 'PostgreSQL 16/17 test lane required');
        $this->schema = 'knowkit_qa_' . bin2hex(random_bytes(12));
    }

    public function run(callable $test): mixed
    {
        $this->connection->exec('CREATE SCHEMA ' . $this->schema);
        try {
            $this->connection->exec('SET search_path TO ' . $this->schema);
            return $test($this);
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            $this->connection->exec('SET search_path TO public');
            $this->connection->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function reset(): void
    {
        $this->connection->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        $this->connection->exec('CREATE SCHEMA ' . $this->schema);
        $this->connection->exec('SET search_path TO ' . $this->schema);
    }

    public function exists(): bool
    {
        $query = $this->connection->prepare('SELECT count(*) FROM pg_namespace WHERE nspname = ?');
        $query->execute([$this->schema]);
        return (int) $query->fetchColumn() !== 0;
    }
}
