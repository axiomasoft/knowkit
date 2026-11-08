<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Domain as D;
use KnowKit\Tests\Jobs\Leases\Fixture as JobFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:resilience')]
final class JobsTest extends TestCase
{
    public function testLeaseTakeoverRejectsOldCompletionHeartbeatCheckpointAndResume(): void
    {
        JobFixture::run(function (JobFixture $f): void {
            $f->enqueue();
            $lease = $f->lease();
            $f->finishStages($lease);
            $options = $this->options(f: $f, lease: $lease);
            $old = new Process(db: $f->catalog->db, operation: 'stale', options: $options);
            $f->expire($lease); // Explicit test-only expiry fault, never a wall-clock delay.
            $takeover = Fixture::run(db: $f->catalog->db, operation: 'takeover', options: $options);
            self::assertSame(['claimed' => true, 'fence' => '2', 'resumed' => true, 'completed' => true], $takeover);
            $before = $f->row();
            $old->go();
            Fixture::expect(case: 'LEASE-TAKEOVER-COMPLETE', actual: $old->result());
            self::assertSame($before, $f->row());
            $db = Fixture::reconnect($f->catalog->db);
            self::assertSame('succeeded', $db->execute('SELECT state FROM jobs')->fetchColumn());
            self::assertSame('2', (string) $db->execute('SELECT fence FROM jobs')->fetchColumn());
            self::assertSame(1, $f->catalog->count('job_checkpoints'));
            Fixture::record(case: 'lease-takeover', observation: $takeover);
        });
    }

    public function testCompletedJobCannotBeTakenOver(): void
    {
        JobFixture::run(function (JobFixture $f): void {
            $f->enqueue();
            $lease = $f->lease();
            $f->finishStages($lease);
            $options = $this->options(f: $f, lease: $lease);
            $takeover = new Process(db: $f->catalog->db, operation: 'claim', options: $options);
            self::assertSame(['completed' => true], Fixture::run(db: $f->catalog->db, operation: 'complete', options: $options));
            $takeover->go();
            Fixture::expect(case: 'COMPLETE-LEASE-TAKEOVER', actual: $takeover->result());
            self::assertSame('succeeded', $f->row()['state']);
            self::assertSame('1', (string) $f->row()['fence']);
        });
    }

    public function testTwoIndependentIngestsShareOneIdempotentJobAndOnlyOneLease(): void
    {
        JobFixture::run(function (JobFixture $f): void {
            $options = ['scope' => $f->scope, 'directory' => $f->directory, 'command' => $f->command('ingest')];
            $a = new Process(db: $f->catalog->db, operation: 'enqueue', options: ['worker' => 901] + $options);
            $b = new Process(db: $f->catalog->db, operation: 'enqueue', options: ['worker' => 902] + $options);
            self::assertNotSame($a->backend, $b->backend);
            Fixture::gate(db: $f->catalog->db, workers: [$a, $b]);
            $left = $a->receive('ENQUEUED');
            $right = $b->receive('ENQUEUED');
            self::assertSame($left['job'], $right['job']);
            $a->go();
            $b->go();
            $results = [$a->result(), $b->result()];
            $db = Fixture::reconnect($f->catalog->db);
            Fixture::expect(case: 'TWO-INGEST', actual: [
                'jobs' => (int) $db->execute('SELECT count(*) FROM jobs')->fetchColumn(),
                'receipts' => (int) $db->execute("SELECT count(*) FROM idempotency_receipts WHERE key='two-ingest'")->fetchColumn(),
                'leases' => count(array_filter(array_column($results, 'claimed'))),
                'attempts' => (int) $db->execute('SELECT attempt_count FROM jobs')->fetchColumn(),
            ]);
        });
    }

    private function options(JobFixture $f, D\Lease $lease): array
    {
        return [
            'scope' => $f->scope,
            'directory' => $f->directory,
            'lease' => $lease,
            'processing' => $f->processing,
            'checkpoint' => new D\Checkpoint(new D\Sha256($f->row()['checkpoint_digest']), false),
            'result' => $f->result(),
        ];
    }

    public function testOrdinaryWriterCannotUpgradeTenantAfterJobWhileAnotherSharedGuardIsHeld(): void
    {
        JobFixture::run(function (JobFixture $f): void {
            $left = $f->enqueue('guard-a');
            $right = $f->enqueue('guard-b');
            $options = ['scope' => $f->scope];
            $a = new Process(db: $f->catalog->db, operation: 'job-write', options: ['job' => $left] + $options);
            $b = new Process(db: $f->catalog->db, operation: 'job-write', options: ['job' => $right] + $options);
            self::assertNotSame($a->backend, $b->backend);
            $a->go();
            $b->go();
            $a->receive('GUARDED');
            $b->receive('GUARDED');
            // B keeps its SHARE guard until A commits. The old trigger's UPDATE upgrade
            // deterministically times out here, regardless of operating-system scheduling.
            $a->go();
            $first = $a->result();
            self::assertSame('committed', $first['status']);
            $b->go();
            Fixture::expect(case: 'SHARED-WRITER-RESTORE-GUARD', actual: [
                'statuses' => [$first['status'], $b->result()['status']],
                'writtenJobs' => (int) Fixture::reconnect($f->catalog->db)->execute("SELECT count(*) FROM jobs WHERE last_error_code='RESILIENCE_GUARD_PROBE'")->fetchColumn(),
            ]);
        });
    }
}
