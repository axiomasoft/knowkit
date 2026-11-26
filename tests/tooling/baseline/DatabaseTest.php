<?php
declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('tooling:baseline')]
final class DatabaseTest extends Support
{
    public function testDisposableSchemaResetsAndCleansSuccessAndFailure(): void
    {
        $outer = new DatabaseSandbox();
        $inner = new DatabaseSandbox();
        $outer->run(function (DatabaseSandbox $outer) use ($inner): void {
            $outer->connection->exec('CREATE TABLE sentinel (id int)');
            $outer->connection->exec('INSERT INTO sentinel VALUES (42)');
            $inner->run(function (DatabaseSandbox $inner): void {
                $inner->connection->exec('CREATE TABLE scratch (id int)');
                $inner->connection->exec('INSERT INTO scratch VALUES (1)');
                $inner->reset();
                $scratchStmt = $inner->connection->query("SELECT to_regclass('scratch')");
                self::assertNotFalse($scratchStmt);
                self::assertNull($scratchStmt->fetchColumn());
                $inner->connection->exec('CREATE TABLE scratch (id int)');
            });
            self::assertFalse($inner->exists());
            $sentinelStmt = $outer->connection->query('SELECT id FROM sentinel');
            self::assertNotFalse($sentinelStmt);
            self::assertSame(42, (int) $sentinelStmt->fetchColumn());
            $this->rejects(function () use ($inner): void {
                $inner->run(function (DatabaseSandbox $db): void {
                    $db->connection->beginTransaction();
                    $db->connection->exec('CREATE TABLE unfinished (id int)');
                    throw new RuntimeException('synthetic child failure');
                });
            }, 'synthetic child failure');
            self::assertFalse($inner->exists());
            $sentinelAfterStmt = $outer->connection->query('SELECT id FROM sentinel');
            self::assertNotFalse($sentinelAfterStmt);
            self::assertSame(42, (int) $sentinelAfterStmt->fetchColumn());
        });
        self::assertFalse($outer->exists());
    }

    public function testApplicationDatabaseCannotBeSelected(): void
    {
        $before = getenv('TEST_PG_DSN');
        putenv('TEST_PG_DSN=pgsql:host=127.0.0.1;dbname=production');
        try {
            $this->rejects(static fn() => new DatabaseSandbox(), 'disposable');
        } finally {
            $before === false ? putenv('TEST_PG_DSN') : putenv('TEST_PG_DSN=' . $before);
        }
        foreach ([
            'pgsql:host=127.0.0.1;dbname=knowkit_test;dbname=production',
            'pgsql:host=127.0.0.1;dbname=knowkit_test;DBNAME=production',
            'pgsql:dbname=knowkit_test dbname=production',
            'pgsql:dbname=\'production\'',
            'pgsql:postgresql://127.0.0.1/knowkit_test',
            'pgsql:dbname=knowkit_testing',
            'mysql:dbname=knowkit_test',
            'pgsql:dbname=knowkit_test\\ dbname=production',
        ] as $dsn) {
            $this->rejects(static fn() => DatabaseSandbox::assertDisposableDsn($dsn), 'disposable');
        }
        foreach ([
            'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
            'pgsql:dbname=knowkit_test',
            'pgsql:dbname=production;dbname=knowkit_test',
            'pgsql:host=127.0.0.1;dbname=\'knowkit_test\'',
        ] as $dsn) {
            DatabaseSandbox::assertDisposableDsn($dsn);
        }
    }
}
