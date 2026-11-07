<?php
declare(strict_types=1);
namespace KnowKit\Tests\Events\Outbox;

use KnowKit\Adapter\Postgres\Catalog\{Connection,Migrate,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Outbox\PdoDelivery;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use KnowKit\Tests\Tooling\DatabaseSandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('events:outbox')]
final class MigrationTest extends TestCase
{
    public function testUpgradePreservesEventAndInboxIdentityAndRequeuesUnfencedLegacyLease(): void
    {
        (new DatabaseSandbox())->run(function(DatabaseSandbox $sandbox): void {
            $pdo = $sandbox->connection; $directory = Fixture::root().'/packages/core/migrations/postgres';
            $pdo->exec('CREATE TABLE schema_migrations(id text PRIMARY KEY,checksum char(64) NOT NULL,applied_at timestamptz NOT NULL)');
            foreach (['001_metadata','002_metadata_uint32','003_jobs','004_generation_checks'] as $id) {
                $sql = file_get_contents($directory.'/'.$id.'.sql'); $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations VALUES (?,?,clock_timestamp())')->execute([$id,hash('sha256',$sql)]);
            }
            $e = Fixture::event(); $w = $e->toWire();
            $pdo->prepare("INSERT INTO tenants(id,name) VALUES (?,'synthetic tenant')")->execute([Fixture::id(1)]);
            $pdo->prepare("INSERT INTO outbox(tenant_id,id,event_json,payload_digest,aggregate_version,delivery_state,delivery_fence,lease_until,attempts)
                VALUES (?,?,?::jsonb,?,'1','leased',7,clock_timestamp()+interval '1 hour',2)")->execute([Fixture::id(1),$w->get('eventId'),$e->canonical(),$w->get('payloadDigest')]);
            $pdo->prepare("INSERT INTO inbox VALUES (?,?,'legacy',?,?,clock_timestamp())")->execute([Fixture::id(1),Fixture::id(700),$w->get('eventId'),$w->get('payloadDigest')]);
            $eventBefore = $pdo->query('SELECT event_json,payload_digest,aggregate_version FROM outbox')->fetch(\PDO::FETCH_ASSOC);
            $inboxBefore = $pdo->query('SELECT tenant_id,id,consumer_id,event_id,payload_digest,applied_at FROM inbox')->fetchAll(\PDO::FETCH_ASSOC);
            $migrate = new Migrate($pdo,$sandbox->schema); $migrate->apply($directory.'/001_metadata.sql');
            self::assertSame($eventBefore,$pdo->query('SELECT event_json,payload_digest,aggregate_version FROM outbox')->fetch(\PDO::FETCH_ASSOC));
            self::assertSame($inboxBefore,$pdo->query('SELECT tenant_id,id,consumer_id,event_id,payload_digest,applied_at FROM inbox')->fetchAll(\PDO::FETCH_ASSOC));
            self::assertSame('pending',$pdo->query('SELECT delivery_state FROM outbox')->fetchColumn());
            self::assertSame(7,(int)$pdo->query('SELECT count(*) FROM schema_migrations')->fetchColumn());
            $migrate->apply($directory.'/001_metadata.sql');
            self::assertCount(7,$pdo->query('SELECT id FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));
            CatalogFixture::sqlReject($pdo,"UPDATE outbox SET delivery_state='leased'",[],['23514']);
            CatalogFixture::sqlReject($pdo,"UPDATE outbox SET lease_until=clock_timestamp()",[],['23514']);
            CatalogFixture::sqlReject($pdo,"UPDATE inbox SET payload_digest=repeat('0',64)",[],['23514']);
            $role = 'knowkit_test_'.bin2hex(random_bytes(8)); $pdo->exec('CREATE ROLE '.$role.' NOLOGIN NOSUPERUSER');
            try {
                $pdo->exec('GRANT USAGE ON SCHEMA '.$sandbox->schema.' TO '.$role);
                $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA '.$sandbox->schema.' TO '.$role); $pdo->exec('SET ROLE '.$role);
                $db = new Connection($pdo,$sandbox->schema);
                $delivery = new PdoDelivery($db,new PdoCatalogTransaction($db),new D\TenantId(Fixture::id(1)),PortFixture::validator());
                $lease = $delivery->leaseDelivery(1)[0]; self::assertSame('8',$lease->fence->value);
                self::assertSame($e->canonical(),$delivery->event($lease)->canonical()); self::assertTrue($delivery->ack($lease));
                self::assertSame('3',(string)$pdo->query('SELECT attempts FROM outbox')->fetchColumn());
                // Legacy inbox lacks immutable envelope proof: block safely, never silently overwrite it.
                $f = new Fixture(new CatalogFixture($sandbox));
                self::assertSame('blocked',$f->consumer($f->inbox('legacy'))->consume($e->canonical()));
            } finally {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $pdo->exec('RESET ROLE'); $pdo->exec('DROP OWNED BY '.$role); $pdo->exec('DROP ROLE '.$role);
            }
        });
    }
}
