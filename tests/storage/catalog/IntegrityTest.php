<?php
declare(strict_types=1);
namespace KnowKit\Tests\Storage\Catalog;

use KnowKit\Domain as D;
use KnowKit\Adapter\Postgres\Catalog\{ChunkMetadata,ChunkSetMetadata,RevisionMetadata};
use KnowKit\Domain\Contract\JsonObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('storage:catalog')]
final class IntegrityTest extends TestCase
{
    private function seed(CatalogFixture $f): void
    {
        $role=$f->db->pdo->query('SELECT current_user')->fetchColumn(); $f->db->pdo->exec('RESET ROLE');
        try { $f->db->pdo->exec(file_get_contents(__DIR__.'/fixtures/v1/metadata.sql')); }
        finally { $f->db->pdo->exec('SET ROLE '.$role); }
    }
    public function testCrossTenantSqlReferencesAcrossEveryBaselineFamilyAreRejected(): void
    {
        CatalogFixture::run(function($f) {
            $this->seed($f); $p=$f->db->pdo; $t=CatalogFixture::id(1);
            $negatives=[
                "INSERT INTO document_revisions SELECT tenant_id,fixture_id(904),fixture_id(1003),parser_name,parser_version,profile_digest,ir_schema,ir_digest,ir_resource_id,coverage_state,coverage_json,created_at FROM document_revisions WHERE tenant_id=?",
                "INSERT INTO documents VALUES (?,fixture_id(905),fixture_id(1004),'foreign')",
                "INSERT INTO segments SELECT tenant_id,fixture_id(907),fixture_id(1005),'foreign',text_resource_id,text_digest,status,logical_order FROM segments WHERE tenant_id=?",
                "INSERT INTO pages SELECT tenant_id,fixture_id(906),fixture_id(1005),'foreign',1,NULL,text_resource_id,text_digest,status,NULL,NULL FROM pages WHERE tenant_id=?",
                "INSERT INTO assets SELECT tenant_id,fixture_id(909),fixture_id(1004),resource_id,media_type,source_locators_json,origin,NULL FROM assets WHERE tenant_id=?",
                "INSERT INTO chunk_sets SELECT tenant_id,fixture_id(910),fixture_id(1004),chunker_signature,manifest_digest FROM chunk_sets WHERE tenant_id=?",
                "INSERT INTO chunks SELECT tenant_id,fixture_id(911),fixture_id(1010),content_digest,document_id,spans_json,provenance_json,byte_length,structure_path,canonical_payload_ref FROM chunks WHERE tenant_id=?",
                "INSERT INTO index_generations(tenant_id,id,kb_id,profile_id,state) VALUES (?,fixture_id(914),fixture_id(1100),'postgres-fts-simple-v1','building')",
                "INSERT INTO index_memberships SELECT tenant_id,fixture_id(912),fixture_id(1014),chunk_id,chunk_digest,source_version_id,revision_id,chunk_set_id FROM index_memberships WHERE tenant_id=?",
                "INSERT INTO lexical_documents SELECT tenant_id,fixture_id(913),fixture_id(1014),chunk_id,source_id,source_version_id,simple_tsvector,exact_tokens,text_digest FROM lexical_documents WHERE tenant_id=?",
                "INSERT INTO publication_snapshots VALUES (?,fixture_id(9100),fixture_id(1100),repeat('a',64),now(),false)",
                "INSERT INTO snapshot_generations VALUES (?,fixture_id(1110),fixture_id(1100),fixture_id(1014),'postgres-fts-simple-v1')",
                "INSERT INTO jobs(tenant_id,id,source_id,source_version_id,kb_id,type,stage,state,available_at,source_epoch,idempotency_key,request_fingerprint) VALUES (?,fixture_id(918),fixture_id(2),fixture_id(1003),fixture_id(100),'extract','parse','pending',now(),0,'foreign-job',repeat('a',64))",
                "INSERT INTO job_checkpoints SELECT tenant_id,fixture_id(919),fixture_id(1018),stage,input_digest,output_digest,resource_refs_json,fence,created_at FROM job_checkpoints WHERE tenant_id=?",
                "INSERT INTO dependency_nodes VALUES (?,fixture_id(920),'source',fixture_id(1002),NULL,repeat('a',64),repeat('b',64),NULL)",
                "INSERT INTO dependency_edges VALUES (?,fixture_id(21),fixture_id(1020),'foreign')",
                "INSERT INTO resource_writers VALUES (?,fixture_id(930),fixture_id(1300),0,'active',NULL,now(),NULL)",
                "INSERT INTO usage_refs VALUES (?,fixture_id(931),fixture_id(1016),fixture_id(20),'{}')",
                "INSERT INTO usage_invalidations VALUES (?,fixture_id(933),fixture_id(16),fixture_id(1032),fixture_id(2),fixture_id(3))",
                "INSERT INTO lifecycle_events VALUES (?,fixture_id(932),fixture_id(1002),NULL,'source.revoked',1,repeat('a',64))",
                "INSERT INTO citations SELECT tenant_id,fixture_id(917),fixture_id(1016),host_scope_hash,source_id,source_version_id,revision_id,document_id,chunk_set_id,chunk_id,content_digest,source_digest,ir_digest,locator_json,digests_json,payload_state FROM citations WHERE tenant_id=?",
                "INSERT INTO deletion_tasks VALUES (?,fixture_id(922),fixture_id(1002),'pending','[]',0,repeat('a',64),NULL)",
                "INSERT INTO deletion_attempts(tenant_id,id,task_id,resource_id,in_flight) VALUES (?,fixture_id(923),fixture_id(22),fixture_id(1300),true)",
                "UPDATE source_versions SET source_id=fixture_id(1002) WHERE tenant_id=?",
                "UPDATE knowledge_bases SET active_snapshot_id=fixture_id(1110) WHERE tenant_id=?"
            ];
            foreach ($negatives as $sql) { CatalogFixture::sqlReject($p,$sql,[$t],['23503','23514']); self::assertTrue(true,$sql); }
            self::assertSame(25,count($negatives));
        });
    }
    public function testSealedMembershipsManifestAndHistoricalSnapshotCannotBeMutated(): void
    {
        CatalogFixture::run(function($f) {
            $this->seed($f); $p=$f->db->pdo; $s=$f->scope();
            $snapshot=$f->repo->resolveSnapshot($s->tenantId,$s->kbId,D\SnapshotSelector::active(),new D\ProfileId('postgres-fts-simple-v1'));
            self::assertSame(CatalogFixture::id(110),$snapshot->ref->snapshotId->value);
            self::assertSame(CatalogFixture::id(14),$snapshot->generationIds[0]->value);
            foreach (["UPDATE index_generations SET manifest_digest=repeat('b',64)",'DELETE FROM index_memberships',
                "UPDATE index_memberships SET chunk_digest=repeat('b',64)",'DELETE FROM snapshot_memberships',
                'DELETE FROM snapshot_generations',"UPDATE publication_snapshots SET manifest_digest=repeat('b',64)",
                'DELETE FROM lexical_documents'] as $sql) { CatalogFixture::sqlReject($p,$sql,[],['23514']); }
            $f->tx->run(function() use($f,$s) {
                $f->tx->lockTenant($s->tenantId,D\LockMode::Update); $f->tx->lockKb($s->kbId,D\LockMode::Update);
                self::assertTrue($f->tx->casPointer($s->kbId,new D\SnapshotId(CatalogFixture::id(110)),new D\Decimal('0')));
                self::assertFalse($f->tx->casPointer($s->kbId,new D\SnapshotId(CatalogFixture::id(110)),new D\Decimal('0')));
            });
            $p->exec('UPDATE knowledge_bases SET active_snapshot_id=NULL WHERE tenant_id=fixture_id(1)');
            self::assertNull($f->repo->resolveSnapshot($s->tenantId,$s->kbId,D\SnapshotSelector::active(),new D\ProfileId('postgres-fts-simple-v1')));
            self::assertEquals($snapshot,$f->repo->resolveSnapshot($s->tenantId,$s->kbId,D\SnapshotSelector::exact($snapshot->ref),new D\ProfileId('postgres-fts-simple-v1')));
            try { $f->repo->resolveSnapshot($s->tenantId,$s->kbId,D\SnapshotSelector::exact($snapshot->ref),new D\ProfileId('vector-v1')); self::fail('Profile fallback'); }
            catch (D\DomainFailure $e) { self::assertSame('profile_incompatible',$e->errorCode); }
        });
    }
    public function testJobsDisclosuresMetadataAndUnknownDeletionObligationsHaveNegativeConstraints(): void
    {
        CatalogFixture::run(function($f) {
            $this->seed($f); $p=$f->db->pdo; $t=CatalogFixture::id(1);
            CatalogFixture::sqlReject($p,"UPDATE jobs SET state='leased',attempt_count=1 WHERE tenant_id=?",[$t],['23514']);
            CatalogFixture::sqlReject($p,"UPDATE jobs SET lease_token_hash='raw-lease-token' WHERE tenant_id=?",[$t],['23514']);
            CatalogFixture::sqlReject($p,"INSERT INTO evidence_usages SELECT tenant_id,fixture_id(916),disclosure_attempt_id,kb_id,actor_id,purpose,run_ref,selection_id,host_scope_hash,policy_binding_digest,prepared_at,provider_accepted_at,evidence_digest,payload_resource_id FROM evidence_usages WHERE tenant_id=?",[$t],['23505']);
            CatalogFixture::sqlReject($p,"UPDATE deletion_tasks SET status='done' WHERE tenant_id=?",[$t],['23514']);
            CatalogFixture::sqlReject($p,"UPDATE collections SET metadata='{\"bad\":null}' WHERE tenant_id=?",[$t],['23514']);
            CatalogFixture::sqlReject($p,"UPDATE collections SET metadata='{\"test.label\":null}' WHERE tenant_id=?",[$t],['23514']);
            CatalogFixture::sqlReject($p,'INSERT INTO collection_closure VALUES (?,fixture_id(100),fixture_id(200),fixture_id(200),0)',[$t],['23514']);
            CatalogFixture::sqlReject($p,"UPDATE citations SET source_digest=repeat('b',64) WHERE tenant_id=?",[$t],['23503','23514']);
            self::assertSame(2,$f->count('jobs')); self::assertSame(2,$f->count('evidence_usages'));
        });
    }
    public function testExactSpanValidationAndCompanionOnlyMutationHaveNegativeExamples(): void
    {
        CatalogFixture::run(function($f) {
            $this->seed($f); $p=$f->db->pdo; $t=CatalogFixture::id(1);
            foreach (['segmentId'=>'foreign-segment','blockId'=>'foreign-block','textDigest'=>str_repeat('a',64),'byteEnd'=>16] as $field=>$value) {
                $span=json_decode($p->query('SELECT spans_json FROM chunks LIMIT 1')->fetchColumn(),true);
                $span[0][$field]=$value;
                CatalogFixture::sqlReject($p,'INSERT INTO chunks SELECT tenant_id,fixture_id(911),chunk_set_id,content_digest,document_id,?::jsonb,provenance_json,byte_length,structure_path,canonical_payload_ref FROM chunks WHERE tenant_id=?',
                    [json_encode($span,JSON_THROW_ON_ERROR),$t],['23514']);
            }
            foreach (["UPDATE evidence_usages SET evidence_digest=repeat('f',64)","UPDATE citations SET locator_json='[]'",'DELETE FROM resource_ledger',
                "UPDATE dependency_nodes SET identity_digest=repeat('f',64)","UPDATE jobs SET source_version_id=fixture_id(1003)",
                "UPDATE resource_writers SET state='active',closed_at=NULL"] as $sql) {
                CatalogFixture::sqlReject($p,$sql,[],['23514']);
            }
            $p->exec('UPDATE evidence_usages SET provider_accepted_at=prepared_at');
            CatalogFixture::sqlReject($p,'UPDATE evidence_usages SET provider_accepted_at=NULL',[],['23514']);
            $p->exec("UPDATE citations SET payload_state='removed'");
            CatalogFixture::sqlReject($p,"UPDATE citations SET payload_state='present'",[],['23514']);
            self::assertSame(2,$f->count('citations'));
        });
    }
    public function testLateInsertPrimitivesRequireRealMetadataAndPreserveExactReferences(): void
    {
        CatalogFixture::run(function($f) {
            $this->seed($f); $s=$f->scope();
            $source=new D\SourceVersionRef($s->tenantId,new D\SourceId(CatalogFixture::id(2)),new D\SourceVersionId(CatalogFixture::id(3)),new D\SourceDigest(str_repeat('a',64)));
            $signature=new D\ParserSignature('test','1',new D\Fingerprint(str_repeat('a',64)));
            $coverage=new D\Coverage(new JsonObject(['state'=>'unknown','counts'=>new JsonObject(['processed'=>0,'empty'=>0,'failed'=>0,'skipped'=>0]),'missingFeatures'=>[],'diagnostics'=>[]]));
            try { new RevisionMetadata(new D\ResourceId(CatalogFixture::id(301)),'ir-2.0',$coverage,$f->clock->now()); self::fail('Unsupported IR major'); }
            catch (D\DomainFailure $e) { self::assertSame('unsupported_schema_version',$e->errorCode); }
            $revision=new D\DocumentRevisionRef($source,new D\DocumentRevisionId(CatalogFixture::id(4)),new D\IrDigest(str_repeat('b',64)),$signature);
            $chunkId=new D\ChunkId(CatalogFixture::id(41)); $setId=new D\ChunkSetId(CatalogFixture::id(40));
            $digest=new D\Sha256(hash('sha256','synthetic bytes')); $f->resource(340,2,1,'owned',$digest->value);
            $chunk=new D\Chunk(new D\ChunkRef($revision,$setId,$chunkId,$digest),new D\DocumentId(CatalogFixture::id(5)),
                [new D\Span(D\TextLocator::segment(new D\LocalId('segment-1'),new D\LocalId('block-1'),0,15),new D\Sha256(str_repeat('c',64)))],'synthetic bytes');
            $set=new D\ChunkSet($revision,$setId,$signature,[$chunk]);
            try {
                $f->tx->run(function() use($f,$s,$set) { $f->tx->lockTenant($s->tenantId,D\LockMode::Update); $f->repo->insertChunkSet($s->tenantId,$set); }); self::fail('Invented missing chunk metadata');
            } catch (D\DomainFailure $e) { self::assertSame('chunk_metadata_required',$e->errorCode); }
            $f->tx->run(function() use($f,$s,$set,$chunkId) {
                $f->tx->lockTenant($s->tenantId,D\LockMode::Update);
                $f->repo->insertChunkSet($s->tenantId,$set,new ChunkSetMetadata(new D\Fingerprint(str_repeat('f',64)),[
                    $chunkId->value=>new ChunkMetadata(new D\ResourceId(CatalogFixture::id(340)),new JsonObject(['kind'=>'verbatim']),[])]));
                $f->repo->insertGeneration($s->tenantId,new D\Generation($s->tenantId,$s->kbId,new D\GenerationId(CatalogFixture::id(50)),'building',null),new D\ProfileId('postgres-fts-simple-v1'));
                $snapshot=new D\PublicationSnapshot(new D\SnapshotRef($s->tenantId,$s->kbId,new D\SnapshotId(CatalogFixture::id(120)),new D\Fingerprint(str_repeat('b',64))),[new D\GenerationId(CatalogFixture::id(14))]);
                $f->repo->insertSnapshot($s->tenantId,$snapshot);
            });
            self::assertSame(3,$f->count('chunks')); self::assertSame(3,$f->count('snapshot_generations'));
            self::assertNull($f->repo->resolveSnapshot($s->tenantId,$s->kbId,D\SnapshotSelector::exact(new D\SnapshotRef($s->tenantId,$s->kbId,new D\SnapshotId(CatalogFixture::id(120)),new D\Fingerprint(str_repeat('b',64)))),new D\ProfileId('postgres-fts-simple-v1')));
        });
    }
}
