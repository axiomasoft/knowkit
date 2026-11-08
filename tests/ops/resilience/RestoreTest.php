<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Lifecycle\Restore\Fixture as Restore;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/tools/fault-injection/Backup.php';

#[Group('ops:resilience')]
final class RestoreTest extends TestCase
{
    public function testActualBackupRestoreReplaysTombstonesBeforeReadinessAcrossKilledRunnerTwice(): void
    {
        Restore::run(function (Restore $f): void {
            $options = $this->options($f);
            $db = $f->blobs->catalog->db;
            $backup = new Backup($db);
            $backup->capture($f->blobs->path($f->ref));
            for ($round = 0; $round < 2; ++$round) {
                $backup->restore($f->blobs->path($f->ref));
                self::assertSame('active', $db->execute('SELECT lifecycle_state FROM sources')->fetchColumn());
                self::assertSame(0, $f->blobs->catalog->count('tombstones'));
                $runner = new Process(db: $db, operation: 'restore', options: ['restoreBarriers' => true] + $options);
                $runner->go();
                $runner->receive('RESTORE_STARTED');
                self::assertSame('blocked', $f->serving());
                self::assertSame('denied', Fixture::run(db: $db, operation: 'read', options: $options)['outcome']);
                CatalogFixture::sqlReject(pdo: $db->pdo, sql: "UPDATE tenants SET serving_state='ready'");
                CatalogFixture::sqlReject(pdo: $db->pdo, sql: 'UPDATE resource_ledger SET ref_count=ref_count+1');
                $runner->go();
                $runner->receive('REPLAYED');
                self::assertSame('1', $f->store->checkpoint($options['plan'])['sequence']);
                self::assertSame('blocked', $f->serving());
                self::assertSame(0, $f->blobs->catalog->count('restore_receipts'));
                self::assertSame('denied', Fixture::run(db: $db, operation: 'read', options: $options)['outcome']);
                $runner->kill();
                self::assertSame(['stage' => 'ready', 'sequence' => '1'], Fixture::run(db: $db, operation: 'restore', options: $options));
                self::assertSame(['stage' => 'ready', 'sequence' => '1'], Fixture::run(db: $db, operation: 'restore', options: $options));
                $reconnected = Fixture::reconnect($db);
                Fixture::expect(case: 'RESTORE-READINESS', actual: [
                    'serving' => $f->serving(),
                    'source' => $reconnected->execute('SELECT lifecycle_state FROM sources')->fetchColumn(),
                    'epoch' => (string) $reconnected->execute('SELECT lifecycle_epoch FROM sources')->fetchColumn(),
                    'tombstones' => $f->blobs->catalog->count('tombstones'),
                    'receipts' => $f->blobs->catalog->count('restore_receipts'),
                    'digest' => hash_file('sha256', $f->blobs->path($f->ref)),
                    'disclosure' => Fixture::run(db: $db, operation: 'read', options: $options)['outcome'],
                ]);
                Fixture::record(case: 'backup-restore', observation: ['round' => $round + 1, 'journalDigest' => $options['plan']->journalDigest->value, 'backupInventories' => $options['plan']->inventories]);
            }
        });
    }

    public function testMissingJournalAndFailedCanaryRemainBlockedAfterReconnect(): void
    {
        Restore::run(function (Restore $f): void {
            $options = $this->options($f);
            $db = $f->blobs->catalog->db;
            $missing = Fixture::run(db: $db, operation: 'restore', options: ['marks' => []] + $options);
            self::assertSame(['status' => 'rejected', 'outcome' => 'unknown', 'code' => 'recovery_journal_gap'], $missing);
            self::assertSame('blocked', $f->serving());
            self::assertSame('0', $f->store->checkpoint($options['plan'])['sequence']);
            $canary = Fixture::run(db: $db, operation: 'restore', options: ['badCanary' => true] + $options);
            self::assertSame(['status' => 'rejected', 'outcome' => 'unknown', 'code' => 'recovery_canary_failed'], $canary);
            Fixture::expect(case: 'RESTORE-FAIL-CLOSED', actual: [
                'serving' => $f->serving(),
                'receipts' => $f->blobs->catalog->count('restore_receipts'),
                'missingJournal' => $missing['outcome'],
                'failedCanary' => $canary['outcome'],
            ]);
            self::assertSame(['stage' => 'ready', 'sequence' => '1'], Fixture::run(db: $db, operation: 'restore', options: $options));
        });
    }

    private function options(Restore $f): array
    {
        $f->marks = [StrictJson::decode(json_encode(Fixture::cases()['deleteMark'], JSON_THROW_ON_ERROR))];
        $plan = $f->plan();
        self::assertSame(Fixture::cases()['journalDigest'], $plan->journalDigest->value);

        return ['plan' => $plan, 'marks' => $f->marks, 'root' => $f->blobs->root, 'ref' => $f->ref];
    }
}
