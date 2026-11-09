<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Tooling\Qa;

/** Counterfactual query plans verify that immutable identity guards no longer scan a tenant. */
final class Lookups
{
    private const BLOCK_SQL = <<<'SQL'
        SELECT id FROM blocks WHERE tenant_id=? AND document_id=? AND local_block_id='b1'
        SQL;

    private const MEMBER_SQL = <<<'SQL'
        SELECT chunk_id FROM index_memberships
        WHERE tenant_id=? AND generation_id=? AND source_version_id=? AND revision_id=? AND chunk_set_id=?
        SQL;

    public static function run(Connection $db): array
    {
        $queries = ['block' => [self::BLOCK_SQL, [Dataset::id(1), Dataset::id(40009999)]],
            'membership' => [self::MEMBER_SQL, [Dataset::id(1), Dataset::id(900), Dataset::id(20009999),
                Dataset::id(30009999), Dataset::id(50009999)]]];
        $results = [];
        foreach ($queries as $name => [$sql, $values]) {
            $indexed = self::plan(db: $db, sql: $sql, values: $values);
            $db->pdo->beginTransaction();
            try {
                $db->execute('SET LOCAL enable_indexscan=off');
                $db->execute('SET LOCAL enable_bitmapscan=off');
                $linear = self::plan(db: $db, sql: $sql, values: $values);
            } finally {
                $db->pdo->rollBack();
            }
            Qa::ensure(self::filtered($indexed) === 0 && self::filtered($linear) >= 9000, 'immutable locator regressed to tenant scan');
            $results[$name] = ['indexedFilteredRows' => self::filtered($indexed), 'linearFilteredRows' => self::filtered($linear),
                'indexName' => $indexed['Index Name'] ?? '', 'indexedMs' => $indexed['Actual Total Time'], 'linearMs' => $linear['Actual Total Time']];
        }

        return $results;
    }

    private static function plan(Connection $db, string $sql, array $values): array
    {
        $wire = $db->execute('EXPLAIN (ANALYZE, FORMAT JSON, TIMING ON) ' . $sql, $values)->fetchColumn();

        return json_decode($wire, true, 64, JSON_THROW_ON_ERROR)[0]['Plan'];
    }

    private static function filtered(array $plan): int
    {
        $total = (int) ($plan['Rows Removed by Filter'] ?? 0);
        foreach ($plan['Plans'] ?? [] as $child) {
            $total += self::filtered($child);
        }

        return $total;
    }
}
