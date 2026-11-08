<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Tests\Lifecycle\Commands\Fixture as Lifecycle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:resilience')]
final class CrashTest extends TestCase
{
    public function testKillBeforeAndAfterCommitReconnectsWithoutPartialEffectsOrDuplicateEvents(): void
    {
        foreach (['before', 'after'] as $window) {
            Lifecycle::run(function (Lifecycle $f) use ($window): void {
                $f->recordHistoricalUsage();
                $history = $this->history($f);
                $events = $f->catalog->count('outbox');
                $db = $f->catalog->db;
                $writer = new Process(db: $db, operation: 'revoke', options: [
                    'scope' => $f->scope(),
                    'key' => 'crash-revoke',
                    'precommit' => $window === 'before',
                    'postcommit' => $window === 'after',
                ]);
                $writer->go();
                $barrier = $writer->receive($window === 'before' ? 'PRECOMMIT' : 'COMMITTED');
                $observer = Fixture::reconnect($db);
                $visible = (int) $observer->execute('SELECT count(*) FROM tombstones')->fetchColumn();
                self::assertSame($window === 'before' ? 0 : 1, $visible);
                self::assertSame($visible, (int) $observer->execute("SELECT count(*) FROM idempotency_receipts WHERE key='crash-revoke'")->fetchColumn());
                $writer->kill();
                self::assertSame('committed', Fixture::run(db: $db, operation: 'revoke', options: ['scope' => $f->scope(), 'key' => 'crash-revoke'])['status']);
                self::assertSame('committed', Fixture::run(db: $db, operation: 'revoke', options: ['scope' => $f->scope(), 'key' => 'crash-revoke'])['status']);
                $reconnected = Fixture::reconnect($db);
                Fixture::expect(case: 'KILL-' . strtoupper($window) . '-COMMIT', actual: [
                    'signal' => 9,
                    'visibleAtKill' => $visible,
                    'epoch' => (string) $reconnected->execute('SELECT lifecycle_epoch FROM sources')->fetchColumn(),
                    'tombstones' => (int) $reconnected->execute('SELECT count(*) FROM tombstones')->fetchColumn(),
                    'newEvents' => $f->catalog->count('outbox') - $events,
                    'receipts' => (int) $reconnected->execute("SELECT count(*) FROM idempotency_receipts WHERE key='crash-revoke'")->fetchColumn(),
                    'usable' => Fixture::decision(db: $db, ref: $f->ref(kind: 'chunk', id: 11)),
                ]);
                self::assertSame($history, $this->history($f));
                self::assertSame(1, $f->catalog->count('usage_invalidations'));
                Fixture::record(case: 'kill-' . $window, observation: ['pid' => $writer->pid, 'backend' => $writer->backend, 'barrier' => $barrier]);
            });
        }
    }

    private function history(Lifecycle $f): array
    {
        $history = [];
        foreach (['evidence_usages', 'usage_refs', 'citations'] as $table) {
            $history[$table] = $f->catalog->db->execute('SELECT to_jsonb(r)::text FROM ' . $table . ' r ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $history;
    }
}
