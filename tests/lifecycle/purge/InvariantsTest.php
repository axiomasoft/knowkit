<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Purge;

use KnowKit\Adapter\Postgres\Catalog\{Connection, Migrate, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Deletion\PdoDeletionStore;
use KnowKit\Application\Deletion\DeletionWorker;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Port\DeletionTransport;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use KnowKit\Tests\Tooling\DatabaseSandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:purge')]
final class InvariantsTest extends TestCase
{
    public function testMismatchedReceiptAndNoncanonicalStatusesFailClosed(): void
    {
        Fixture::run(function ($f) {
            $f->purge();
            $transport = new class implements DeletionTransport {
                public function provesExactVersion(D\DeletionTarget $target): bool { return true; }
                public function delete(D\DeletionTarget $target, D\AttemptId $attempt): D\DeletionReceipt
                {
                    return new D\DeletionReceipt(new JsonObject(['attemptId'=>$attempt->value, 'taskId'=>$target->taskId->value,
                        'resourceId'=>Fixture::id(999), 'inFlight'=>false, 'result'=>'deleted', 'providerReceipt'=>'opaque-proof',
                        'knownExpiry'=>null, 'observedAt'=>'2026-11-06T12:00:01Z']), PortFixture::validator());
                }
            };
            $worker = new DeletionWorker($f->store, $transport, $f->clock, PortFixture::validator());
            $receipt = $worker->attempt($f->lifecycle->tenant, $f->task, new D\ResourceId(Fixture::id(3003)), new D\AttemptId(Fixture::id(81000)));
            self::assertSame('unknown', $receipt->toWire()->get('result')); self::assertSame('unknown', $f->status());
            self::assertSame('DELETION_RECEIPT_MISMATCH', $f->c()->db->row('SELECT error_code FROM deletion_attempts')['error_code']);
            foreach (['failed','completed','succeeded'] as $alias) {
                CatalogFixture::sqlReject($f->c()->db->pdo, 'UPDATE deletion_tasks SET status=?', [$alias]);
                CatalogFixture::sqlReject($f->c()->db->pdo, 'UPDATE deletion_attempts SET result=?', [$alias]);
            }
            CatalogFixture::sqlReject($f->c()->db->pdo, "UPDATE resource_ledger SET resource_class='backup_obligation'");
            CatalogFixture::sqlReject($f->c()->db->pdo, "UPDATE resource_ledger SET retention_until=now()");
            CatalogFixture::sqlReject($f->c()->db->pdo, "DELETE FROM resource_ledger");
            self::assertSame(3, $f->c()->count('resource_ledger'));
        });
    }

    public function testUpgradeInvalidatesUnprovenDoneAndPreservesTaskIdentity(): void
    {
        (new DatabaseSandbox())->run(function ($sandbox) {
            $pdo = $sandbox->connection; $directory = dirname(__DIR__,3).'/packages/core/migrations/postgres';
            $pdo->exec('CREATE TABLE schema_migrations(id text PRIMARY KEY,checksum char(64) NOT NULL,applied_at timestamptz NOT NULL)');
            foreach (['001_metadata','002_metadata_uint32','003_jobs','004_generation_checks','005_outbox_delivery'] as $id) {
                $sql = self::migrationSql($directory, $id); $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations VALUES (?,?,clock_timestamp())')->execute([$id,hash('sha256',$sql)]);
            }
            $pdo->prepare("INSERT INTO tenants(id,name) VALUES (?,'fixture')")->execute([Fixture::id(1)]);
            $pdo->prepare("INSERT INTO sources(tenant_id,id,owner_ref,origin,lifecycle_state) VALUES (?,?,'owner:fixture','host:upload','revoked')")->execute([Fixture::id(1),Fixture::id(2)]);
            $pdo->prepare("INSERT INTO deletion_tasks VALUES (?,?,?,'done','[]',0,?,NULL)")->execute([Fixture::id(1),Fixture::id(600),Fixture::id(2),str_repeat('a',64)]);
            self::assertSame('done', $pdo->query('SELECT status FROM deletion_tasks')->fetchColumn());
            $migrate = new Migrate($pdo,$sandbox->schema); $migrate->apply($directory.'/001_metadata.sql');
            self::assertSame('unknown',$pdo->query('SELECT status FROM deletion_tasks')->fetchColumn());
            self::assertSame(14,(int)$pdo->query('SELECT count(*) FROM schema_migrations')->fetchColumn());
            $migrate->apply($directory.'/001_metadata.sql');
            self::assertSame('unknown',$pdo->query('SELECT status FROM deletion_tasks')->fetchColumn());
            self::assertSame(Fixture::id(600),$pdo->query('SELECT id FROM deletion_tasks')->fetchColumn());
        });
    }

    public function testPre006DeletedResourcesCanObtainFreshVerificationAfterUpgrade(): void
    {
        foreach ([false,true] as $reservation) {
            (new DatabaseSandbox())->run(function ($sandbox) use ($reservation) {
                $pdo = $sandbox->connection; $directory = dirname(__DIR__,3).'/packages/core/migrations/postgres';
                $pdo->exec('CREATE TABLE schema_migrations(id text PRIMARY KEY,checksum char(64) NOT NULL,applied_at timestamptz NOT NULL)');
                foreach (['001_metadata','002_metadata_uint32','003_jobs','004_generation_checks','005_outbox_delivery'] as $id) {
                    $sql = self::migrationSql($directory, $id); $pdo->exec($sql);
                    $pdo->prepare('INSERT INTO schema_migrations VALUES (?,?,clock_timestamp())')->execute([$id,hash('sha256',$sql)]);
                }
                $tenant = new D\TenantId(Fixture::id(1)); $source = new D\SourceId(Fixture::id(2));
                $resource = new D\ResourceId(Fixture::id(300)); $task = new D\DeletionTaskId(Fixture::id(600));
                $old = new D\AttemptId(Fixture::id(601)); $attempt = new D\AttemptId(Fixture::id(602));
                $pdo->prepare("INSERT INTO tenants(id,name) VALUES (?,'fixture')")->execute([$tenant->value]);
                $pdo->prepare("INSERT INTO sources(tenant_id,id,owner_ref,origin,lifecycle_state) VALUES (?,?,'owner:fixture','host:upload','active')")->execute([$tenant->value,$source->value]);
                $pdo->prepare("INSERT INTO resource_ledger(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,staging_id,digest,byte_length,state) VALUES (?,?,?,'owned','legacy-store','legacy/key','legacy-v1',?,?,?,?)")
                    ->execute([$tenant->value,$resource->value,$source->value,$reservation ? Fixture::id(700) : null,
                        $reservation ? null : str_repeat('b',64),$reservation ? null : '1',$reservation ? 'reserved' : 'uploaded']);
                $pdo->prepare("UPDATE sources SET lifecycle_state='deletion_requested',lifecycle_epoch=1 WHERE tenant_id=? AND id=?")->execute([$tenant->value,$source->value]);
                $pdo->prepare("INSERT INTO tombstones VALUES (?,?,?,'source_deleted',?,1,'future_versions')")
                    ->execute([$tenant->value,Fixture::id(603),$source->value,Fixture::id(604)]);
                $inventory = [new JsonObject(['resourceId'=>$resource->value,'resourceClass'=>$reservation ? 'upload_reservation' : 'provider_resource','ownerKind'=>'owned','state'=>'pending'])];
                $digest = HashDomain::ResourceInventory->digest($inventory);
                $pdo->prepare("INSERT INTO deletion_tasks VALUES (?,?,?,'pending',?::jsonb,1,?,NULL)")
                    ->execute([$tenant->value,$task->value,$source->value,Jcs::canonicalize($inventory),$digest]);
                $pdo->prepare("INSERT INTO deletion_attempts(tenant_id,id,task_id,resource_id,in_flight,result,provider_receipt_json,observed_at,storage_key,object_version,staging_id) VALUES (?,?,?,?,false,?,'\"legacy-receipt\"','2026-11-06T12:00:00Z','legacy/key','legacy-v1',?)")
                    ->execute([$tenant->value,$old->value,$task->value,$resource->value,$reservation ? 'absent' : 'deleted',$reservation ? Fixture::id(700) : null]);
                $pdo->prepare("UPDATE resource_ledger SET state='deleted' WHERE tenant_id=? AND id=?")->execute([$tenant->value,$resource->value]);
                $pdo->prepare("UPDATE deletion_tasks SET status='done' WHERE tenant_id=? AND id=?")->execute([$tenant->value,$task->value]);
                self::assertSame('done',$pdo->query('SELECT status FROM deletion_tasks')->fetchColumn());
                (new Migrate($pdo,$sandbox->schema))->apply($directory.'/001_metadata.sql');
                self::assertSame('unknown',$pdo->query('SELECT status FROM deletion_tasks')->fetchColumn());
                self::assertSame('deleted',$pdo->query('SELECT state FROM resource_ledger')->fetchColumn());
                $history = $pdo->query('SELECT * FROM deletion_attempts')->fetch(\PDO::FETCH_ASSOC);
                self::assertFalse($history['exact_version_proof']);
                $role = 'knowkit_test_'.bin2hex(random_bytes(8)); $pdo->exec('CREATE ROLE '.$role.' NOLOGIN NOSUPERUSER');
                try {
                    $pdo->exec('GRANT USAGE ON SCHEMA '.$sandbox->schema.' TO '.$role);
                    $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA '.$sandbox->schema.' TO '.$role);
                    $pdo->exec('SET ROLE '.$role);
                    $db = new Connection($pdo,$sandbox->schema); $clock = new MutableClock();
                    $store = new PdoDeletionStore($db,new PdoCatalogTransaction($db),$tenant,$clock,PortFixture::validator());
                    $transport = new class($pdo) implements DeletionTransport {
                        public int $calls = 0;
                        public function __construct(private \PDO $pdo) {}
                        public function provesExactVersion(D\DeletionTarget $target): bool { return $target->storageId === 'legacy-store' && $target->objectVersion === 'legacy-v1'; }
                        public function delete(D\DeletionTarget $target, D\AttemptId $attempt): D\DeletionReceipt
                        {
                            TestCase::assertFalse($this->pdo->inTransaction());
                            TestCase::assertSame('legacy/key',$target->storageKey); ++$this->calls;
                            return new D\DeletionReceipt(new JsonObject(['attemptId'=>$attempt->value,'taskId'=>$target->taskId->value,
                                'resourceId'=>$target->resourceId->value,'inFlight'=>false,'result'=>'absent','providerReceipt'=>'fresh-exact-proof',
                                'knownExpiry'=>null,'observedAt'=>'2026-11-06T12:00:01Z']),PortFixture::validator());
                        }
                    };
                    $worker = new DeletionWorker($store,$transport,$clock,PortFixture::validator());
                    self::assertSame($reservation ? 'absent' : 'deleted',$worker->attempt($tenant,$task,$resource,$old)->toWire()->get('result'));
                    self::assertSame(0,$transport->calls);
                    self::assertSame('unknown',$store->aggregate($tenant,$task));
                    $receipt = $worker->attempt($tenant,$task,$resource,$attempt);
                    self::assertSame('absent',$receipt->toWire()->get('result')); self::assertSame(1,$transport->calls);
                    self::assertSame($receipt->canonical(),$worker->attempt($tenant,$task,$resource,$attempt)->canonical());
                    self::assertSame(1,$transport->calls); self::assertSame('done',$store->aggregate($tenant,$task));
                    self::assertSame($history,$db->row('SELECT * FROM deletion_attempts WHERE tenant_id=? AND id=?',[$tenant->value,$old->value]));
                    self::assertTrue($db->row('SELECT exact_version_proof FROM deletion_attempts WHERE tenant_id=? AND id=?',[$tenant->value,$attempt->value])['exact_version_proof']);
                    self::assertSame($digest,$db->row('SELECT inventory_digest FROM deletion_tasks WHERE tenant_id=? AND id=?',[$tenant->value,$task->value])['inventory_digest']);
                    self::assertSame($reservation ? null : str_repeat('b',64),$db->row('SELECT digest FROM resource_ledger WHERE tenant_id=? AND id=?',[$tenant->value,$resource->value])['digest']);
                } finally {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $pdo->exec('RESET ROLE'); $pdo->exec('DROP OWNED BY '.$role); $pdo->exec('DROP ROLE '.$role);
                }
            });
        }
    }

    private static function migrationSql(string $directory, string $id): string
    {
        static $sql = [];
        $bytes = $sql[$id] ??= file_get_contents($directory . '/' . $id . '.sql');
        if (!is_string($bytes)) {
            throw new \RuntimeException('Missing migration ' . $id);
        }

        return $bytes;
    }
}
