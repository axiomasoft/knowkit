<?php
declare(strict_types=1);
namespace KnowKit\Tests\Lifecycle\Restore;

use KnowKit\Adapter\Postgres\Reconciliation\{PdoReconciliationStore,ProjectionRebuilder};
use KnowKit\Application\Reconciliation\Reconcile;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs,JsonObject};
use KnowKit\Port\RecoveryEvidence;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Publication\Manifest\Fixture as Publication;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('lifecycle:restore')]
final class ProjectionTest extends TestCase
{
    public function testVerifiedManifestRebuildsLostProjectionWithoutChangingHistoricalIdentities(): void
    {
        Publication::run(function(Publication $f): void {
            $candidate=$f->candidate(); $staging=$f->store(); $staging->stage($f->lease,$candidate); $f->index($candidate); $staging->seal($f->lease,$candidate); $f->snapshot(501,601,true);
            $c=$f->jobs->catalog; $tenant=$f->jobs->scope->tenantId;
            $c->db->execute("INSERT INTO index_generations(tenant_id,id,kb_id,profile_id,state) VALUES (?,?,?,'postgres-fts-simple-v1','failed')",
                [$tenant->value,Fixture::id(502),Fixture::id(100)]);
            $manifestBefore=$c->db->row('SELECT manifest_digest,manifest_json FROM index_generations');
            $membersBefore=$c->db->execute('SELECT * FROM index_memberships ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
            // Fault injection models a backup without the derived projection; production code never disables a guard.
            $role=$c->db->execute('SELECT current_user')->fetchColumn();
            $c->db->pdo->exec('RESET ROLE'); $c->db->pdo->exec('ALTER TABLE lexical_documents DISABLE TRIGGER USER');
            $c->db->pdo->exec('DELETE FROM lexical_documents'); $c->db->pdo->exec('ALTER TABLE lexical_documents ENABLE TRIGGER USER'); $c->db->pdo->exec('SET ROLE '.$role);
            $store=new PdoReconciliationStore($c->db,$c->tx,$tenant,$c->ids); $seed=new D\Sha256(D\RecoveryDigest::seed());
            $dummy=new D\RecoveryPlan($tenant,new D\Ulid(Fixture::id(90000)),new D\Decimal('0'),new D\Decimal('0'),$seed,$seed,
                array_fill_keys(['objects','ledger','manifests','outbox'],Fixture::inventory([])));
            $p=new D\RecoveryPlan($tenant,$dummy->id,$dummy->backupSequence,$dummy->cutoff,$seed,$seed,
                ['objects'=>Fixture::inventory([]),'ledger'=>Fixture::inventory($store->scan($dummy,'ledger',null,100)),
                 'manifests'=>Fixture::inventory($store->scan($dummy,'manifests',null,100)),
                 'outbox'=>Fixture::inventory($store->scan($dummy,'outbox',null,100))]);
            $rebuilder=new ProjectionRebuilder($c->db,$c->tx,$tenant,PortFixture::validator(),$c->ids,
                function() use($f,$c): array { self::assertFalse($c->db->pdo->inTransaction()); return [$f->set]; });
            $io=new class($f,$rebuilder) implements RecoveryEvidence {
                public function __construct(private Publication $f,private ProjectionRebuilder $rebuilder) {}
                public function journal(D\RecoveryPlan $p,D\Decimal $a,int $n): array { return []; }
                public function objects(D\RecoveryPlan $p,?string $a,int $n): array { return []; } // Fixture storage contract, not provider qualification.
                public function resource(D\RecoveryPlan $p,JsonObject $r): string {
                    $bytes=file_get_contents($this->f->jobs->directory.'/resource-'.$r->get('id'));
                    return hash('sha256',$bytes)===$r->get('digest') && (string)strlen($bytes)===$r->get('byte_length') ? 'verified' : 'incompatible';
                }
                public function rebuild(D\RecoveryPlan $p,JsonObject $r): bool { return $this->rebuilder->rebuild($p,$r); }
                public function deniedCanary(D\RecoveryPlan $p): bool {
                    // Real disclosure/hold canaries are exercised by RestoreTest with LocalBlobStore.
                    return $this->f->jobs->catalog->db->execute('SELECT serving_state FROM tenants')->fetchColumn()==='blocked';
                }
            };
            $runner=new Reconcile($store,$io); $runner->start($p);
            while ($store->checkpoint($p)['stage']!=='manifests') { $runner->step($p,1); }
            CatalogFixture::sqlReject($c->db->pdo,"INSERT INTO lexical_documents (tenant_id,id,generation_id,chunk_id,source_id,source_version_id,simple_tsvector,exact_tokens,text_digest)
                SELECT tenant_id,?,generation_id,?, ?,source_version_id,''::tsvector,'{}'::text[],chunk_digest FROM index_memberships LIMIT 1",
                [Fixture::id(99900),$f->set->chunkSet->chunks[0]->ref->chunkId->value,Fixture::id(2)]);
            $row=$store->scan($p,'manifests',null,1)[0];
            $aborted=$store->scan($p,'manifests',Fixture::id(501),1)[0];
            self::assertTrue($rebuilder->rebuild($p,$aborted));
            Fixture::failure(fn()=>$rebuilder->rebuild($p,$aborted->with('state','building')),'recovery_manifest_missing');
            Fixture::failure(fn()=>$rebuilder->rebuild($p,$row->with('manifest_digest',str_repeat('0',64))),'manifest_mismatch',D\Outcome::Incompatible);
            Fixture::expected('MANIFEST-TAMPER',['serving'=>$c->db->execute('SELECT serving_state FROM tenants')->fetchColumn(),'outcome'=>'incompatible']);
            self::assertSame(0,$c->count('lexical_documents'));
            $runner->step($p,1); // Verified projection is durable before the page checkpoint.
            self::assertSame(2,$c->count('lexical_documents')); $f->backend->reload($candidate->manifest);
            // Re-running the same rebuild after a kill is safe and does not change immutable identities.
            self::assertTrue($rebuilder->rebuild($p,$row));
            while ($store->checkpoint($p)['stage']!=='ready') { $runner->step($p,1); }
            $manifestAfter=$c->db->row('SELECT manifest_digest,manifest_json FROM index_generations');
            $membersAfter=$c->db->execute('SELECT * FROM index_memberships ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
            self::assertSame($manifestBefore,$manifestAfter); self::assertSame($membersBefore,$membersAfter);
            Fixture::expected('PROJECTION-REBUILD',['serving'=>$c->db->execute('SELECT serving_state FROM tenants')->fetchColumn(),
                'sameManifest'=>$manifestBefore===$manifestAfter,'sameMemberships'=>$membersBefore===$membersAfter,'readback'=>'verified']);
            CatalogFixture::sqlReject($c->db->pdo,'DELETE FROM lexical_documents');
            // A later restore cannot erase an earlier uncertain job observation.
            $c->db->execute('UPDATE jobs SET uncertain_external_effect=true');
            $retry=new D\RecoveryPlan($p->tenantId,new D\Ulid(Fixture::id(90001)),$p->backupSequence,$p->cutoff,$p->backupJournalDigest,$p->journalDigest,$p->inventories);
            $runner->start($retry);
            while ($store->checkpoint($retry)['stage']!=='canary') { $runner->step($retry,1); }
            Fixture::failure(fn()=>$runner->step($retry,1),'recovery_writer_uncertain');
            self::assertSame('blocked',$c->db->execute('SELECT serving_state FROM tenants')->fetchColumn());
        });
    }
}
