<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Domain as D;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use KnowKit\Port\WorkerRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:e2e')]
final class LeaseHeartbeatTest extends TestCase
{
    public function testHostRenewsEveryFifteenSecondsWithoutRenewingOnEveryPoll(): void
    {
        Harness::run(function (Harness $h): void {
            $attempt = $h->attempt('docling-pdf-text-v1', true);
            $now = 1;
            $keeper = new LeaseHeartbeat($h->pipeline->queue, static function () use (&$now): int {
                return $now;
            });
            $keeper->tick($attempt->lease);
            $this->shorten($h, $attempt->lease);
            $until = $h->jobs->row($attempt->lease->jobId)['lease_until'];
            $now += 14_000_000_000;
            $keeper->tick($attempt->lease);
            self::assertSame($until, $h->jobs->row($attempt->lease->jobId)['lease_until']);
            $now += 1_000_000_000;
            $keeper->tick($attempt->lease);
            self::assertGreaterThan(new \DateTimeImmutable($until),
                new \DateTimeImmutable($h->jobs->row($attempt->lease->jobId)['lease_until']));
            self::assertNotNull($h->pipeline->queue->workerContext($attempt->lease));
        });
    }

    public function testExpiredAndTakenOverLeasesCannotBeResurrected(): void
    {
        Harness::run(function (Harness $h): void {
            $attempt = $h->attempt('docling-pdf-text-v1', true);
            $keeper = new LeaseHeartbeat($h->pipeline->queue);
            $h->jobs->expire($attempt->lease);
            $this->denied(fn () => $keeper->tick($attempt->lease));
            $next = $h->takeover($attempt);
            $this->denied(fn () => $keeper->tick($attempt->lease));
            $keeper->tick($next);
            self::assertNotNull($h->pipeline->queue->workerContext($next));
            self::assertFalse($h->pipeline->queue->heartbeat($attempt->lease));
        });
    }

    public function testWorkerPollingRenewsTheHostLeaseBeforeItsFreshGuard(): void
    {
        Harness::run(function (Harness $h): void {
            $attempt = $h->attempt('docling-pdf-text-v1', true);
            $now = 1;
            $pipeline = new Pipeline(new Catalog($h->jobs->catalog->db), $attempt->lease->scope,
                $h->jobs->directory, $h->root . '/blobs', monotonic: static function () use (&$now): int {
                    return $now;
                });
            $runner = new class(function (?\Closure $cancel) use ($h, $attempt, &$now): void {
                $this->shorten($h, $attempt->lease);
                $before = $h->jobs->row($attempt->lease->jobId)['lease_until'];
                $now += 15_000_000_000;
                self::assertNotNull($cancel);
                self::assertFalse($cancel());
                self::assertGreaterThan(new \DateTimeImmutable($before),
                    new \DateTimeImmutable($h->jobs->row($attempt->lease->jobId)['lease_until']));
            }) implements WorkerRunner {
                public function __construct(private \Closure $during)
                {
                }

                public function run(D\JobEnvelope $job, string $source, ?\Closure $cancel = null): D\WorkerOutput
                {
                    ($this->during)($cancel);

                    return (new OracleRunner())->run($job, $source, $cancel);
                }
            };
            $accepted = $pipeline->extract($attempt->lease, $attempt->identity, $attempt->profile, $attempt->source, $runner);
            self::assertFalse($accepted->jobCompleted);
            self::assertNotNull($pipeline->queue->workerContext($attempt->lease));
        });
    }

    private function shorten(Harness $h, D\Lease $lease): void
    {
        $h->jobs->catalog->db->execute("UPDATE jobs SET lease_until=clock_timestamp()+interval '5 seconds' WHERE tenant_id=? AND id=?",
            [$lease->scope->tenantId->value, $lease->jobId->value]);
    }

    private function denied(\Closure $run): void
    {
        try {
            $run();
            self::fail('Expired capability was renewed');
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Denied, $failure->outcome);
            self::assertSame('stale_lease', $failure->errorCode);
        }
    }
}
