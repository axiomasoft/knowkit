<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use KnowKit\Adapter\Postgres\Catalog\Migrate;
use KnowKit\Load\Report;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class MigrationTest extends TestCase
{
    public function testAdditiveIndexesKeepMigrationChecksumsAndIdempotentReplay(): void
    {
        CatalogFixture::run(function (CatalogFixture $c): void {
            $before = $c->db->execute('SELECT id,checksum FROM schema_migrations ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
            self::assertCount(13, $before);
            foreach ($before as $id => $checksum) {
                self::assertSame(hash_file('sha256', Report::root() . '/packages/core/migrations/postgres/' . $id . '.sql'), $checksum);
            }
            $indexes = $c->db->execute('SELECT indexname FROM pg_indexes WHERE schemaname=?', [$c->db->schema])->fetchAll(\PDO::FETCH_COLUMN);
            self::assertContains('blocks_document_locator', $indexes);
            self::assertContains('index_memberships_source_locator', $indexes);
            $role = $c->db->execute('SELECT current_user')->fetchColumn();
            $c->db->pdo->exec('RESET ROLE');
            try {
                (new Migrate(pdo: $c->db->pdo, schema: $c->db->schema))->apply(Report::root() . '/packages/core/migrations/postgres/001_metadata.sql');
            } finally {
                $c->db->pdo->exec('SET ROLE ' . $role);
            }
            self::assertSame($before, $c->db->execute('SELECT id,checksum FROM schema_migrations ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR));
        });
    }

    public function testDatabaseManifestCeilingMatchesWireWithoutRemovingUpperBound(): void
    {
        CatalogFixture::run(function (CatalogFixture $c): void {
            $c->tenant();
            $c->kb();
            $sql = <<<'SQL'
                INSERT INTO index_generations (tenant_id,id,kb_id,profile_id,state,manifest_json)
                VALUES (?,?,?,'postgres-fts-simple-v1','building',jsonb_build_object('padding',repeat('x',?)))
                SQL;
            $c->db->execute($sql, [CatalogFixture::id(1), CatalogFixture::id(998), CatalogFixture::id(100), 4194305]);
            self::assertGreaterThan(4194304, (int) $c->db->execute('SELECT octet_length(manifest_json::text) FROM index_generations')->fetchColumn());
            CatalogFixture::sqlReject(pdo: $c->db->pdo, sql: $sql,
                values: [CatalogFixture::id(1), CatalogFixture::id(999), CatalogFixture::id(100), 16777217], states: ['23514']);
            self::assertSame(1, $c->count('index_generations'));
        });
    }
}
