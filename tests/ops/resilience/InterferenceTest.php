<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Domain as D;
use KnowKit\Tests\Lifecycle\Commands\Fixture as Lifecycle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:resilience')]
final class InterferenceTest extends TestCase
{
    public function testPublishAndRevokeWaitAndRecheckInBothOrders(): void
    {
        foreach (['publish', 'revoke'] as $first) {
            Lifecycle::run(function (Lifecycle $f) use ($first): void {
                $f->sealSnapshot(snapshotId: 110, generationId: 14, sourceId: 2, versionId: 3, revisionId: 4, chunkSetId: 10, chunkId: 11);
                $second = $first === 'publish' ? 'revoke' : 'publish';
                $results = $this->pair(f: $f, first: $first, second: $second);
                self::assertSame('committed', $results[0]['status']);
                Fixture::expect(case: 'PUBLISH-REVOKE-' . strtoupper($first), actual: [
                    'waiter' => $results[1]['status'],
                    'source' => $f->catalog->db->execute('SELECT lifecycle_state FROM sources')->fetchColumn(),
                    'epoch' => (string) $f->catalog->db->execute('SELECT lifecycle_epoch FROM sources')->fetchColumn(),
                    'tombstones' => $f->catalog->count('tombstones'),
                    'usable' => Fixture::decision(db: $f->catalog->db, ref: $f->ref(kind: 'chunk', id: 11)),
                ]);
                // The historical sealed snapshot is retained with its original immutable membership.
                self::assertSame(Fixture::id(3), $f->catalog->db->execute('SELECT source_version_id FROM snapshot_memberships')->fetchColumn());
            });
        }
    }

    public function testGraphRegistrationAndInvalidationSerializeInBothOrders(): void
    {
        foreach (['graph-register', 'revoke'] as $first) {
            Lifecycle::run(function (Lifecycle $f) use ($first): void {
                $f->catalog->resource(600);
                $second = $first === 'graph-register' ? 'revoke' : 'graph-register';
                $results = $this->pair(f: $f, first: $first, second: $second);
                $registered = $f->catalog->db->row('SELECT invalidated_epoch FROM dependency_nodes WHERE id=?', [Fixture::id(600)]);
                Fixture::expect(case: 'GRAPH-INVALIDATE-' . strtoupper($first), actual: [
                    'waiter' => $results[1]['status'],
                    'registered' => $registered !== null,
                    'invalidated' => $registered !== null && $registered['invalidated_epoch'] !== null,
                    'parent' => Fixture::decision(db: $f->catalog->db, ref: $f->ref(kind: 'chunk', id: 11)),
                ]);
                if ($registered !== null) {
                    self::assertSame('Deny', Fixture::decision(db: $f->catalog->db, ref: $f->ref(kind: 'resource', id: 600)));
                    self::assertSame(Fixture::id(11), $f->catalog->db->execute('SELECT parent_id FROM dependency_edges WHERE child_id=?', [Fixture::id(600)])->fetchColumn());
                }
            });
        }
    }

    public function testUnseenRootRegistrationAndTombstoneSerializeInBothOrders(): void
    {
        foreach (['register', 'revoke'] as $first) {
            Lifecycle::run(function (Lifecycle $f) use ($first): void {
                $second = $first === 'register' ? 'revoke' : 'register';
                $results = $this->pair(f: $f, first: $first, second: $second, options: ['source' => 77]);
                if ($second === 'revoke' && $results[1]['status'] === 'rejected') {
                    // The waiter may reject the pre-registration owner binding; a fresh retry must revoke.
                    $retry = Fixture::run(db: $f->catalog->db, operation: 'revoke', options: ['source' => 77, 'scope' => $f->scope()]);
                    self::assertSame('committed', $retry['status']);
                }
                $source = $f->catalog->db->row('SELECT lifecycle_state FROM sources WHERE id=?', [Fixture::id(77)]);
                $mark = $f->catalog->db->row('SELECT lifecycle_epoch,applies_to FROM tombstones WHERE source_id=?', [Fixture::id(77)]);
                Fixture::expect(case: 'UNSEEN-TOMBSTONE-' . strtoupper($first), actual: [
                    'registered' => $source !== null,
                    'source' => $source['lifecycle_state'] ?? 'absent',
                    'epoch' => (string) $mark['lifecycle_epoch'],
                    'appliesTo' => $mark['applies_to'],
                    'usable' => Fixture::decision(db: $f->catalog->db, ref: $f->ref(kind: 'source', id: 77)),
                ]);
                self::assertSame('rejected', Fixture::run(db: $f->catalog->db, operation: 'register', options: ['scope' => $f->scope(), 'key' => 'register-after-tombstone'])['status']);
            });
        }
    }

    public function testCompetingIngestPublicationsCannotBothWinPointerCas(): void
    {
        Lifecycle::run(function (Lifecycle $f): void {
            $f->sealSnapshot(snapshotId: 110, generationId: 14, sourceId: 2, versionId: 3, revisionId: 4, chunkSetId: 10, chunkId: 11);
            $f->sealSnapshot(snapshotId: 111, generationId: 15, sourceId: 2, versionId: 3, revisionId: 4, chunkSetId: 10, chunkId: 11);
            $a = new Process(db: $f->catalog->db, operation: 'publish', options: ['scope' => $f->scope(), 'key' => 'ingest-a']);
            $b = new Process(db: $f->catalog->db, operation: 'publish', options: ['scope' => $f->scope(), 'snapshot' => 111, 'key' => 'ingest-b']);
            Fixture::gate(db: $f->catalog->db, workers: [$a, $b]);
            $results = [$a->result(), $b->result()];
            $statuses = array_column($results, 'status');
            sort($statuses);
            $db = Fixture::reconnect($f->catalog->db);
            Fixture::expect(case: 'TWO-PUBLICATIONS', actual: [
                'statuses' => $statuses,
                'pointerVersion' => (string) $db->execute('SELECT pointer_version FROM knowledge_bases')->fetchColumn(),
                'receipts' => (int) $db->execute("SELECT count(*) FROM idempotency_receipts WHERE key IN ('ingest-a','ingest-b')")->fetchColumn(),
            ]);
            $active = $db->execute('SELECT active_snapshot_id FROM knowledge_bases')->fetchColumn();
            self::assertContains($active, [Fixture::id(110), Fixture::id(111)]);
            self::assertSame('Allow', Fixture::decision(db: $db, ref: $f->ref(kind: 'chunk', id: 11)));
        });
    }

    private function pair(Lifecycle $f, string $first, string $second, array $options = []): array
    {
        $db = $f->catalog->db;
        $options += ['scope' => $f->scope()];
        $a = new Process(db: $db, operation: $first, options: ['precommit' => true] + $options);
        $b = new Process(db: $db, operation: $second, options: $options);
        self::assertNotSame($a->pid, $b->pid);
        self::assertNotSame($a->backend, $b->backend);
        $a->go();
        $transaction = $a->receive('PRECOMMIT');
        self::assertSame('read committed', $transaction['isolation']);
        $b->go();
        Fixture::waiting(db: $db, waiters: [$b], blocker: $a->backend);
        $a->go();
        $results = [$a->result(), $b->result()];
        Fixture::record(case: $first . '/' . $second, observation: [
            'transaction' => $transaction['transaction'],
            'backends' => [$a->backend, $b->backend],
            'pids' => [$a->pid, $b->pid],
            'waitEdgeObserved' => true,
            'results' => $results,
        ]);

        return $results;
    }
}
