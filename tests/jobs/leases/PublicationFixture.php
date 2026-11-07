<?php
declare(strict_types=1);
namespace KnowKit\Tests\Jobs\Leases;

use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain,JsonObject};
use KnowKit\Tests\Contract\Ports\PortFixture;

/** Real SQL/CAS/outbox composition under queue acceptance guards. This is not a Publishing implementation. */
final class PublicationFixture
{
    public static function prepare(Fixture $f): D\GenerationId
    {
        foreach ([500=>'sealed',501=>'building'] as $id=>$state) {
            $f->catalog->db->execute('INSERT INTO index_generations(tenant_id,id,kb_id,profile_id,state,manifest_digest,manifest_json)
                VALUES (?,?,?,\'postgres-fts-simple-v1\',?,?,\'{}\'::jsonb)',[Fixture::id(1),Fixture::id($id),Fixture::id(100),$state,hash('sha256','generation-'.$id)]);
        }
        $db=$f->catalog->db;
        $db->execute('INSERT INTO publication_snapshots(tenant_id,id,kb_id,manifest_digest,created_at) VALUES (?,?,?,?,clock_timestamp())',
            [Fixture::id(1),Fixture::id(600),Fixture::id(100),hash('sha256','snapshot-600')]);
        $db->execute('INSERT INTO snapshot_generations VALUES (?,?,?,?,?)',[Fixture::id(1),Fixture::id(600),Fixture::id(100),Fixture::id(500),'postgres-fts-simple-v1']);
        $db->execute('UPDATE publication_snapshots SET sealed=true WHERE id=?',[Fixture::id(600)]);
        $db->execute('UPDATE knowledge_bases SET active_snapshot_id=? WHERE id=?',[Fixture::id(600),Fixture::id(100)]);
        return new D\GenerationId(Fixture::id(501));
    }
    public static function effect(Connection $db,PdoCatalogTransaction $tx,D\Scope $scope): \Closure
    {
        $payload=new JsonObject(['kbId'=>Fixture::id(100),'snapshotId'=>Fixture::id(601),
            'manifestDigest'=>hash('sha256','snapshot-601'),'pointerVersion'=>'1']);
        // Prepared before BEGIN, including schema/digest validation.
        $event=new D\DomainEvent(new JsonObject(['eventId'=>Fixture::id(800),'schemaVersion'=>'event-1.0','tenantId'=>Fixture::id(1),
            'aggregateType'=>'knowledge_base','aggregateId'=>Fixture::id(100),'aggregateVersion'=>'1','type'=>'snapshot.activated',
            'occurredAt'=>'2026-11-07T00:00:00Z','causationId'=>null,'correlationId'=>null,'payload'=>$payload,
            'payloadDigest'=>HashDomain::DomainEventPayload->digest($payload)]),PortFixture::validator());
        $outbox=new PdoOutbox($db,$tx);
        return static function(PdoCatalogTransaction $guard) use($db,$scope,$event,$outbox): void {
            $db->execute("UPDATE index_generations SET state='sealed' WHERE tenant_id=? AND id=?",[Fixture::id(1),Fixture::id(501)]);
            $db->execute('INSERT INTO publication_snapshots(tenant_id,id,kb_id,manifest_digest,created_at) VALUES (?,?,?,?,clock_timestamp())',
                [Fixture::id(1),Fixture::id(601),Fixture::id(100),hash('sha256','snapshot-601')]);
            $db->execute('INSERT INTO snapshot_generations VALUES (?,?,?,?,?)',[Fixture::id(1),Fixture::id(601),Fixture::id(100),Fixture::id(501),'postgres-fts-simple-v1']);
            $db->execute('UPDATE publication_snapshots SET sealed=true WHERE tenant_id=? AND id=?',[Fixture::id(1),Fixture::id(601)]);
            D\DomainFailure::requireWrite($guard->casPointer($scope->kbId,new D\SnapshotId(Fixture::id(601)),new D\Decimal('0')));
            $outbox->append($event);
        };
    }
}
