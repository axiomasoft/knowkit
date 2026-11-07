<?php
declare(strict_types=1);

namespace KnowKit\Tests\Storage\Catalog;

use KnowKit\Adapter\Postgres\Catalog\{Connection,RevisionMetadata};
use KnowKit\Application\Catalog\{OriginBoundary,RemoveKnowledgeBase};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain,JsonObject,Jcs};
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

#[Group('storage:catalog')]
final class CatalogTest extends TestCase
{
    private function rejects(callable $operation,string $code,D\Outcome $outcome=D\Outcome::Incompatible): void
    {
        try { $operation(); self::fail('Forbidden command accepted: '.$code); }
        catch (D\DomainFailure $e) { self::assertSame($code,$e->errorCode); self::assertSame($outcome,$e->outcome); }
    }
    public function testSameLocalIdsStayIsolatedAcrossTenants(): void
    {
        CatalogFixture::run(function($f) {
            foreach ([1,9] as $tenant) { $f->tenant($tenant); $f->kb($tenant); $f->source(2,$tenant); }
            foreach ([1,9] as $tenant) {
                $scope=$f->scope($tenant); $f->commands->addMembership($scope,new D\AddMembership(new D\SourceId(CatalogFixture::id(2)),null,new D\CatalogMetadata()),$f->context('membership','0','0'));
                $page=new D\PageCursor($scope->tenantId,$scope->kbId,new D\CollectionFilter(),$scope->hostScopeHash);
                $sources=$f->repo->listSources($scope->tenantId,$scope->kbId,$page->filter,$page)->sources;
                self::assertCount(1,$sources); self::assertTrue($scope->tenantId->equals($sources[0]->tenantId));
                self::assertCount(1,$f->repo->listMemberships($scope->tenantId,$sources[0]->sourceId));
            }
            self::assertNull($f->repo->getSource(new D\TenantId(CatalogFixture::id(3)),new D\SourceId(CatalogFixture::id(2))));
            self::assertSame(2,$f->count('knowledge_bases')); self::assertSame(2,$f->count('source_memberships'));
            self::assertSame('isolated',CatalogFixture::fixture()['outcomes']['sameLocalIds']);
        });
    }
    public function testIdempotencyReplaysOriginalResponseAndRejectsChangedPayloadOperationAndExpectedEpoch(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $s=$f->scope(); $kb=$f->kb();
            $command=new D\CreateKB($s->kbId,$kb->name,$kb->policyRef,$kb->defaultRetrievalProfile,$kb->coveragePolicy);
            self::assertEquals($kb,$f->commands->createKnowledgeBase($s,$command,$f->context()));
            self::assertSame(1,$f->count('idempotency_receipts'));
            $changed=new D\CreateKB($s->kbId,'changed',$kb->policyRef,$kb->defaultRetrievalProfile,$kb->coveragePolicy);
            $this->rejects(fn()=>$f->commands->createKnowledgeBase($s,$changed,$f->context()),'idempotency_conflict');
            $this->rejects(fn()=>$f->commands->createKnowledgeBase($s,$command,$f->context('create-kb',null,'1')),'idempotency_conflict');
            $collection=new D\CreateCollection(new D\CollectionId(CatalogFixture::id(200)),null,new D\CatalogMetadata());
            $this->rejects(fn()=>$f->commands->createCollection($s,$collection,$f->context()),'idempotency_conflict');
            $accepted=$f->commands->createCollection($s,$collection,$f->context('collection','0'));
            self::assertSame('1',$f->repo->getKb($s->tenantId,$s->kbId)->revision->value);
            $s=$f->scope();
            self::assertEquals($accepted,$f->commands->createCollection($s,$collection,$f->context('collection','0','0')));
            self::assertSame(1,$f->count('collections')); self::assertSame(2,$f->count('idempotency_receipts'));
            self::assertSame('original_response',CatalogFixture::fixture()['outcomes']['samePayloadReplay']);
        });
    }
    public function testStaleRevisionAndEpochDoNotLeaveSuccessReceipts(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $scope=$f->scope();
            $command=new D\CreateCollection(new D\CollectionId(CatalogFixture::id(200)),null,new D\CatalogMetadata());
            $this->rejects(fn()=>$f->commands->createCollection($scope,$command,$f->context('stale','7')),'stale_write');
            $this->rejects(fn()=>$f->commands->createCollection($scope,$command,$f->context('epoch','0','1')),'POLICY_CHANGED',D\Outcome::Denied);
            self::assertSame(0,$f->count('collections')); self::assertSame(1,$f->count('idempotency_receipts'));
            $f->authority->prepare($scope,new D\ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId),D\Operation::CollectionCreate);
            try {
                $f->tx->run(function() use($f,$scope,$command) {
                    $f->tx->lockTenant($scope->tenantId,D\LockMode::Update); $f->tx->lockKb($scope->kbId,D\LockMode::Update);
                    $f->repo->createCollection($scope,$command,$f->context('rollback','0')); throw new \RuntimeException('test rollback');
                }); self::fail('Rollback did not throw');
            } catch (\RuntimeException $e) { self::assertSame('test rollback',$e->getMessage()); }
            self::assertSame(0,$f->count('collections')); self::assertSame(1,$f->count('idempotency_receipts'));
            self::assertSame('0',$f->repo->getKb($scope->tenantId,$scope->kbId)->revision->value);
        });
    }
    public function testApplicationRejectsForeignMembershipAndIncompleteParentChain(): void
    {
        CatalogFixture::run(function($f) {
            foreach ([1,9] as $tenant) { $f->tenant($tenant); $f->kb($tenant); } $f->source(8,9);
            $scope=$f->scope(); $foreign=new D\AddMembership(new D\SourceId(CatalogFixture::id(8)),null,new D\CatalogMetadata());
            $this->rejects(fn()=>$f->commands->addMembership($scope,$foreign,$f->context('foreign','0')),'resource_missing');
            $child=new D\CreateCollection(new D\CollectionId(CatalogFixture::id(200)),new D\CollectionId(CatalogFixture::id(201)),new D\CatalogMetadata());
            $this->rejects(fn()=>$f->commands->createCollection($scope,$child,$f->context('parent','0')),'collection_parent_missing');
            $this->rejects(fn()=>new D\CreateCollection($child->collectionId,$child->collectionId,new D\CatalogMetadata()),'collection_cycle');
            self::assertSame(0,$f->count('source_memberships')); self::assertSame(0,$f->count('collections'));
        });
    }
    public function testCurrentExternalOwnerDenyAndUnknownOverrideStaleLocalAllowInEveryMembership(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $source=$f->source(); $s=$f->scope();
            foreach ([200,201] as $n=>$id) {
                $f->commands->createCollection($f->scope(),new D\CreateCollection(new D\CollectionId(CatalogFixture::id($id)),null,new D\CatalogMetadata()),$f->context('c'.$id,(string)$n));
            }
            foreach ([200,201] as $n=>$id) {
                $f->commands->addMembership($f->scope(),new D\AddMembership($source->sourceId,new D\CollectionId(CatalogFixture::id($id)),new D\CatalogMetadata()),$f->context('m'.$id,(string)($n+2)));
            }
            self::assertCount(2,$f->repo->listMemberships($s->tenantId,$source->sourceId));
            $s=$f->scope(); $resource=new D\ResourceRef($s->tenantId,'source',$source->sourceId);
            $binding=new JsonObject(['tenantPolicyEpoch'=>'0','kbPolicyEpoch'=>$s->policyEpoch->value,'sources'=>[new JsonObject(['sourceId'=>$source->sourceId->value,'sourcePolicyEpoch'=>'0','lifecycleEpoch'=>'0'])]]);
            foreach ([D\Decision::Deny,D\Decision::Unknown] as $decision) {
                $f->owner->decision=D\Decision::Allow;
                $f->authority->prepare($s,$resource,D\Operation::Disclose);
                $f->owner->decision=$decision;
                foreach ([200,201] as $id) {
                    // This shared authorization boundary makes no plaintext available on a stale projection.
                    $f->authority->prepare($s,$resource,D\Operation::Disclose);
                    $this->rejects(fn()=>$f->authority->requireAllow($s,$resource,D\Operation::Disclose,$binding),
                        $decision===D\Decision::Deny?'policy_denied':'policy_unknown',$decision===D\Decision::Deny?D\Outcome::Denied:D\Outcome::Unknown);
                    $this->rejects(fn()=>$f->commands->addMembership($s,new D\AddMembership($source->sourceId,new D\CollectionId(CatalogFixture::id($id)),new D\CatalogMetadata()),$f->context('m'.$id,(string)($id-200+2))),
                        $decision===D\Decision::Deny?'policy_denied':'policy_unknown',$decision===D\Decision::Deny?D\Outcome::Denied:D\Outcome::Unknown);
                }
            }
            self::assertTrue($f->owner->staleLocalAllow); self::assertCount(2,$f->repo->listMemberships($s->tenantId,$source->sourceId));
        });
    }
    public function testExactVersionHistoryIsImmutableAndOutboxSharesRollback(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $source=$f->source(); $f->resource(); $s=$f->scope();
            $ref=new D\SourceVersionRef($s->tenantId,$source->sourceId,new D\SourceVersionId(CatalogFixture::id(3)),new D\SourceDigest(hash('sha256','synthetic bytes')));
            $version=new D\SourceVersion($ref,new D\BlobRef($s->tenantId,$source->sourceId,$ref->sourceDigest,new D\Uint64('15'),'test','object-300','owned'),'text/plain','owner:rights',$f->clock->now());
            $write=function() use($f,$s,$version) { $f->tx->lockTenant($s->tenantId,D\LockMode::Update); $f->tx->lockSources([$version->ref->sourceId],D\LockMode::Update); $f->repo->insertSourceVersion($s->tenantId,$version); };
            try { $f->tx->run(function() use($write) { $write(); throw new \RuntimeException('abort'); }); } catch (\RuntimeException $e) { self::assertSame('abort',$e->getMessage()); }
            self::assertSame(0,$f->count('source_versions')); self::assertSame(1,$f->count('outbox'));
            $f->tx->run($write); $f->tx->run($write);
            self::assertEquals($ref,$f->repo->getVersion($s->tenantId,$ref)->ref); self::assertSame(2,$f->count('outbox'));
            $bad=new D\SourceVersionRef($s->tenantId,$source->sourceId,$ref->sourceVersionId,new D\SourceDigest(str_repeat('f',64)));
            $this->rejects(fn()=>$f->repo->getVersion($s->tenantId,$bad),'immutable_conflict');
            $this->rejects(fn()=>$f->repo->getVersion(new D\TenantId(CatalogFixture::id(9)),$ref),'scope_mismatch',D\Outcome::Denied);
            CatalogFixture::sqlReject($f->db->pdo,"UPDATE source_versions SET rights_ref='new'",[],['23514']);
            CatalogFixture::sqlReject($f->db->pdo,'DELETE FROM source_versions',[],['23514']);
            $f->resource(301,2,1,'owned',str_repeat('b',64));
            $rev=new D\DocumentRevisionRef($ref,new D\DocumentRevisionId(CatalogFixture::id(4)),new D\IrDigest(str_repeat('b',64)),new D\ParserSignature('test','1',new D\Fingerprint(str_repeat('c',64))));
            $coverage=new D\Coverage(new JsonObject(['state'=>'unknown','counts'=>new JsonObject(['processed'=>0,'empty'=>0,'failed'=>0,'skipped'=>0]),'missingFeatures'=>[],'diagnostics'=>[]]));
            $f->tx->run(function() use($f,$s,$rev,$coverage) {
                $f->tx->lockTenant($s->tenantId,D\LockMode::Update); $f->tx->lockSources([$rev->sourceRef->sourceId],D\LockMode::Update);
                $f->repo->insertDocumentRevision($s->tenantId,$rev,new RevisionMetadata(new D\ResourceId(CatalogFixture::id(301)),'ir-1.0',$coverage,$f->clock->now()));
            });
            self::assertSame(3,$f->count('outbox')); self::assertSame(1,$f->count('document_revisions'));
            self::assertSame(['document_revision.accepted','source.registered','source_version.accepted'],
                $f->db->execute("SELECT event_json->>'type' FROM outbox ORDER BY event_json->>'type'")->fetchAll(\PDO::FETCH_COLUMN));
        });
    }
    public function testOutboxRejectsSamePayloadUnderConflictingEnvelopeAndRequiresTransaction(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $s=$f->scope();
            $payload=new JsonObject(['sourceId'=>CatalogFixture::id(2),'lifecycleState'=>'active','lifecycleEpoch'=>'0','sourcePolicyEpoch'=>'0','retentionHold'=>false]);
            $event=$f->events->create($s->tenantId,'source',new D\SourceId(CatalogFixture::id(2)),new D\Decimal('0'),'source.registered',$payload);
            $this->rejects(fn()=>$f->outbox->append($event),'scope_mismatch',D\Outcome::Denied);
            $f->tx->run(function() use($f,$s,$event) { $f->tx->lockTenant($s->tenantId,D\LockMode::Update); $f->outbox->append($event); $f->outbox->append($event); });
            $conflict=new D\DomainEvent($event->toWire()->with('aggregateVersion','1'),PortFixture::validator());
            $this->rejects(fn()=>$f->tx->run(function() use($f,$s,$conflict) { $f->tx->lockTenant($s->tenantId,D\LockMode::Update); $f->outbox->append($conflict); }),'immutable_conflict');
            self::assertSame(1,$f->count('outbox'));
            CatalogFixture::sqlReject($f->db->pdo,"UPDATE outbox SET event_json=jsonb_set(event_json,'{aggregateVersion}','\"1\"')",[],['23514']);
        });
    }
    public function testPaginationFiltersBeforeLimitAndAuthenticatesContinuationScope(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $s=$f->scope();
            $f->commands->createCollection($s,new D\CreateCollection(new D\CollectionId(CatalogFixture::id(200)),null,new D\CatalogMetadata()),$f->context('collection','0'));
            foreach ([2,3,4] as $i=>$id) {
                $source=$f->source($id); $f->commands->addMembership($f->scope(),new D\AddMembership($source->sourceId,$id===2?null:new D\CollectionId(CatalogFixture::id(200)),new D\CatalogMetadata()),$f->context('member'.$id,(string)($i+1)));
            }
            $filter=new D\CollectionFilter(new D\CollectionId(CatalogFixture::id(200))); $cursor=new D\PageCursor($s->tenantId,$s->kbId,$filter,$s->hostScopeHash,null,1);
            $first=$f->repo->listSources($s->tenantId,$s->kbId,$filter,$cursor); self::assertSame(CatalogFixture::id(3),$first->sources[0]->sourceId->value);
            $second=$f->repo->listSources($s->tenantId,$s->kbId,$filter,$first->next); self::assertSame(CatalogFixture::id(4),$second->sources[0]->sourceId->value); self::assertNull($second->next);
            foreach ([new D\Sha256(str_repeat('b',64)),$s->hostScopeHash] as $digest) {
                $tampered=new D\PageCursor($s->tenantId,$s->kbId,$digest===$s->hostScopeHash?new D\CollectionFilter():$filter,$digest,$first->next->token,1);
                $this->rejects(fn()=>$f->repo->listSources($s->tenantId,$s->kbId,$tampered->filter,$tampered),'scope_mismatch',D\Outcome::Denied);
            }
        });
    }
    public function testRemovingKnowledgeBaseCreatesOnlyOwnedDeletionIntentsAndPreservesAuditRows(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $s=$f->scope();
            foreach ([2,3] as $i=>$id) {
                $source=$f->source($id); $f->resource(300+$i,$id,1,$id===2?'owned':'external');
                $f->commands->addMembership($f->scope(),new D\AddMembership($source->sourceId,null,new D\CatalogMetadata()),$f->context('member'.$id,(string)$i));
            }
            $remove=new RemoveKnowledgeBase($f->repo,$f->repo->removeKnowledgeBase(...),$f->tx,$f->authority);
            $tasks=$remove->remove($f->scope(),$f->context('remove','2','2')); self::assertCount(1,$tasks);
            self::assertSame($tasks,$remove->remove($f->scope(),$f->context('remove','2','2')));
            self::assertSame(0,$f->count('source_memberships')); self::assertSame(2,$f->count('resource_ledger')); self::assertSame(1,$f->count('knowledge_bases'));
            $required=json_decode($f->db->execute('SELECT required_resources_json FROM deletion_tasks')->fetchColumn(),true);
            self::assertSame(CatalogFixture::id(300),$required[0]['resourceId']);
            self::assertSame('accepted',$f->db->execute('SELECT state FROM resource_ledger WHERE owner_kind=\'external\'')->fetchColumn());
            self::assertSame('detach_only',CatalogFixture::fixture()['outcomes']['externalResourceRemoval']);
        });
    }
    public function testPrivateRedirectTraversalAndRawSqlFilterAreRejectedBeforePrivilegedWork(): void
    {
        self::assertSame('https://example.org/doc',OriginBoundary::https('https://example.org/doc',['93.184.216.34']));
        self::assertSame('object-123',OriginBoundary::storageKey('object-123'));
        $privilegedCalls=0;
        foreach ([['https://127.0.0.1/internal',['127.0.0.1']],['https://example.org/redirect',['10.0.0.1']],
            ['https://[::ffff:127.0.0.1]/',['::ffff:127.0.0.1']],['file:///etc/passwd',[]],['https://u:p@example.org/',['93.184.216.34']],
            ['https://example.org/',['93.184.216.34','192.168.1.1']]] as [$url,$ips]) {
            $this->rejects(function() use($url,$ips,&$privilegedCalls) { OriginBoundary::https($url,$ips); $privilegedCalls++; },'unsafe_origin',D\Outcome::Denied);
        }
        foreach (['../source','/etc/passwd','a\\b','%2e%2e%2fsecret','x/../../y'] as $path) {
            $this->rejects(function() use($path,&$privilegedCalls) { OriginBoundary::storageKey($path); $privilegedCalls++; },'unsafe_path',D\Outcome::Denied);
        }
        try { new D\CollectionFilter('1 OR 1=1'); $privilegedCalls++; self::fail('Raw SQL filter accepted'); } catch (\TypeError) {}
        self::assertSame(0,$privilegedCalls);
    }
    public function testMembershipIntersectsKnowledgeBaseCollectionAndSourcePermissions(): void
    {
        CatalogFixture::run(function($f) {
            $f->tenant(); $f->kb(); $source=$f->source(); $s=$f->scope(); $collection=new D\CollectionId(CatalogFixture::id(200));
            $f->commands->createCollection($s,new D\CreateCollection($collection,null,new D\CatalogMetadata()),$f->context('collection','0'));
            $s=$f->scope();
            foreach (['knowledge_base:'.$s->kbId->value,'collection:'.$collection->value,'source:'.$source->sourceId->value] as $key) {
                $f->owner->decisions=[$key=>D\Decision::Deny];
                $this->rejects(fn()=>$f->commands->addMembership($s,new D\AddMembership($source->sourceId,$collection,new D\CatalogMetadata()),$f->context('membership','1')),'policy_denied',D\Outcome::Denied);
                self::assertSame(0,$f->count('source_memberships'));
            }
            $f->owner->decisions=[];
            $f->commands->addMembership($s,new D\AddMembership($source->sourceId,$collection,new D\CatalogMetadata()),$f->context('membership','1'));
            self::assertSame(1,$f->count('source_memberships'));
        });
    }
}
