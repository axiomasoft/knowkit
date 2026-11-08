<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Adapter\Postgres\Deletion\PdoDeletionStore;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:resilience')]
final class StorageTest extends TestCase
{
    public function testObjectDurabilityBeforeAcceptanceNeedsConfirmedStoppedWriterAndDigestProof(): void
    {
        BlobFixture::run(function (BlobFixture $f): void {
            $options = $this->options($f);
            $writer = new Process(db: $f->catalog->db, operation: 'write', options: ['objectBarrier' => true] + $options);
            $writer->go();
            $writer->receive('OBJECT_DURABLE');
            $writer->kill();
            self::assertSame('reserved', $f->row()['state']);
            self::assertSame('active', $f->catalog->db->execute('SELECT state FROM resource_writers')->fetchColumn());
            self::assertSame(Fixture::cases()['payloadDigest'], hash_file('sha256', $f->path($options['ref'])));
            $denied = Fixture::run(db: $f->catalog->db, operation: 'read', options: $options);
            self::assertSame('denied', $denied['outcome']);
            $uncertain = Fixture::run(db: $f->catalog->db, operation: 'recover-object', options: ['stopped' => false] + $options);
            self::assertSame('unknown', $uncertain['outcome']);
            self::assertSame('writer_uncertain', $uncertain['code']);
            $recovered = Fixture::run(db: $f->catalog->db, operation: 'recover-object', options: ['stopped' => true] + $options);
            self::assertSame(['accepted' => true, 'digest' => Fixture::cases()['payloadDigest']], $recovered);
            Fixture::expect(case: 'OBJECT-UNCERTAINTY', actual: [
                'state' => $f->row()['state'],
                'writer' => $f->catalog->db->execute('SELECT state FROM resource_writers')->fetchColumn(),
                'digest' => hash('sha256', BlobFixture::read($f->store->open($options['ref']))),
            ]);
        });
    }

    public function testPurgeFencesLateObjectAcceptanceAndCapturesAlreadyAcceptedResource(): void
    {
        foreach ([true, false] as $late) {
            BlobFixture::run(function (BlobFixture $f) use ($late): void {
                $db = $f->catalog->db;
                $options = $this->options($f);
                $writer = new Process(db: $db, operation: 'write', options: ['objectBarrier' => $late] + $options);
                $purger = new Process(db: $db, operation: 'purge', options: ['precommit' => $late] + $options);
                $writer->go();
                if ($late) {
                    $writer->receive('OBJECT_DURABLE');
                    $purger->go();
                    $purger->receive('PRECOMMIT');
                    $writer->go();
                    Fixture::waiting(db: $db, waiters: [$writer], blocker: $purger->backend);
                    $purger->go();
                } else {
                    self::assertSame('committed', $writer->result()['status']);
                    $purger->go();
                }
                $purged = $purger->result();
                self::assertSame('committed', $purged['status']);
                if ($late) {
                    self::assertSame('rejected', $writer->result()['status']);
                }
                $runtime = new Runtime(db: Fixture::reconnect($db), options: []);
                $store = new PdoDeletionStore(db: $runtime->db, tx: $runtime->tx, tenant: $runtime->tenant, clock: $runtime->clock, validator: PortFixture::validator());
                Fixture::expect(case: $late ? 'PURGE-LATE-RESOURCE' : 'RESOURCE-PURGE', actual: [
                    'source' => $db->execute('SELECT lifecycle_state FROM sources')->fetchColumn(),
                    'resource' => $f->row()['state'],
                    'writer' => $db->execute('SELECT state FROM resource_writers')->fetchColumn(),
                    'inventory' => $f->catalog->count('deletion_task_resources'),
                    'aggregate' => $store->aggregate(tenant: $runtime->tenant, task: new D\DeletionTaskId($purged['task'])),
                ]);
                self::assertFileExists($f->path($options['ref']));
                self::assertSame('denied', Fixture::run(db: $db, operation: 'read', options: $options)['outcome']);
            });
        }
    }

