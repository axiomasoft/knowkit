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
        self::assertDisposableDsn($dsn);
        $this->connection = new PDO($dsn, getenv('TEST_PG_USER') ?: 'knowkit_test', getenv('TEST_PG_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $this->connection->query('SHOW server_version_num');
        Qa::ensure($stmt !== false, 'cannot query PostgreSQL version');
        $version = (int) $stmt->fetchColumn();
        Qa::ensure($version >= 160000 && $version < 180000, 'PostgreSQL 16/17 test lane required');
        $this->schema = 'knowkit_qa_' . bin2hex(random_bytes(12));
    }

    /** Reject before connect. pdo_pgsql keeps the last dbname after turning ';' into spaces. */
    public static function assertDisposableDsn(string $dsn): void
    {
        $database = self::effectiveDatabase($dsn);
        Qa::ensure(is_string($database) && preg_match('/^[a-zA-Z0-9_]+_test$/', $database) === 1,
            'explicit disposable *_test database required');
    }

    private static function effectiveDatabase(string $dsn): ?string
    {
        if (!str_starts_with($dsn, 'pgsql:')) {
            return null;
        }
        $conninfo = str_replace(';', ' ', substr($dsn, 6));
        if (str_contains($conninfo, '\\')) {
            return null;
        }
        $trimmed = ltrim($conninfo);
        if (str_starts_with($trimmed, 'postgresql://') || str_starts_with($trimmed, 'postgres://')) {
            return null;
        }
        $length = strlen($conninfo);
        $index = 0;
        $database = null;
        while ($index < $length) {
            while ($index < $length && self::isConninfoSpace($conninfo[$index])) {
                $index++;
            }
            if ($index >= $length) {
                break;
            }
            $keyStart = $index;
            while ($index < $length && $conninfo[$index] !== '=' && !self::isConninfoSpace($conninfo[$index])) {
                $index++;
            }
            if ($index === $keyStart) {
                return null;
            }
            $key = substr($conninfo, $keyStart, $index - $keyStart);
            while ($index < $length && self::isConninfoSpace($conninfo[$index])) {
                $index++;
            }
            if ($index >= $length || $conninfo[$index] !== '=') {
                return null;
            }
            $index++;
            while ($index < $length && self::isConninfoSpace($conninfo[$index])) {
                $index++;
            }
            if ($index < $length && $conninfo[$index] === "'") {
                $index++;
                $value = '';
                $closed = false;
                while ($index < $length) {
                    if ($conninfo[$index] === "'") {
                        if ($index + 1 < $length && $conninfo[$index + 1] === "'") {
                            $value .= "'";
                            $index += 2;
                            continue;
                        }
                        $index++;
                        $closed = true;
                        break;
                    }
                    $value .= $conninfo[$index];
                    $index++;
                }
                if (!$closed) {
                    return null;
                }
            } else {
                $valueStart = $index;
                while ($index < $length && !self::isConninfoSpace($conninfo[$index])) {
                    $index++;
                }
                $value = substr($conninfo, $valueStart, $index - $valueStart);
            }
            if (strcasecmp($key, 'dbname') === 0) {
                $database = $value;
            }
        }
        return $database;
    }

    private static function isConninfoSpace(string $character): bool
    {
        return $character === ' ' || $character === "\t" || $character === "\n"
            || $character === "\r" || $character === "\f" || $character === "\v";
    }

    /** @param callable(self): mixed $test */
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
