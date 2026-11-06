<?php
declare(strict_types=1);

namespace KnowKit\Tests\Storage\Catalog;

use KnowKit\Adapter\Postgres\Catalog\Migrate;
use KnowKit\Domain as D;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

#[Group('storage:catalog')]
final class SchemaTest extends TestCase
{
    public function testFreshMigrationContainsCompleteBaselineAndTenantQualifiedForeignKeys(): void
    {
        CatalogFixture::run(function($f) {
            $tables=$f->db->execute('SELECT tablename FROM pg_tables WHERE schemaname=?',[$f->sandbox->schema])->fetchAll(\PDO::FETCH_COLUMN);
            foreach (CatalogFixture::fixture()['tables'] as $table) { self::assertContains($table,$tables); }
            self::assertNotContains('vector_entries',$tables);
            $rows=$f->db->execute("SELECT c.conname,c.confdeltype AS delete_action,t.relname,pg_get_constraintdef(c.oid) definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=? AND c.contype='f'",[$f->sandbox->schema])->fetchAll(\PDO::FETCH_ASSOC);
            self::assertGreaterThan(90,count($rows));
            foreach ($rows as $r) {
                self::assertSame('r',$r['delete_action'],$r['definition']);
                self::assertTrue(str_contains($r['definition'],'FOREIGN KEY (tenant_id') || $r['relname']==='tenants',$r['definition']);
                if ($r['relname']==='tombstones') { self::assertStringNotContainsString('REFERENCES sources',$r['definition']); }
            }
            $indexes=$f->db->execute('SELECT indexname FROM pg_indexes WHERE schemaname=?',[$f->sandbox->schema])->fetchAll(\PDO::FETCH_COLUMN);
            foreach (['lexical_scope','lexical_full_text','lexical_exact_tokens','jobs_available','jobs_expired','outbox_pending','resource_staging_identity'] as $index) { self::assertContains($index,$indexes); }
            self::assertSame(['C','C'],$f->db->execute("SELECT c.collname FROM pg_type t JOIN pg_collation c ON c.oid=t.typcollation JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname=? AND t.typname IN ('kk_ulid','kk_digest') ORDER BY t.typname",[$f->sandbox->schema])->fetchAll(\PDO::FETCH_COLUMN));
            self::assertSame(1,$f->count('schema_migrations'));
        });
    }
    public function testMigrationReplayAndChecksumMismatch(): void
    {
        CatalogFixture::run(function($f) {
            $pdo=$f->db->pdo; $pdo->exec('RESET ROLE');
            (new Migrate($pdo,$f->sandbox->schema))->apply(CatalogFixture::root().'/packages/core/migrations/postgres/001_metadata.sql');
            self::assertSame(1,$f->count('schema_migrations'));
            $pdo->exec("UPDATE schema_migrations SET checksum=repeat('0',64)");
            try { (new Migrate($pdo,$f->sandbox->schema))->apply(CatalogFixture::root().'/packages/core/migrations/postgres/001_metadata.sql'); self::fail('Changed checksum accepted'); }
            catch (\RuntimeException $e) { self::assertSame('Migration checksum mismatch',$e->getMessage()); }
        });
    }
    public function testForeignMembershipNullUniquenessAndCollectionCycleAreRejectedByDatabase(): void
    {
        CatalogFixture::run(function($f) {
            foreach ([1,9] as $tenant) { $f->tenant($tenant); $f->kb($tenant); $f->source(2,$tenant); }
            $p=$f->db->pdo; $t=CatalogFixture::id(1); $kb=CatalogFixture::id(100);
            $sql='INSERT INTO collections(tenant_id,id,kb_id,parent_id,name,metadata) VALUES (?,?,?,?,?,\'{}\')';
            foreach ([[200,null],[201,200]] as [$id,$parent]) { $p->prepare($sql)->execute([$t,CatalogFixture::id($id),$kb,$parent===null?null:CatalogFixture::id($parent),'fixture']); }
            CatalogFixture::sqlReject($p,'UPDATE collections SET parent_id=? WHERE tenant_id=? AND id=?',[CatalogFixture::id(201),$t,CatalogFixture::id(200)],['23514']);
            self::assertSame(3,$f->count('collection_closure'));
            $f->source(8,9);
            CatalogFixture::sqlReject($p,'INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,\'{}\')',[$t,CatalogFixture::id(400),CatalogFixture::id(8),$kb],['23503','23514']);
            $p->prepare('INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,\'{}\')')->execute([$t,CatalogFixture::id(401),CatalogFixture::id(2),$kb]);
            CatalogFixture::sqlReject($p,'INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,metadata) VALUES (?,?,?,?,\'{}\')',[$t,CatalogFixture::id(402),CatalogFixture::id(2),$kb],['23505']);
            CatalogFixture::sqlReject($p,$sql,[$t,CatalogFixture::id(205),CatalogFixture::id(500),null,'foreign'],['23503']);
            self::assertSame(1,$f->count('source_memberships'));
        });
    }
    public function testUnseenTombstoneAndImmutableIdentityCannotBeBypassed(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant();
            $f->db->execute('INSERT INTO tombstones VALUES (?,?,?,?,?,?,?)',[CatalogFixture::id(1),CatalogFixture::id(500),CatalogFixture::id(2),'source_revoked',CatalogFixture::id(501),'1','future_versions']);
            CatalogFixture::sqlReject($f->db->pdo,'INSERT INTO sources(tenant_id,id,owner_ref,origin,lifecycle_state) VALUES (?,?,?,?,\'active\')',[CatalogFixture::id(1),CatalogFixture::id(2),'owner:fixture','host:upload'],['23514']);
            CatalogFixture::sqlReject($f->db->pdo,"UPDATE tombstones SET reason='source_deleted'",[],['23514']);
            CatalogFixture::sqlReject($f->db->pdo,'DELETE FROM tombstones',[],['23514']);
            self::assertSame(1,$f->count('tombstones')); self::assertSame(0,$f->count('sources'));
        });
    }
    public function testNullableReservationFinalizeAndExactAbsentProof(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->source(); $p=$f->db->pdo; $t=CatalogFixture::id(1); $source=CatalogFixture::id(2); $id=CatalogFixture::id(300); $staging=CatalogFixture::id(301);
            $sql='INSERT INTO resource_ledger(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,staging_id,state) VALUES (?,?,?,\'owned\',\'test\',\'staging-key\',\'v1\',?,\'reserved\')';
            CatalogFixture::sqlReject($p,$sql,[$t,$id,$source,null],['23514']);
            $p->prepare($sql)->execute([$t,$id,$source,$staging]);
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET state='accepted' WHERE id=?",[$id],['23514']);
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET state='deleted' WHERE id=?",[$id],['23514']);
            $task=CatalogFixture::id(350);
            $p->prepare("INSERT INTO deletion_tasks VALUES (?,?,?,'pending','[]',0,?,NULL)")->execute([$t,$task,$source,str_repeat('a',64)]);
            $p->prepare("INSERT INTO resource_writers VALUES (?,?,?,0,'active',NULL,now(),NULL)")->execute([$t,CatalogFixture::id(360),$id]);
            $p->prepare("INSERT INTO deletion_attempts(tenant_id,id,task_id,resource_id,in_flight,result,provider_receipt_json,observed_at,storage_key,object_version,staging_id) VALUES (?,?,?,?,false,'absent','\"durable exact receipt\"',now(),'staging-key','wrong',?)")->execute([$t,CatalogFixture::id(351),$task,$id,$staging]);
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET state='deleted' WHERE id=?",[$id],['23514']);
            $p->exec("UPDATE resource_writers SET state='closed',closed_at=now()");
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET state='deleted' WHERE id=?",[$id],['23514']);
            $p->prepare("INSERT INTO deletion_attempts(tenant_id,id,task_id,resource_id,in_flight,result,provider_receipt_json,observed_at,storage_key,object_version,staging_id) VALUES (?,?,?,?,false,'absent','\"durable exact receipt\"',now(),'staging-key','v1',?)")->execute([$t,CatalogFixture::id(352),$task,$id,$staging]);
            $p->prepare("UPDATE resource_ledger SET state='deleted' WHERE id=?")->execute([$id]);
            self::assertNull($p->query('SELECT digest FROM resource_ledger')->fetchColumn());
            $f->resource(400);
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET digest=repeat('b',64) WHERE id=?",[CatalogFixture::id(400)],['23514']);
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET source_id=? WHERE id=?",[CatalogFixture::id(3),CatalogFixture::id(400)],['23514']);
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET state='deleted' WHERE id=?",[CatalogFixture::id(400)],['23514']);
            CatalogFixture::sqlReject($p,"UPDATE deletion_attempts SET result='deleted'",[],['23514']);
            $p->prepare($sql)->execute([$t,CatalogFixture::id(410),$source,CatalogFixture::id(411)]);
            $p->prepare("INSERT INTO resource_writers VALUES (?,?,?,0,'active',NULL,now(),NULL)")->execute([$t,CatalogFixture::id(412),CatalogFixture::id(410)]);
            $p->prepare("UPDATE resource_ledger SET digest=repeat('e',64),byte_length=15,storage_key='canonical-key',object_version='cas-v1',state='uploaded' WHERE id=?")->execute([CatalogFixture::id(410)]);
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET state='accepted' WHERE id=?",[CatalogFixture::id(410)],['23514']);
            $p->prepare("UPDATE resource_writers SET state='closed',closed_at=now() WHERE id=?")->execute([CatalogFixture::id(412)]);
            $p->prepare("UPDATE resource_ledger SET state='accepted' WHERE id=?")->execute([CatalogFixture::id(410)]);
            CatalogFixture::sqlReject($p,"UPDATE resource_ledger SET storage_key='other-key' WHERE id=?",[CatalogFixture::id(410)],['23514']);
            self::assertSame(CatalogFixture::id(411),$p->query('SELECT staging_id FROM resource_ledger WHERE id=\''.CatalogFixture::id(410).'\'')->fetchColumn());
        });
    }
    public function testRestoreProofAndDependencyCycleFailClosed(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->source(); $p=$f->db->pdo; $t=CatalogFixture::id(1);
            CatalogFixture::sqlReject($p,"INSERT INTO restore_receipts VALUES (?,?,0,false,0,?,true,'ready')",[$t,CatalogFixture::id(500),str_repeat('b',64)],['23514']);
            foreach ([600,601] as $id) {
                $p->prepare('INSERT INTO dependency_nodes VALUES (?,?,\'source\',?,NULL,?,?,NULL)')->execute([$t,CatalogFixture::id($id),CatalogFixture::id(2),str_repeat('c',64),str_repeat('d',64)]);
            }
            $p->prepare("INSERT INTO dependency_edges VALUES (?,?,?,'derived')")->execute([$t,CatalogFixture::id(600),CatalogFixture::id(601)]);
            CatalogFixture::sqlReject($p,"INSERT INTO dependency_edges VALUES (?,?,?,'derived')",[$t,CatalogFixture::id(601),CatalogFixture::id(600)],['23514']);
            self::assertSame(1,$f->count('dependency_edges'));
        });
    }
    public function testExplicitMigrationCliUsesChecksumWithoutImplicitInstall(): void
    {
        CatalogFixture::run(function($f) {
            $p=$f->db->pdo; $p->exec('RESET ROLE'); $schema=$f->sandbox->schema.'_cli'; $p->exec('CREATE SCHEMA '.$schema);
            try {
                $env=array_merge(getenv(),['KNOWKIT_PG_DSN'=>getenv('TEST_PG_DSN')?:'pgsql:host=127.0.0.1;port=55432;dbname=knowkit_test',
                    'KNOWKIT_PG_USER'=>getenv('TEST_PG_USER')?:'knowkit_test','KNOWKIT_PG_PASSWORD'=>getenv('TEST_PG_PASSWORD')?:'']);
                $run=function() use($schema,$env) {
                    $pipes=[]; $process=proc_open([PHP_BINARY,CatalogFixture::root().'/packages/core/src/Adapter/Postgres/Catalog/migrate.php',$schema],
                        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,CatalogFixture::root(),$env);
                    self::assertIsResource($process); fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]); return [proc_close($process),$out,$err];
                };
                self::assertSame([0,"001_metadata applied\n",''],$run());
                self::assertSame([0,"001_metadata applied\n",''],$run());
                self::assertSame(1,(int)$p->query('SELECT count(*) FROM '.$schema.'.schema_migrations')->fetchColumn());
                $p->exec("UPDATE ".$schema.".schema_migrations SET checksum=repeat('0',64)");
                self::assertSame([1,'',"Migration failed; verify schema, connection and checksum\n"],$run());
            } finally { $p->exec('DROP SCHEMA '.$schema.' CASCADE'); }
        });
    }
}
