<?php
declare(strict_types=1);

namespace KnowKit\Tests\Security\Policy;

use KnowKit\Adapter\Postgres\PolicyEpoch\PdoPolicyEpoch;
use KnowKit\Application\Access as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('security:policy')]
final class EpochTest extends TestCase
{
    private function rejects(callable $call, D\Outcome $outcome, string $code): void
    {
        try { $call(); self::fail('Invalid binding accepted'); }
        catch (D\DomainFailure $e) { self::assertSame($outcome,$e->outcome); self::assertSame($code,$e->errorCode); }
    }
    private function seed(CatalogFixture $f): D\SourceVersionRef
    {
        $f->tenant(); $f->kb(); $source=$f->source(); $f->resource(); $scope=$f->scope();
        $ref=new D\SourceVersionRef($scope->tenantId,$source->sourceId,new D\SourceVersionId(PortFixture::id(3)),new D\SourceDigest(hash('sha256','synthetic bytes')));
        $version=new D\SourceVersion($ref,new D\BlobRef($scope->tenantId,$source->sourceId,$ref->sourceDigest,new D\Uint64('15'),'test','object-300','owned'),'text/plain','owner:rights',$f->clock->now());
        $f->tx->run(function() use ($f,$scope,$version) {
            $f->tx->lockTenant($scope->tenantId,D\LockMode::Update); $f->tx->lockSources([$version->ref->sourceId],D\LockMode::Update);
            $f->repo->insertSourceVersion($scope->tenantId,$version);
        });
        $f->commands->addMembership($scope,new D\AddMembership($source->sourceId,null,new D\CatalogMetadata()),$f->context('membership','0','0'));
        return $ref;
    }
    private function scope(CatalogFixture $f): D\Scope
    {
        $s=$f->scope(); $kb=$f->repo->getKb($s->tenantId,$s->kbId);
        return new D\Scope($s->tenantId,$s->kbId,$s->actorId,'lesson.read',$kb->policyEpoch,$s->hostScopeHash);
    }
    private function clock(CatalogFixture $f): P\Clock
    {
        return new class($f) implements P\Clock {
            public function __construct(private CatalogFixture $f) {}
            public function now(): D\UtcInstant
            {
                return new D\UtcInstant($this->f->db->execute("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')")->fetchColumn());
            }
        };
    }
    public function testCollectionAndMembershipMutationInvalidateSourceReceiptsInSameTransaction(): void
    {
        CatalogFixture::run(function(CatalogFixture $f) {
            $ref=$this->seed($f); $scope=$this->scope($f); $store=new PdoPolicyEpoch($f->db,$f->tx,$scope->tenantId);
            $binding=$store->read($scope,[$ref]); self::assertSame('1',$binding->get('kbPolicyEpoch'));
            $clock=$this->clock($f); $owner=PolicyFixture::owner($clock,$binding);
            $owner->beforeCall=static function() use ($f): void { if ($f->db->pdo->inTransaction()) { throw new \LogicException('Owner in transaction'); } };
            $checks=new A\PolicyChecks($owner,$owner,$clock); $context=A\AccessContext::fromHost(PolicyFixture::host($scope));
            $resource=new D\ResourceRef($scope->tenantId,'source_version',$ref->sourceVersionId);
            $prepared=$checks->prepare($context,[$resource],D\Operation::Disclose,$binding); $calls=$owner->calls;
            $collection=new D\CreateCollection(new D\CollectionId(PortFixture::id(200)),null,new D\CatalogMetadata());
            $f->commands->createCollection($scope,$collection,$f->context('collection','1','1'));
            $this->rejects(fn()=>$store->underSharedGuards($scope,[$ref],fn($current,$now)=>$prepared->requireAllow($context,$current,$now)),D\Outcome::Denied,'POLICY_CHANGED');
            self::assertSame($calls,$owner->calls); $after=$store->read($this->scope($f),[$ref]);
            self::assertSame('2',$after->get('kbPolicyEpoch')); self::assertEquals($binding->get('sources'),$after->get('sources'));
            $current=$this->scope($f);
            // Metadata replay uses fresh owner authorization, but does not increment epochs again.
            $f->commands->createCollection($current,$collection,$f->context('collection','1','1'));
            self::assertSame('2',$store->read($current,[$ref])->get('kbPolicyEpoch'));
            $f->commands->addMembership($current,new D\AddMembership($ref->sourceId,$collection->collectionId,new D\CatalogMetadata()),$f->context('membership-collection','2','2'));
            self::assertSame('3',$store->read($this->scope($f),[$ref])->get('kbPolicyEpoch'));
            $owner->binding=$store->read($this->scope($f),[$ref]); $next=A\AccessContext::fromHost(PolicyFixture::host($this->scope($f)));
            $accepted=$checks->prepare($next,[$resource],D\Operation::Disclose,$owner->binding);
            $store->underSharedGuards($next->scope(),[$ref],fn($b,$now)=>$accepted->requireAllow($next,$b,$now));
            $this->rejects(fn()=>$store->underSharedGuards($next->scope(),[$ref],fn($b,$now)=>$accepted->requireAllow($next,$b,$now)),D\Outcome::Denied,'decision_consumed');
        });
    }
    public function testEpochCASAndExactTenantVersionLifecycleChecksFailClosed(): void
    {
        CatalogFixture::run(function(CatalogFixture $f) {
            $ref=$this->seed($f); $scope=$this->scope($f); $store=new PdoPolicyEpoch($f->db,$f->tx,$scope->tenantId);
            self::assertSame('1',$store->advanceTenant($scope->tenantId,new D\Decimal('0'))->value);
            $this->rejects(fn()=>$store->advanceTenant($scope->tenantId,new D\Decimal('0')),D\Outcome::Denied,'POLICY_CHANGED');
            self::assertSame('1',$store->advanceSource($scope,$ref->sourceId,new D\Decimal('0'))->value);
            self::assertSame('2',$store->advanceKb($scope)->value);
            $this->rejects(fn()=>$store->advanceKb($scope),D\Outcome::Denied,'POLICY_CHANGED');
            $binding=$store->read($this->scope($f),[$ref]);
            self::assertSame('1',$binding->get('tenantPolicyEpoch')); self::assertSame('1',$binding->get('sources')[0]->get('sourcePolicyEpoch'));
            $this->rejects(fn()=>$store->read(PolicyFixture::scope(tenant:9)),D\Outcome::Denied,'scope_mismatch');
            $foreign=new D\SourceVersionRef(new D\TenantId(PortFixture::id(9)),$ref->sourceId,$ref->sourceVersionId,$ref->sourceDigest);
            $this->rejects(fn()=>$store->read($scope,[$foreign]),D\Outcome::Denied,'scope_mismatch');
            $forged=new D\SourceVersionRef($ref->tenantId,$ref->sourceId,$ref->sourceVersionId,new D\SourceDigest(str_repeat('f',64)));
            $this->rejects(fn()=>$store->read($scope,[$forged]),D\Outcome::Incompatible,'immutable_conflict');
            $this->rejects(fn()=>$store->read($scope,[$ref,$ref]),D\Outcome::Incompatible,'duplicate_reference');
            $f->db->execute("UPDATE source_version_state SET usability='corrected' WHERE tenant_id=? AND id=?",[$ref->tenantId->value,$ref->sourceVersionId->value]);
            $this->rejects(fn()=>$store->read($scope,[$ref]),D\Outcome::Denied,'source_unusable');
            // Retention hold does not grant read; immutable refs remain unchanged after revoke.
            $f->db->execute("UPDATE sources SET lifecycle_state='revoked',lifecycle_epoch=lifecycle_epoch+1,retention_hold=true WHERE tenant_id=? AND id=?",[$ref->tenantId->value,$ref->sourceId->value]);
            $this->rejects(fn()=>$store->read($scope,[$ref]),D\Outcome::Denied,'source_unusable');
            self::assertEquals($ref,$f->repo->getVersion($ref->tenantId,$ref)->ref);
            self::assertSame('accepted',$f->db->execute('SELECT state FROM resource_ledger')->fetchColumn());
        });
    }
    public function testRightsRevokeAfterExtractionBlocksFreshPublicationAndGuardedAcceptance(): void
    {
        CatalogFixture::run(function(CatalogFixture $f) {
            $ref=$this->seed($f); $scope=$this->scope($f); $store=new PdoPolicyEpoch($f->db,$f->tx,$scope->tenantId);
            $clock=$this->clock($f); $owner=PolicyFixture::owner($clock,$store->read($scope,[$ref]));
            $checks=new A\PolicyChecks($owner,$owner,$clock); $context=A\AccessContext::fromHost(PolicyFixture::host($scope));
            $resource=new D\ResourceRef($scope->tenantId,'source_version',$ref->sourceVersionId);
            $checks->admission($context,[$resource],D\Operation::Ingest,$owner->binding);
            $preflight=$checks->prepare($context,[$resource],D\Operation::Publish,$owner->binding);
            $owner->decision=D\Decision::Deny;
            $this->rejects(fn()=>$checks->prepare($context,[$resource],D\Operation::Publish,$owner->binding),D\Outcome::Denied,'policy_denied');
            $store->advanceSource($scope,$ref->sourceId,new D\Decimal('0'));
            $this->rejects(fn()=>$store->underSharedGuards($scope,[$ref],fn($b,$now)=>$preflight->requireAllow($context,$b,$now)),D\Outcome::Denied,'POLICY_CHANGED');
            self::assertNull($f->repo->getKb($scope->tenantId,$scope->kbId)->activeSnapshotId);
        });
    }
}
