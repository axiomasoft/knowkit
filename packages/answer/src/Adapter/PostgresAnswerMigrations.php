<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use PDO;

/** Explicit optional setup only. Construction and Composer never install tables. */
final readonly class PostgresAnswerMigrations
{
    public function __construct(
        private PDO $pdo,
        private string $schema,
    ) {
        Connection::schema($schema);
    }

    public function apply(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Migration owns transaction');
        }
        $this->pdo->beginTransaction();
        try {
            foreach (['001_answer_invocations', '002_observation_payloads'] as $migration) {
                $sql = file_get_contents(dirname(__DIR__, 2) . "/migrations/postgres/{$migration}.sql");
                if ($sql === false) {
                    throw new \RuntimeException('Answer migration required');
                }
                $this->migrate('answer:' . substr($migration, 0, 3), $sql);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function migrate(string $id, string $sql): void
    {
        $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');
        $lock->execute(['knowkit:migrations:' . $this->schema]);
        $this->pdo->exec('SET LOCAL search_path TO "' . $this->schema . '",pg_catalog');
        $query = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE id=?');
        $query->execute([$id]);
        $existing = $query->fetchColumn();
        $checksum = hash('sha256', $sql);
        if ($existing !== false && (!is_string($existing) || !hash_equals($existing, $checksum))) {
            throw new \RuntimeException('Answer migration checksum mismatch');
        }
        if ($existing !== false) {
            return;
        }
        $this->pdo->exec($sql);
        $statement = $this->pdo->prepare('INSERT INTO schema_migrations VALUES (?,?,clock_timestamp())');
        $statement->execute([$id, $checksum]);
    }
}