    public function testProviderCrashBeforeAndAfterEffectReplaysUnknownWithoutTransport(): void
    {
        foreach (['before-effect', 'after-effect'] as $window) {
            BlobFixture::run(function (BlobFixture $f) use ($window): void {
                $f->put();
                $db = $f->catalog->db;
                $role = $db->execute('SELECT current_user')->fetchColumn();
                $db->pdo->exec('RESET ROLE');
                try {
                    $db->execute('CREATE TABLE resilience_provider_effects(tenant_id text,resource_id text,object_version text,PRIMARY KEY(tenant_id,resource_id,object_version))');
                    $db->pdo->exec('GRANT SELECT,INSERT ON resilience_provider_effects TO ' . $role);
                } finally {
                    $db->pdo->exec('SET ROLE ' . $role);
                }
                $purged = Fixture::run(db: $db, operation: 'purge', options: ['scope' => $f->catalog->scope()]);
                $options = ['task' => new D\DeletionTaskId($purged['task']), 'resource' => new D\ResourceId(Fixture::id(300)), 'attempt' => new D\AttemptId(Fixture::id(81000))];
                $worker = new Process(db: $db, operation: 'delete', options: ['window' => $window] + $options);
                $worker->go();
                $worker->receive($window === 'before-effect' ? 'BEFORE_EFFECT' : 'AFTER_EFFECT');
                self::assertSame(1, $f->catalog->count('deletion_attempts'));
                self::assertSame($window === 'before-effect' ? 0 : 1, $f->catalog->count('resilience_provider_effects'));
                $worker->kill();
                $replay = Fixture::run(db: $db, operation: 'delete', options: $options);
                self::assertSame(['result' => 'unknown', 'calls' => 0, 'aggregate' => 'unknown'], $replay);
                $retry = Fixture::run(db: $db, operation: 'delete', options: ['attempt' => new D\AttemptId(Fixture::id(81001))] + $options);
                self::assertSame('deleted', $retry['result']);
                self::assertSame(1, $retry['calls']);
                self::assertSame('partial', $retry['aggregate']);
                $runtime = new Runtime(db: Fixture::reconnect($db), options: []);
                $store = new PdoDeletionStore(db: $runtime->db, tx: $runtime->tx, tenant: $runtime->tenant, clock: $runtime->clock, validator: PortFixture::validator());
                try {
                    $store->interruptAttempt($runtime->tenant, $options['task'], $options['resource'], $options['attempt'], static fn (): bool => false);
                    self::fail('Unproven runner death cleared uncertainty');
                } catch (D\DomainFailure $error) {
                    self::assertSame('deletion_runner_uncertain', $error->errorCode);
                }
                $store->interruptAttempt($runtime->tenant, $options['task'], $options['resource'], $options['attempt'], static fn (): bool => true);
                Fixture::expect(case: 'PROVIDER-' . strtoupper($window), actual: [
                    'replay' => $replay['result'],
                    'replayedIo' => $replay['calls'],
                    'effects' => $f->catalog->count('resilience_provider_effects'),
                    'aggregate' => $store->aggregate(tenant: $runtime->tenant, task: $options['task']),
                ]);
                $again = Fixture::run(db: $db, operation: 'delete', options: $options);
                self::assertSame('unknown', $again['result']);
                self::assertSame(0, $again['calls']);
            });
        }
    }

    private function options(BlobFixture $f): array
    {
        return [
            'root' => $f->root,
            'scope' => $f->catalog->scope(),
            'reservation' => $f->reservation(known: true),
            'ref' => new D\BlobRef(
                tenantId: $f->catalog->scope()->tenantId,
                sourceId: new D\SourceId(Fixture::id(2)),
                digest: new D\Sha256(Fixture::cases()['payloadDigest']),
                byteLength: new D\Uint64('3'),
                storageId: 'local-v1',
                objectVersion: Fixture::id(300),
                ownerKind: 'owned',
            ),
        ];
    }
}
