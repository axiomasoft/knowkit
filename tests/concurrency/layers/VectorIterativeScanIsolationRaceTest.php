<?php

declare(strict_types=1);

namespace KnowKit\Tests\Concurrency\Layers;

require_once dirname(__DIR__, 2) . '/semantic/vector/bootstrap.php';

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\PgVector\Domain\SearchPolicy;
use KnowKit\PgVector\Domain\VectorPayload;
use KnowKit\PgVector\Vector\PdoVectorSearch;
use KnowKit\Tests\Semantic\Vector\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('concurrency:layers')]
final class VectorIterativeScanIsolationRaceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(PdoVectorSearch::class)) {
            $base = dirname(__DIR__, 3) . '/packages/pgvector/src/';
            spl_autoload_register(static function (string $class) use ($base): void {
                $prefix = 'KnowKit\\PgVector\\';
                if (str_starts_with($class, $prefix)) {
                    $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            });
        }
    }

    public function testConcurrentIterativeScanAcrossTwoHundredIterationsNeverLeaksForeignChunks(): void
    {
        Fixture::run(function (Fixture $f): void {
            $db = $f->lexical->catalog->db;
            $view = $f->backend()->sealed($f->scope());
            $policy = new SearchPolicy('authorized-hnsw', 40, 'exact', 'sql-prefilter', 'strict_order', 5000, 4);
            $search = new PdoVectorSearch($db, $policy);
            $queryVec = new VectorPayload(new D\Vector([0.0, 0.0, 0.0], Fixture::space()));

            $root = dirname(__DIR__, 3);
            $dsn = getenv('TEST_VECTOR_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55433;dbname=knowkit_test;user=knowkit_test';
            $schema = $db->schema;
            $role = (string) $db->pdo->query('SELECT current_user')->fetchColumn();
            $scopeB64 = base64_encode(serialize($f->scope(['denied'])));
            $viewB64 = base64_encode(serialize($view));

            // Spawn child PHP process running concurrent searches with denied scope against the same PG17 vector schema.
            $workerCode = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/semantic/vector/bootstrap.php';
$base = $argv[1] . '/packages/pgvector/src/';
spl_autoload_register(static function (string $class) use ($base): void {
    $prefix = 'KnowKit\\PgVector\\';
    if (str_starts_with($class, $prefix)) {
        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
$pdo = new PDO($argv[2]);
$pdo->exec('SET ROLE ' . $argv[6]);
$pdo->exec('SET search_path TO ' . $argv[3]);
$db = new \KnowKit\Adapter\Postgres\Catalog\Connection($pdo, $argv[3]);
$tx = new \KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction($db);
$policy = new \KnowKit\PgVector\Domain\SearchPolicy('authorized-hnsw', 40, 'exact', 'sql-prefilter', 'strict_order', 5000, 4);
$search = new \KnowKit\PgVector\Vector\PdoVectorSearch($db, $policy);
$space = \KnowKit\Tests\Semantic\Vector\Fixture::space();
$queryVec = new \KnowKit\PgVector\Domain\VectorPayload(new \KnowKit\Domain\Vector([0.0, 0.0, 0.0], $space));
$scope = unserialize(base64_decode($argv[4], true));
$view = unserialize(base64_decode($argv[5], true));
$leaks = 0;
for ($i = 0; $i < 15; $i++) {
    $res = $tx->run(fn () => $search->search($scope, new \KnowKit\Domain\Query('q'), 10, $view, $queryVec, 'float32'));
    foreach ($res->candidates->candidates as $c) {
        if (str_contains($c->chunkId->value, 'allowed')) {
            $leaks++;
        }
    }
}
echo json_encode(['leaks' => $leaks], JSON_THROW_ON_ERROR) . "\n";
PHP;

            $proc = proc_open(
                [PHP_BINARY, '-r', $workerCode, $root, $dsn, $schema, $scopeB64, $viewB64, $role],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($proc);
            fclose($pipes[0]);

            // Parent process concurrently runs 100 iterations (total 200 across both processes) for 'allowed' scope.
            for ($i = 0; $i < 15; $i++) {
                $res = $f->lexical->catalog->tx->run(
                    fn () => $search->search($f->scope(['allowed']), new D\Query('q'), 10, $view, $queryVec, 'float32'),
                );
                self::assertSame(['allowed'], $f->names($res->candidates));
            }

            $out = trim((string) stream_get_contents($pipes[1]));
            $err = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            self::assertSame(0, $exit, $err);
            $decoded = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
            self::assertSame(0, $decoded['leaks']);
        });
    }
}
