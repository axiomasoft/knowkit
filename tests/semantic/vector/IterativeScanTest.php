<?php

declare(strict_types=1);

namespace KnowKit\Tests\Semantic\Vector;

require_once __DIR__ . '/bootstrap.php';

use KnowKit\Domain as D;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\PgVector\Domain\VectorPayload;
use KnowKit\PgVector\Vector\PdoVectorSearch;
use KnowKit\PgVector\Vector\SearchSql;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('semantic:vector')]
final class IterativeScanTest extends TestCase
{
    public function testIterativeScanPolicySetsPgvector08GucsAndLeaksZeroDeniedRows(): void
    {
        Fixture::run(function (Fixture $f): void {
            $db = $f->lexical->catalog->db;
            $view = $f->backend()->sealed($f->scope());
            $policy = new SearchPolicy('authorized-hnsw', 40, 'exact', 'sql-prefilter', 'strict_order', 5000, 4);
            $search = new PdoVectorSearch($db, $policy);
            $queryVec = new VectorPayload(new D\Vector([0.0, 0.0, 0.0], Fixture::space()));

            $result = $f->lexical->catalog->tx->run(function () use ($db, $search, $f, $view, $queryVec): mixed {
                $res = $search->search($f->scope(['allowed']), new D\Query('q'), 10, $view, $queryVec, 'float32');
                self::assertSame('strict_order', $db->execute('SHOW hnsw.iterative_scan')->fetchColumn());
                self::assertSame('5000', $db->execute('SHOW hnsw.max_scan_tuples')->fetchColumn());

                return $res;
            });

            self::assertSame(1, $result->permittedCount);
            self::assertCount(1, $result->candidates->candidates);
            self::assertSame(['allowed'], $f->names($result->candidates));

            $emptyResult = $f->lexical->catalog->tx->run(
                fn () => $search->search($f->scope([]), new D\Query('q'), 10, $view, $queryVec, 'float32'),
            );
            self::assertSame(0, $emptyResult->permittedCount);
            self::assertSame([], $emptyResult->candidates->candidates);
        });
    }

    public function testBinaryQuantized2StageCteMaterializesPermittedBeforeCoarseAndFinal(): void
    {
        Fixture::run(function (Fixture $f): void {
            $db = $f->lexical->catalog->db;
            $view = $f->backend()->sealed($f->scope());
            $values = SearchSql::values($f->scope(), new D\Query('q'), $view);
            $sql = SearchSql::exact($db, 'binary_quantized');
            $plan = json_decode(
                $db->execute('EXPLAIN (FORMAT JSON) ' . $sql, [...$values, '101', 10, 4, '[0,0,0]', 10])->fetchColumn(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $json = json_encode($plan[0]['Plan'], JSON_THROW_ON_ERROR);
            self::assertStringContainsString('CTE permitted', $json);
            self::assertStringContainsString('CTE coarse', $json);
        });
    }
}
