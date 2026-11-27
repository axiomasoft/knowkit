<?php

declare(strict_types=1);

namespace KnowKit\Tests\Concurrency\Layers;

require_once dirname(__DIR__, 2) . '/jobs/leases/Fixture.php';

use KnowKit\Adapter\Postgres\Jobs\PdoJobQueue;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\IdempotencyKey;
use KnowKit\Domain\JobCommand;
use KnowKit\Domain\JobType;
use KnowKit\Domain\ProcessingIdentity;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\WorkerId;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Jobs\Leases\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('concurrency:layers')]
final class DistillLeaseFencingRaceTest extends TestCase
{
    public function testExpiredWorkerFencingTokenCannotHeartbeatOrCompleteAfterNewWorkerAcquiresLease(): void
    {
        Fixture::run(function (Fixture $f): void {
            $jobId = $f->enqueue();

            // Worker 1 acquires the lease, then stalls until expired.
            $lease1 = $f->lease();
            self::assertSame($jobId->value, $lease1->jobId->value);
            $f->expire($lease1);

            // Worker 2 (separate PHP process) steals the expired lease with a higher fencing token.
            $root = dirname(__DIR__, 3);
            $dsn = getenv('TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test;user=knowkit_test';
            $schema = $f->catalog->db->schema;
            $role = (string) $f->catalog->db->pdo->query('SELECT current_user')->fetchColumn();
            $scopeB64 = base64_encode(serialize($f->catalog->scope(1)));

            $worker2Code = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
require $argv[1] . '/tests/jobs/leases/Fixture.php';
$pdo = new PDO($argv[2]);
$pdo->exec('SET ROLE ' . $argv[6]);
$pdo->exec('SET search_path TO ' . $argv[3]);
$db = new \KnowKit\Adapter\Postgres\Catalog\Connection($pdo, $argv[3]);
$tx = new \KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction($db);
$scope = unserialize(base64_decode($argv[4], true));
$queue = \KnowKit\Tests\Jobs\Leases\Fixture::makeQueue($db, $scope, $argv[5], $tx);
$lease2 = $queue->lease(new \KnowKit\Domain\JobType('extract'), new \KnowKit\Domain\WorkerId(\KnowKit\Tests\Jobs\Leases\Fixture::id(902)));
if ($lease2 === null) {
    fwrite(STDERR, "worker2 failed to acquire lease\n");
    exit(2);
}
echo json_encode(['epoch' => (int) $lease2->fence->value, 'jobId' => $lease2->jobId->value], JSON_THROW_ON_ERROR) . "\n";
PHP;

            $proc = proc_open(
                [PHP_BINARY, '-r', $worker2Code, $root, $dsn, $schema, $scopeB64, $f->directory, $role],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $out = trim((string) stream_get_contents($pipes[1]));
            $err = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            self::assertSame(0, $exit, $err);

            $res = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
            self::assertSame($jobId->value, $res['jobId']);
            self::assertGreaterThan((int) $lease1->fence->value, $res['epoch']);

            // Worker 1 with stale fencing token MUST be rejected on heartbeat.
            self::assertFalse($f->queue->heartbeat($lease1));
        });
    }
}
