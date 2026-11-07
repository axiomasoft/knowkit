<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Purge;

use KnowKit\Adapter\Postgres\Deletion\BlobDeletionTransport;
use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Application\Deletion\DeletionWorker;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, StrictJson};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Blobs\BlobFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:purge')]
final class InventoryTest extends TestCase
{
    public function testExactConfirmedMissingLocalObjectCompletesAndPreservesAuditIdentity(): void
    {
        Fixture::run(function ($f) {
            $blob = new BlobFixture($f->c());
            try {
                $ref = $blob->put(); unlink($blob->path($ref)); $f->purge();
                $worker = new DeletionWorker($f->store, new BlobDeletionTransport($blob->store, 'local-v1', true), $f->clock, PortFixture::validator());
                $receipt = $worker->attempt($f->lifecycle->tenant, $f->task, new D\ResourceId(Fixture::id(300)), new D\AttemptId(Fixture::id(81000)));
                $f->transport->result = 'absent';
                foreach ([3003,4004,5011] as $id) { $f->attempt($id); }
                Fixture::expected('CONFIRMED-ABSENT', ['receipt'=>$receipt->toWire()->get('result'), 'task'=>$f->status()]);
                self::assertSame(1, $f->c()->count('source_versions')); self::assertSame(4, $f->c()->count('resource_ledger'));
                self::assertSame('deletion_requested', $f->c()->db->row('SELECT lifecycle_state FROM sources')['lifecycle_state']);
            } finally { BlobFixture::removeTree($blob->root); }
        });
    }
    public function testInventoryEnumeratesClassesAndExcludesOwnerManagedBytes(): void
    {
        Fixture::run(function ($f) {
            $c = $f->c(); $c->resource(600); $c->resource(601, 2, 1, 'external');
            $c->db->execute('INSERT INTO assets(tenant_id,id,revision_id,resource_id,media_type,source_locators_json,origin) VALUES (?,?,?,?,?,?::jsonb,?)',
                [Fixture::id(1), Fixture::id(700), Fixture::id(4), Fixture::id(600), 'image/png', '[]', 'source']);
            $c->db->execute("INSERT INTO resource_ledger(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state,resource_class) VALUES (?,?,?,'owned','vectors','key','v1',?,1,'uploaded','embedding')",
                [Fixture::id(1), Fixture::id(602), Fixture::id(2), str_repeat('e',64)]);
            $f->purge(); $row = $c->db->row('SELECT * FROM deletion_tasks WHERE id=?', [$f->task->value]);
            $inventory = StrictJson::decode($row['required_resources_json']);
            self::assertSame($row['inventory_digest'], HashDomain::ResourceInventory->digest($inventory));
            $classes = array_map(static fn ($r) => $r->get('resourceClass'), $inventory); sort($classes);
            Fixture::expected('R31-A01', ['classes'=>$classes, 'members'=>count($inventory)]);
            $f->finishAll(); self::assertSame('done', $f->status());
            self::assertSame('accepted', $c->db->row('SELECT state FROM resource_ledger WHERE id=?', [Fixture::id(601)])['state']);
            self::assertSame($row['required_resources_json'], $c->db->row('SELECT required_resources_json FROM deletion_tasks WHERE id=?', [$f->task->value])['required_resources_json']);
        });
    }
    public function testLegalHoldRetainsBytesWithoutGrantingAccessAndLateHoldIsRejected(): void
    {
        Fixture::run(function ($f) {
            $blob = new BlobFixture($f->c());
            try {
                $ref = $blob->put();
                $f->lifecycle->lifecycle->placeLegalHold($f->lifecycle->scope(), new D\LegalHold(new D\SourceId(Fixture::id(2)), true, new D\Decimal('0')), new D\IdempotencyKey('hold'));
                $f->purge('1'); Fixture::failure(fn () => $f->attempt(3003), 'retention_hold');
                self::assertSame('abc', file_get_contents($blob->path($ref)));
                Fixture::failure(fn () => $blob->store->open($ref), 'blob_not_accepted');
                Fixture::expected('LEGAL-HOLD', ['task'=>$f->status(), 'calls'=>$f->transport->calls,
                    'access'=>$f->lifecycle->atomic(fn () => $f->lifecycle->decision($f->lifecycle->ref('chunk',11)))->value]);
                $f->lifecycle->lifecycle->placeLegalHold($f->lifecycle->scope(), new D\LegalHold(new D\SourceId(Fixture::id(2)), false, new D\Decimal('2')), new D\IdempotencyKey('release'));
                $f->attempt(3003);
                Fixture::failure(fn () => $f->lifecycle->lifecycle->placeLegalHold($f->lifecycle->scope(), new D\LegalHold(new D\SourceId(Fixture::id(2)), true, new D\Decimal('3')), new D\IdempotencyKey('late-hold')), 'HOLD_TOO_LATE');
                self::assertSame(D\Decision::Deny, $f->lifecycle->atomic(fn () => $f->lifecycle->decision($f->lifecycle->ref('chunk',11))));
                self::assertFileExists($blob->path($ref));
            } finally { BlobFixture::removeTree($blob->root); }
        });
    }
    public function testBackupExpiryRequiresSeparateExactProofAfterRetention(): void
    {
        Fixture::run(function ($f) {
            $f->c()->db->execute("INSERT INTO resource_ledger(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state,resource_class,retention_until) VALUES (?,?,?,'owned','backup','backup/key','b1',?,1,'uploaded','backup_obligation',?)",
                [Fixture::id(1), Fixture::id(600), Fixture::id(2), str_repeat('b',64), '2026-11-06T13:00:00Z']);
            $f->purge(); foreach ([3003,4004,5011] as $id) { $f->attempt($id); }
            Fixture::failure(fn () => $f->attempt(600), 'backup_retention');
            // Even a caller bypassing beginAttempt cannot use the physical binding before retention expires.
            $resource = new D\ResourceId(Fixture::id(600)); $attempt = new D\AttemptId(Fixture::id(81000));
            $f->c()->db->execute("INSERT INTO deletion_attempts(tenant_id,id,task_id,resource_id,in_flight,storage_key,object_version) VALUES (?,?,?,?,true,'backup/key','b1')",
                [$f->lifecycle->tenant->value,$attempt->value,$f->task->value,$resource->value]);
            $f->c()->db->execute("UPDATE resource_ledger SET state='deleting' WHERE id=?",[$resource->value]);
            $binding = new PdoResourceLedger($f->c()->db,$f->c()->tx,$f->lifecycle->tenant,'backup',$f->clock);
            Fixture::failure(fn () => $binding->deletionBinding(new D\ResourceRef($f->lifecycle->tenant,'resource',$resource),$attempt), 'resource_in_use');
            Fixture::expected('BACKUP-RETENTION', ['task'=>$f->status()]);
            $f->clock->value = '2026-11-06T14:00:00Z';
            $f->store->interruptAttempt($f->lifecycle->tenant,$f->task,$resource,$attempt,static fn () => true);
            self::assertSame('partial', $f->status());
            self::assertSame('unknown', $f->attempt(600)->toWire()->get('result')); // Clock passage is not backup erasure.
            self::assertSame('partial', $f->status());
            $f->transport->result = 'absent'; $f->transport->expiry = '2026-11-06T13:00:00Z'; $f->attempt(600);
            Fixture::expected('BACKUP-EXPIRY', ['task'=>$f->status()]);
        });
    }
    public function testPreRevokeWriterAndUnfinalizedReservationBlockUntilExactAbsence(): void
    {
        Fixture::run(function ($f) {
            $blob = new BlobFixture($f->c());
            try {
                $r = $blob->ledger->reserveWriter($blob->reservation()); $f->purge();
                Fixture::failure(fn () => $f->attempt(300), 'writer_uncertain');
                Fixture::failure(fn () => $blob->ledger->reserveWriter($blob->reservation(310,311)), 'source_changed');
                foreach ([3003,4004,5011] as $id) { $f->attempt($id); }
                Fixture::expected('LATE-WRITER', ['task'=>$f->status(), 'writer'=>$f->c()->db->row('SELECT state FROM resource_writers WHERE id=?', [$r->writerId->value])['state']]);
                // Synthetic runner stopped: P3.7 owns production quiescence reconciliation, not lease expiry.
                $f->c()->db->execute("UPDATE resource_writers SET state='closed',closed_at=? WHERE id=?", [$f->clock->now()->value, $r->writerId->value]);
                self::assertSame('unknown', $f->attempt(300)->toWire()->get('result')); // deleted cannot fabricate an uncomputed digest.
                $f->transport->result = 'absent'; $f->attempt(300);
                self::assertSame('done', $f->status());
                $row = $f->c()->db->row('SELECT * FROM resource_ledger WHERE id=?', [$r->resource->id->value]);
                self::assertNull($row['digest']); self::assertNull($row['byte_length']); self::assertSame('deleted', $row['state']);
            } finally { BlobFixture::removeTree($blob->root); }
        });
    }
    public function testLateOrphanInvalidatesDoneAndFrozenInventoryCannotBeRewritten(): void
    {
        Fixture::run(function ($f) {
            $f->purge(); $f->finishAll(); self::assertSame('done', $f->status());
            $ledger = new PdoResourceLedger($f->c()->db, $f->c()->tx, $f->lifecycle->tenant, 'test', $f->clock);
            $ledger->quarantine(new D\SourceId(Fixture::id(2)), Fixture::id(1).'/'.Fixture::id(2).'/orphan', new D\ResourceId(Fixture::id(600)), null);
            self::assertSame('unknown', $f->c()->db->row('SELECT status FROM deletion_tasks WHERE id=?', [$f->task->value])['status']);
            Fixture::expected('LATE-ORPHAN', ['task'=>$f->status(), 'resources'=>$f->c()->count('resource_ledger')]);
            self::assertTrue($f->c()->db->row('SELECT retention_hold FROM resource_ledger WHERE id=?', [Fixture::id(600)])['retention_hold']);
            CatalogFixture::sqlReject($f->c()->db->pdo, "UPDATE deletion_tasks SET status='done' WHERE id=?", [$f->task->value]);
            CatalogFixture::sqlReject($f->c()->db->pdo, "UPDATE deletion_tasks SET inventory_epoch=inventory_epoch+1 WHERE id=?", [$f->task->value]);
            CatalogFixture::sqlReject($f->c()->db->pdo, 'UPDATE deletion_attempts SET storage_key=? WHERE task_id=?', ['wrong', $f->task->value]);
        });
    }
    public function testUncertainJobAndNoInventoryCannotBecomeDone(): void
    {
        Fixture::run(function ($f) {
            $f->purge(); $f->finishAll();
            $f->c()->db->execute("INSERT INTO jobs(tenant_id,id,source_id,source_version_id,kb_id,type,stage,state,available_at,attempt_deadline,source_epoch,idempotency_key,request_fingerprint,uncertain_external_effect) VALUES (?,?,?,?,?,'extract','extract','cancelled',?,?,1,'late-job',?,true)",
                [Fixture::id(1),Fixture::id(600),Fixture::id(2),Fixture::id(3),Fixture::id(100),$f->clock->now()->value,$f->clock->now()->value,str_repeat('a',64)]);
            Fixture::expected('R31-A02', ['task'=>$f->status()]);
            CatalogFixture::sqlReject($f->c()->db->pdo, "UPDATE deletion_tasks SET status='done' WHERE id=?", [$f->task->value]);
        });
        Fixture::run(function ($f) {
            $f->c()->source(20); $f->lifecycle->addMembership(20, 100, 'empty-member');
            $task = $f->lifecycle->lifecycle->requestPurge($f->lifecycle->scope(), new D\PurgeRequest(new D\SourceId(Fixture::id(20)), new D\Decimal('0')), new D\IdempotencyKey('empty-purge'));
            self::assertSame('pending', $f->store->aggregate($f->lifecycle->tenant, $task));
            CatalogFixture::sqlReject($f->c()->db->pdo, "UPDATE deletion_tasks SET status='done' WHERE id=?", [$task->value]);
        });
    }
}
