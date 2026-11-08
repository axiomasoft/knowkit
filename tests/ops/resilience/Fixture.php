<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Assert;

final class Fixture
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function id(int $id): string
    {
        return CatalogFixture::id($id);
    }

    public static function cases(): array
    {
        static $data;
        $data ??= json_decode(file_get_contents(__DIR__ . '/fixtures/v1/cases.json'), true, 64, JSON_THROW_ON_ERROR);

        return $data;
    }

    public static function expect(string $case, array $actual): void
    {
        Assert::assertSame(self::cases()['outcomes'][$case], $actual, $case);
        self::record(case: $case, observation: $actual);
    }

    public static function record(string $case, array $observation): void
    {
        $directory = self::root() . '/.phpunit.cache/P9.3';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents(
            $directory . '/transactions.jsonl',
            json_encode(['case' => $case, 'observation' => $observation], JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /** Observe actual lock dependencies; elapsed time never establishes a schedule. */
    public static function waiting(Connection $db, array $waiters, int $blocker): void
    {
        $deadline = hrtime(true) + 400_000_000;
        $pending = $waiters;
        do {
            foreach ($pending as $key => $waiter) {
                $blocked = $db->execute(<<<'SQL'
                    WITH RECURSIVE blockers(pid) AS (
                        SELECT unnest(pg_blocking_pids(?::int))
                        UNION
                        SELECT unnest(pg_blocking_pids(b.pid)) FROM blockers b
                    ) SELECT EXISTS (SELECT 1 FROM blockers WHERE pid=?::int)
                    SQL, [$waiter->backend, $blocker])->fetchColumn();
                if ($blocked) {
                    unset($pending[$key]);
                }
            }
        } while ($pending !== [] && hrtime(true) < $deadline);
        Assert::assertSame([], $pending, 'Required PostgreSQL wait edge was not observed');
    }

    public static function gate(Connection $db, array $workers): void
    {
        $db->pdo->beginTransaction();
        try {
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$db->schema . ':tenant:' . self::id(1)]);
            foreach ($workers as $worker) {
                $worker->go();
            }
            self::waiting(db: $db, waiters: $workers, blocker: (int) $db->execute('SELECT pg_backend_pid()')->fetchColumn());
        } finally {
            $db->pdo->commit();
        }
    }

    /** @phpstan-impure */
    public static function run(Connection $db, string $operation, array $options = []): array
    {
        $worker = new Process(db: $db, operation: $operation, options: $options);
        $worker->go();

        return $worker->result();
    }

    public static function decision(Connection $db, D\ResourceRef $ref): string
    {
        $runtime = new Runtime(db: self::reconnect($db), options: []);

        return $runtime->tx->run(function () use ($runtime, $ref): string {
            $runtime->tx->lockTenant(tenantId: $ref->tenantId, mode: D\LockMode::Share);

            return $runtime->graph->checkAncestors(new D\NodeRefs(tenantId: $ref->tenantId, refs: [$ref]))->decision->value;
        });
    }

    public static function reconnect(Connection $db): Connection
    {
        $role = $db->execute('SELECT current_user')->fetchColumn();
        Connection::schema($role);
        $pdo = new \PDO(
            getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
            getenv('TEST_PG_USER') ?: 'knowkit_test',
            getenv('TEST_PG_PASSWORD') ?: null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('SET ROLE ' . $role);
        $pdo->exec('SET search_path TO ' . $db->schema);

        return new Connection(pdo: $pdo, schema: $db->schema);
    }
}
