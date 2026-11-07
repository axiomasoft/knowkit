<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Dependencies;

use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Application\Dependencies\RequireUsable;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';

#[Group('lifecycle:dependencies')]
final class GraphTest extends TestCase
{
    public function testTombstoneBeforeRootAndSourceRegistration(): void
    {
        Fixture::run(function($f) {
            $event=$f->event(source:77);
            $f->atomic(fn()=>$f->graph->invalidate($event));
            $f->atomic(fn()=>$f->graph->invalidate($event));
            self::assertSame(1,$f->catalog->count('tombstones')); self::assertSame(0,$f->catalog->count('dependency_nodes'));
            self::assertSame(0,$f->catalog->count('lifecycle_events')); // no fake source for the FK
            $f->atomic(fn()=>self::assertSame(D\Decision::Deny,$f->decision($f->ref('source',77))));
            self::assertSame('1',(string)$f->catalog->db->row('SELECT lifecycle_epoch FROM tombstones')['lifecycle_epoch']);
            Fixture::failure(fn()=>$f->catalog->source(77),'storage_conflict');
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('source',77)),'required_parent_missing');
            $changed=$event->event->toWire()->with('payload',new JsonObject(['sourceId'=>Fixture::id(78),'reason'=>'source_revoked','lifecycleEpoch'=>'1','appliesTo'=>'future_versions']));
            $changed=$changed->with('payloadDigest',HashDomain::DomainEventPayload->digest($changed->get('payload')));
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->invalidate(new D\LifecycleEvent(new D\DomainEvent($changed,PortFixture::validator())))),'idempotency_conflict');
            self::assertSame(1,$f->catalog->count('tombstones'));
            // Existing catalog source, but no graph node: revoke still wins over late root registration.
            $existing=$f->event(); $f->atomic(fn()=>$f->revoke($existing));
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('source_version',3)),'ancestor_unusable',D\Outcome::Denied);
            self::assertSame(0,$f->catalog->count('dependency_nodes'));
        });
    }
    public function testImmutableConflictPreservesBytesAndParents(): void
    {
        Fixture::run(function($f) {
            $f->version(30);
            $f->atomic(function() use($f) {
                $a=$f->register('source_version',3); $b=$f->register('source_version',30);
                $n=$f->derived(500,[$a->ref,$b->ref]); $f->register('resource',500,[$b->ref,$a->ref]);
                self::assertSame(D\Decision::Allow,$f->decision($n->ref));
            });
            $before=$f->catalog->db->row('SELECT * FROM dependency_nodes WHERE id=?',[Fixture::id(500)]);
            $edges=$f->catalog->db->execute('SELECT * FROM dependency_edges ORDER BY child_id,parent_id')->fetchAll();
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('resource',500,[$f->ref('source_version',3)])),'immutable_conflict');
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->register(new D\Node($f->ref('resource',500),new D\Fingerprint(str_repeat('f',64))),
                new D\Parents($f->tenant,[$f->ref('source_version',3),$f->ref('source_version',30)]))),'immutable_conflict');
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->graph->register(new D\Node($f->ref('source_version',3),new D\Fingerprint(str_repeat('f',64))),
                new D\Parents($f->tenant,[]))),'immutable_conflict');
            CatalogFixture::sqlReject($f->catalog->db->pdo,'UPDATE source_versions SET source_digest=? WHERE tenant_id=? AND id=?',
                [str_repeat('f',64),Fixture::id(1),Fixture::id(3)],['23514']);
            self::assertSame($before,$f->catalog->db->row('SELECT * FROM dependency_nodes WHERE id=?',[Fixture::id(500)]));
            self::assertSame($edges,$f->catalog->db->execute('SELECT * FROM dependency_edges ORDER BY child_id,parent_id')->fetchAll());
            self::assertSame(hash('sha256','version-3'),$f->catalog->db->row('SELECT source_digest FROM source_versions WHERE id=?',[Fixture::id(3)])['source_digest']);
        });
    }
    public function testCycleSelfReferenceAndDuplicateParentRejected(): void
    {
        Fixture::run(function($f) {
            $f->atomic(function() use($f) { $root=$f->register('source_version',3); $a=$f->derived(500,[$root->ref]); $f->derived(501,[$a->ref]); });
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('source_version',3,[$f->ref('resource',501)])),'immutable_conflict');
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('resource',500,[$f->ref('resource',500)])),'self_reference');
            Fixture::failure(fn()=>new D\Parents($f->tenant,[$f->ref('resource',500),$f->ref('resource',500)]),'duplicate_identifier');
            CatalogFixture::sqlReject($f->catalog->db->pdo,"INSERT INTO dependency_edges VALUES (?,?,?,'required')",
                [Fixture::id(1),Fixture::id(3),Fixture::id(501)],['23514']);
            self::assertSame(2,$f->catalog->count('dependency_edges'));
        });
    }
    public function testMissingAndCrossTenantParentsCannotBecomeVisible(): void
    {
        Fixture::run(function($f) {
            $f->atomic(fn()=>$f->register('source_version',3)); $f->catalog->resource(500);
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('resource',500,[$f->ref('source_version',999)])),'required_parent_missing');
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('resource',500)),'required_parent_missing');
            Fixture::failure(fn()=>new D\Parents($f->tenant,[$f->ref('source_version',3,9)]),'scope_mismatch',D\Outcome::Denied);
            $foreign=new PdoDependencyStore($f->catalog->db,$f->catalog->tx,new D\TenantId(Fixture::id(9)),$f->catalog->ids);
            Fixture::failure(fn()=>$f->atomic(fn()=>$foreign->checkAncestors(new D\NodeRefs($f->tenant,[]))),'scope_mismatch',D\Outcome::Denied);
            $f->catalog->tenant(9); $f->catalog->source(8,9);
            $f->catalog->db->execute('INSERT INTO dependency_nodes VALUES (?,?,\'source\',?,NULL,?,?,NULL)',
                [Fixture::id(9),Fixture::id(8),Fixture::id(8),str_repeat('a',64),str_repeat('b',64)]);
            CatalogFixture::sqlReject($f->catalog->db->pdo,"INSERT INTO dependency_edges VALUES (?,?,?,'required')",[Fixture::id(1),Fixture::id(3),Fixture::id(8)],['23503']);
            self::assertSame(0,$f->catalog->count('dependency_edges'));
            self::assertNull($f->catalog->db->row('SELECT id FROM dependency_nodes WHERE tenant_id=? AND id=?',[Fixture::id(1),Fixture::id(500)]));
        });
    }
    public function testDeepInvalidAncestorAndLateDerivativeBeforePropagation(): void
    {
        Fixture::run(function($f) {
            $last=$f->atomic(function() use($f) { $last=$f->register('source_version',3); for($i=500;$i<524;$i++) { $last=$f->derived($i,[$last->ref]); } return $last; });
            $f->atomic(fn()=>$f->catalog->db->execute("UPDATE source_version_state SET usability='corrected' WHERE tenant_id=? AND id=?",[Fixture::id(1),Fixture::id(3)]));
            $f->atomic(function() use($f,$last) {
                self::assertSame(D\Decision::Deny,$f->decision($last->ref));
                Fixture::failure(fn()=>(new RequireUsable($f->graph))->check(new D\NodeRefs($f->tenant,[$last->ref])),'ancestor_unusable',D\Outcome::Denied);
            },D\LockMode::Share);
            $f->catalog->resource(600);
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('resource',600,[$last->ref])),'ancestor_unusable',D\Outcome::Denied);
            self::assertSame(25,$f->catalog->count('dependency_nodes'));
            self::assertSame(0,(int)$f->catalog->db->execute('SELECT count(*) FROM dependency_nodes WHERE invalidated_epoch IS NOT NULL')->fetchColumn());
        });
    }
    public function testExactVersionCorrectionAndSourceWideDelete(): void
    {
        Fixture::run(function($f) {
            $f->version(30); $f->version(31);
            $f->atomic(function() use($f) { $v1=$f->register('source_version',3); $v2=$f->register('source_version',30); $f->derived(500,[$v1->ref]); $f->derived(501,[$v2->ref]);
                self::assertSame(D\Decision::Allow,$f->decision($v1->ref,$v2->ref)); });
            $correct=$f->event('source_version.corrected');
            $f->atomic(fn()=>$f->graph->invalidate($correct)); $f->atomic(fn()=>$f->graph->invalidate($correct));
            $f->atomic(function() use($f) { self::assertSame(D\Decision::Deny,$f->decision($f->ref('resource',500)));
                self::assertSame(D\Decision::Allow,$f->decision($f->ref('resource',501))); $f->register('source_version',31);
                $f->derived(502,[$f->ref('source_version',30)]); });
            self::assertSame(1,$f->catalog->count('lifecycle_events')); self::assertSame(0,$f->catalog->count('tombstones'));
            $f->atomic(fn()=>$f->graph->invalidate($f->event('source.deletion_requested',epoch:'2')));
            $f->atomic(function() use($f) { self::assertSame(D\Decision::Deny,$f->decision($f->ref('resource',501),$f->ref('resource',502),$f->ref('source_version',31))); });
            $f->atomic(fn()=>$f->graph->invalidate($correct));
            self::assertSame('2',(string)$f->catalog->db->row('SELECT invalidated_epoch FROM dependency_nodes WHERE id=?',[Fixture::id(500)])['invalidated_epoch']);
            Fixture::failure(fn()=>$f->version(32),'storage_conflict');
            self::assertSame(1,$f->catalog->count('tombstones'));
        });
    }
    public function testUnknownDeniedUnavailableAndTransactionGuardsStayDistinct(): void
    {
        Fixture::run(function($f) {
            Fixture::failure(fn()=>$f->graph->checkAncestors(new D\NodeRefs($f->tenant,[])),'scope_mismatch',D\Outcome::Denied);
            Fixture::failure(fn()=>(new NodeMetadata($f->catalog->db,$f->catalog->tx))->resolve($f->ref('source_version',3)),'scope_mismatch',D\Outcome::Denied);
            $f->atomic(function() use($f) {
                Fixture::failure(fn()=>(new NodeMetadata($f->catalog->db,$f->catalog->tx))->resolve($f->ref('source_version',3,9)),'scope_mismatch',D\Outcome::Denied);
                self::assertSame(D\Decision::Unknown,$f->decision($f->ref('source_version',999)));
                Fixture::failure(fn()=>(new RequireUsable($f->graph))->check(new D\NodeRefs($f->tenant,[$f->ref('source_version',999)])),'ancestor_unknown',D\Outcome::Unknown);
            });
            $f->atomic(fn()=>$f->register('source_version',3));
            $f->atomic(fn()=>$f->graph->invalidate($f->event('retention_hold.changed')));
            $f->atomic(fn()=>self::assertSame(D\Decision::Allow,$f->decision($f->ref('source_version',3))));
            try { $f->atomic(fn()=>$f->register('source_version',3),D\LockMode::Share); self::fail('Write without tenant barrier'); }
            catch (\LogicException $e) { self::assertSame('Tenant update guard required',$e->getMessage()); }
        });
    }
    public function testInvalidationRollbackLeavesNoPartialEffect(): void
    {
        Fixture::run(function($f) {
            $f->atomic(fn()=>$f->register('source_version',3)); $before=$f->catalog->count('outbox'); $event=$f->event();
            try { $f->atomic(function() use($f,$event) { $f->revoke($event); throw new \RuntimeException('interrupt'); }); }
            catch (\RuntimeException $e) { self::assertSame('interrupt',$e->getMessage()); }
            self::assertSame($before,$f->catalog->count('outbox')); self::assertSame(0,$f->catalog->count('tombstones')); self::assertSame(0,$f->catalog->count('lifecycle_events'));
            $f->atomic(fn()=>self::assertSame(D\Decision::Allow,$f->decision($f->ref('source_version',3))));
            $f->atomic(fn()=>$f->revoke($event)); $f->atomic(fn()=>$f->graph->invalidate($event));
            self::assertSame(1,$f->catalog->count('lifecycle_events'));
            self::assertSame('1',(string)$f->catalog->db->row('SELECT invalidated_epoch FROM dependency_nodes')['invalidated_epoch']);
        });
    }
    public function testStoredParentSetCannotBeExtendedBehindImmutableDigest(): void
    {
        Fixture::run(function($f) {
            $f->version(30);
            $f->atomic(function() use($f) { $root=$f->register('source_version',3); $f->register('source_version',30); $f->derived(500,[$root->ref]); });
            $f->catalog->db->execute("INSERT INTO dependency_edges VALUES (?,?,?,'required')",[Fixture::id(1),Fixture::id(500),Fixture::id(30)]);
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->decision($f->ref('resource',500))),'immutable_conflict');
            $f->catalog->resource(501);
            Fixture::failure(fn()=>$f->atomic(fn()=>$f->register('resource',501,[$f->ref('resource',500)])),'immutable_conflict');
            self::assertSame(3,$f->catalog->count('dependency_nodes'));
        });
    }
}
