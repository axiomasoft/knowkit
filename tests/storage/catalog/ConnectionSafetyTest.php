<?php
declare(strict_types=1);

namespace KnowKit\Tests\Storage\Catalog;

use KnowKit\Adapter\Postgres\Catalog\{Connection, Migrate, PdoCatalogTransaction};
use KnowKit\Domain as D;
use KnowKit\Tests\Tooling\DatabaseSandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('storage:catalog')]
final class ConnectionSafetyTest extends TestCase
{
    public function testTemporaryTablesCannotHideSourceTombstonesFromTriggers(): void
    {
        CatalogFixture::run(function (CatalogFixture $f): void {
            $f->tenant();
            $f->db->execute('INSERT INTO ' . $f->db->table('tombstones')
                . " (tenant_id,id,source_id,reason,event_id,lifecycle_epoch,applies_to)
                    VALUES (?,?,?,'source_revoked',?,1,'future_versions')",
                [CatalogFixture::id(1), CatalogFixture::id(900), CatalogFixture::id(2), CatalogFixture::id(901)]);
            $f->db->execute('CREATE TEMP TABLE tombstones (LIKE ' . $f->db->table('tombstones') . ')');
            try {
                $f->source(2);
                self::fail('A temporary table hid the authoritative source tombstone');
            } catch (D\DomainFailure $failure) {
                self::assertSame('storage_conflict', $failure->errorCode);
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
            }
            self::assertSame(0, $f->count('sources'));
        });
    }

    public function testLostConnectionAtBeginReturnsTypedUnavailableWithoutCallingOperation(): void
    {
        CatalogFixture::run(function (CatalogFixture $f): void {
            // Terminate only this test's second connection, leaving cleanup/admin alive.
            $victim = (new DatabaseSandbox())->connection;
            $role = $f->db->execute('SELECT current_user')->fetchColumn();
            $victim->exec('SET ROLE ' . $role);
            $transaction = new PdoCatalogTransaction(new Connection($victim, $f->db->schema));
            $pid = (int) $victim->query('SELECT pg_backend_pid()')->fetchColumn();
            $f->db->pdo->exec('RESET ROLE');
            $f->db->execute('SELECT pg_terminate_backend(?, 5000)', [$pid]);
            $called = false;
            try {
                $transaction->run(function () use (&$called): void { $called = true; });
                self::fail('Lost connection accepted a transaction');
            } catch (D\DomainFailure $failure) {
                self::assertSame('storage_unavailable', $failure->errorCode);
                self::assertSame(D\Outcome::Unavailable, $failure->outcome);
                self::assertNull($failure->getPrevious());
            }
            self::assertFalse($called);
        });
    }

    public function testMigrationIgnoresTemporaryLedgerAndRestoresCallerSearchPath(): void
    {
        CatalogFixture::run(function (CatalogFixture $f): void {
            $pdo = $f->db->pdo;
            $pdo->exec('RESET ROLE');
            $pdo->exec('CREATE TEMP TABLE schema_migrations (LIKE ' . $f->db->table('schema_migrations') . ')');
            $pdo->exec('SET search_path TO pg_temp, "' . $f->db->schema . '"');
            $before = $pdo->query('SHOW search_path')->fetchColumn();
            self::assertStringStartsWith('pg_temp_', $pdo->query('SELECT current_schema()')->fetchColumn());
            (new Migrate($pdo, $f->db->schema))->apply(CatalogFixture::root() . '/packages/core/migrations/postgres/001_metadata.sql');
            self::assertSame($before, $pdo->query('SHOW search_path')->fetchColumn());
            self::assertSame(0, (int) $pdo->query('SELECT count(*) FROM pg_temp.schema_migrations')->fetchColumn());
            self::assertSame(2, $f->count('schema_migrations'));
        });
    }

    public function testUpgradePinsExistingGuardsWithoutChangingEarlierMigrationHistory(): void
    {
        CatalogFixture::run(function (CatalogFixture $f): void {
            $history = $f->db->execute('SELECT id FROM ' . $f->db->table('schema_migrations') . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
            self::assertSame(['001_metadata', '002_layers_artifacts_graph'], $history);
            $config = $f->db->execute('SELECT p.proconfig::text FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                WHERE n.nspname=? AND p.proname=\'kk_source_barrier\'', [$f->db->schema])->fetchColumn();
            self::assertStringContainsString('search_path=pg_catalog, ' . $f->db->schema . ', pg_temp', (string) $config);
            $unpinned = (int) $f->db->execute(
                'SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                 WHERE n.nspname=? AND left(p.proname, 3)=\'kk_\'
                   AND (p.proconfig IS NULL OR NOT EXISTS (
                       SELECT 1 FROM unnest(p.proconfig) AS cfg WHERE cfg LIKE \'search_path=pg_catalog, %\'
                   ))',
                [$f->db->schema],
            )->fetchColumn();
            self::assertSame(0, $unpinned);
        });
    }
}
