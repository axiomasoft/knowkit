<?php
declare(strict_types=1);

namespace KnowKit\Tests\Contract\Ports;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Port as P;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class PolicySpy implements P\AccessPolicy, P\EgressPolicy
{
    public D\Decision $processing = D\Decision::Allow;
    public D\Decision $egress = D\Decision::Allow;
    public int $processingCalls = 0;
    public int $egressCalls = 0;
    public function authorizeQuery(D\Scope $scope): D\QueryGrant { return PortFixture::grant(); }
    public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
    {
        $this->processingCalls++;
        return PortFixture::receipt($this->processing,$operation,$resource,$scope);
    }
    public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
    {
        $this->egressCalls++;
        $items = $request->toWire()->get('items');
        $resource = $items[0]->get('sourceVersionRefs') === []
            ? new D\ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId)
            : new D\ResourceRef($scope->tenantId,'source_version',new D\SourceVersionId($items[0]->get('sourceVersionRefs')[0]->get('sourceVersionId')));
        return PortFixture::receipt($this->egress,D\Operation::from($request->toWire()->get('operation')),$resource,$scope);
    }
}

final class ProviderSpy extends P\PolicyCheckedEmbeddingProvider
{
    public int $sends = 0;
    public function capabilities(): D\EmbeddingCapabilities
    {
        return new D\EmbeddingCapabilities([D\Capability::EmbeddingDocuments,D\Capability::EmbeddingQuery],['ir-1.0']);
    }
    protected function performDocuments(D\EmbeddingAccess $access, array $inputs, D\SpaceRef $space): D\Result
    {
        $this->sends++;
        return D\Result::success(new D\EmbeddingBatch(array_map(static fn() => new D\Vector([1,2,3],$space),$inputs),$space));
    }
    protected function performQuery(D\EmbeddingAccess $access, D\QueryInput $input, D\SpaceRef $space): D\Result
    {
        $this->sends++;
        return D\Result::success(new D\Vector([1,2,3],$space));
    }
}

#[Group('contract:ports')]
final class BoundaryTest extends TestCase
{
    private function clock(): P\Clock
    {
        return new class implements P\Clock {
            public function now(): D\UtcInstant { return new D\UtcInstant('2026-11-06T12:00:01Z'); }
        };
    }

    public function testTenantAndImmutableBoundariesRejectCrossTenantRefs(): void
    {
        $scope = PortFixture::scope(); $source = PortFixture::source();
        $foreign = new D\TenantId(PortFixture::id(999));
        $ref = new D\SourceVersionRef($foreign,$source->sourceId,$source->sourceVersionId,$source->sourceDigest);
        foreach ([
            static fn() => $scope->assertTenant($foreign),
            static fn() => (new D\ExactIdentityGuard($scope->tenantId))->remember($ref),
            static fn() => new D\SourceVersion($ref,new D\BlobRef($scope->tenantId,$source->sourceId,$source->sourceDigest,
                new D\Uint64('1'),'test','v1','owned')),
            static fn() => new D\ResourcePage($scope->tenantId,[new D\ResourceRef($foreign,'source',$source->sourceId)],null),
            static fn() => new D\Parents($scope->tenantId,[new D\ResourceRef($foreign,'source',$source->sourceId)]),
        ] as $boundary) {
            try { $boundary(); self::fail('Cross-tenant boundary accepted'); }
            catch (D\DomainFailure $failure) { self::assertSame(D\Outcome::Denied,$failure->outcome); }
        }
    }

    public function testGrantAndHostExclusionsApplyBeforeTopK(): void
    {
        $scope = PortFixture::scope(); $grant = PortFixture::grant();
        $allowed = new D\SourceVersionId(PortFixture::id(3)); $excluded = new D\SourceVersionId(PortFixture::id(444));
        $search = D\AuthorizedSearchScope::materialize($scope,$grant,PortFixture::snapshot(),new D\GenerationId(PortFixture::id(14)),
            [$allowed,$excluded],$this->clock()->now());
        self::assertSame([$allowed],$search->allowedSourceVersionIds);
        self::assertSame($scope->hostScopeHash,$search->hostScopeHash);
        self::assertSame([],PortFixture::validator()->validateValue($search->toWire(),'urn:knowkit:schema:authorized-search-scope:1.0'));
        $nobody = D\AuthorizedSearchScope::materialize($scope,$grant,PortFixture::snapshot(),new D\GenerationId(PortFixture::id(14)),
            [],$this->clock()->now());
        self::assertSame([],$nobody->allowedSourceVersionIds);
        $query = new D\Query('identifier',[$excluded]);
        $filtered = D\AuthorizedSearchScope::materialize($scope,$grant,PortFixture::snapshot(),new D\GenerationId(PortFixture::id(14)),
            [$allowed],$this->clock()->now(),null,$query);
        self::assertSame([],$filtered->allowedSourceVersionIds);

        // Backend contract: filter the full membership universe before rank/limit. Foreign first must not consume k=1.
        $foreignRef = new D\ChunkRef(new D\DocumentRevisionRef(new D\SourceVersionRef(new D\TenantId(PortFixture::id(999)),
            PortFixture::source()->sourceId,$excluded,PortFixture::source()->sourceDigest),PortFixture::revision()->documentRevisionId,
            PortFixture::revision()->irDigest,PortFixture::revision()->parserSignature),PortFixture::chunk()->chunkSetId,
            new D\ChunkId(PortFixture::id(998)),PortFixture::chunk()->chunkDigest);
        $index = new class([$foreignRef,PortFixture::chunk()]) implements P\LexicalIndex {
            public function __construct(private array $all) {}
            public function index(D\GenerationId $generationId,D\ChunkSet $chunkSet): D\IndexReceipt
            { throw new D\DomainFailure(D\Outcome::Unavailable,'not_indexed'); }
            public function search(D\AuthorizedSearchScope $scope,D\Query $query,int $k): D\CandidateList
            {
                $selected = [];
                foreach ($this->all as $ref) {
                    try { $scope->assertCandidate($ref); $selected[] = $ref; } catch (D\DomainFailure) {}
                }
                return new D\CandidateList($scope,array_map(static fn($ref) => new D\Candidate($ref,1),array_slice($selected,0,$k)));
            }
        };
        self::assertEquals(PortFixture::chunk(),$index->search($search,new D\Query('identifier'),1)->candidates[0]->ref);
        $this->expectException(D\DomainFailure::class);
        new D\CandidateList($search,[new D\Candidate($foreignRef,1)]);
    }

    public function testCollectionFiltersAndStaleGrantCannotWidenScope(): void
    {
        $scope = PortFixture::scope(); $source = new D\SourceVersionId(PortFixture::id(3));
        $collection = new D\CollectionId(PortFixture::id(200));
        $memberships = new D\CurrentMemberships($scope,[$source->value=>[$collection]]);
        $query = new D\Query('q',null,[$collection]);
        $args = [$scope,PortFixture::grant(),PortFixture::snapshot(),new D\GenerationId(PortFixture::id(14)),[$source],$this->clock()->now()];
        self::assertSame([$source],D\AuthorizedSearchScope::materialize(...[...$args,null,$query,$memberships])->allowedSourceVersionIds);
        $other = new D\Query('q',null,[new D\CollectionId(PortFixture::id(201))]);
        self::assertSame([],D\AuthorizedSearchScope::materialize(...[...$args,null,$other,$memberships])->allowedSourceVersionIds);
        try { D\AuthorizedSearchScope::materialize(...[...$args,null,$query]); self::fail('Guessed missing memberships'); }
        catch (D\DomainFailure $failure) { self::assertSame(D\Outcome::Incompatible,$failure->outcome); }
        $expiredArgs = $args; $expiredArgs[5] = new D\UtcInstant('2026-11-06T12:00:05Z');
        $this->expectException(D\DomainFailure::class); D\AuthorizedSearchScope::materialize(...$expiredArgs);
    }

    public function testReceiptFreshnessPolicyChangesAndHostScopeMismatch(): void
    {
        $scope = PortFixture::scope(); $resource = new D\ResourceRef($scope->tenantId,'source_version',PortFixture::source()->sourceVersionId);
        $binding = new D\Sha256(HashDomain::PolicyBinding->digest(PortFixture::binding()));
        $receipt = PortFixture::receipt(D\Decision::Allow,D\Operation::Disclose,$resource);
        $receipt->requireAllow($scope,$resource,D\Operation::Disclose,$this->clock()->now(),$binding);
        foreach ([
            [$scope,new D\UtcInstant('2026-11-06T12:00:05Z'),$binding],
            [PortFixture::scope(str_repeat('c',64)),$this->clock()->now(),$binding],
            [$scope,$this->clock()->now(),new D\Sha256(str_repeat('d',64))],
        ] as [$checkedScope,$now,$checkedBinding]) {
            try { $receipt->requireAllow($checkedScope,$resource,D\Operation::Disclose,$now,$checkedBinding); self::fail('Stale receipt accepted'); }
            catch (D\DomainFailure $failure) { self::assertSame(D\Outcome::Denied,$failure->outcome); }
        }
        foreach ([D\Decision::Deny,D\Decision::Unknown] as $decision) {
            $denied = PortFixture::receipt($decision,D\Operation::Disclose,$resource);
            try { $denied->requireAllow($scope,$resource,D\Operation::Disclose,$this->clock()->now(),$binding); self::fail('Non-Allow accepted'); }
            catch (D\DomainFailure $failure) {
                self::assertSame($decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown,$failure->outcome);
            }
        }
    }

    public function testEveryEmbeddingCallChecksProcessingAndEgressBeforeSending(): void
    {
        $policy = new PolicySpy(); $provider = new ProviderSpy($policy,$policy,$this->clock());
        $inputs = [new D\EmbeddingInput(PortFixture::chunk(),'provider input')];
        foreach ([D\Decision::Deny,D\Decision::Unknown] as $decision) {
            $policy->egress = $decision;
            $expected = $decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown;
            self::assertSame($expected,$provider->embedDocuments(PortFixture::egress(true),$inputs,PortFixture::space())->outcome);
            self::assertSame($expected,$provider->embedQuery(PortFixture::egress(false),new D\QueryInput('provider input'),PortFixture::space())->outcome);
            self::assertSame(0,$provider->sends);
        }
        $policy->egress = D\Decision::Allow;
        self::assertSame(D\Outcome::Success,$provider->embedDocuments(PortFixture::egress(true),$inputs,PortFixture::space())->outcome);
        self::assertSame(D\Outcome::Success,$provider->embedQuery(PortFixture::egress(false),new D\QueryInput('provider input'),PortFixture::space())->outcome);
        self::assertSame(2,$provider->sends); self::assertSame(6,$policy->processingCalls); self::assertSame(6,$policy->egressCalls);
        $policy->processing = D\Decision::Deny;
        self::assertSame(D\Outcome::Denied,$provider->embedQuery(PortFixture::egress(false),new D\QueryInput('provider input'),PortFixture::space())->outcome);
        self::assertSame(2,$provider->sends); self::assertSame(6,$policy->egressCalls);
        $policy->processing = D\Decision::Allow;
        self::assertSame(D\Outcome::Incompatible,$provider->embedQuery(PortFixture::egress(false),new D\QueryInput('different bytes'),PortFixture::space())->outcome);
        self::assertSame(2,$provider->sends);
        $broken = new class($policy,$policy,$this->clock()) extends P\PolicyCheckedEmbeddingProvider {
            public function capabilities(): D\EmbeddingCapabilities { return new D\EmbeddingCapabilities([D\Capability::EmbeddingQuery],['ir-1.0']); }
            protected function performDocuments(D\EmbeddingAccess $access,array $inputs,D\SpaceRef $space): D\Result
            { return D\Result::success(new D\EmbeddingBatch([],$space)); }
            protected function performQuery(D\EmbeddingAccess $access,D\QueryInput $input,D\SpaceRef $space): D\Result
            { return D\Result::success(new D\Vector([1,2,3],PortFixture::space(['revision'=>'wrong']))); }
        };
        self::assertSame(D\Outcome::Incompatible,$broken->embedDocuments(PortFixture::egress(true),$inputs,PortFixture::space())->outcome);
        self::assertSame(D\Outcome::Incompatible,$broken->embedQuery(PortFixture::egress(false),new D\QueryInput('provider input'),PortFixture::space())->outcome);
    }

    public function testVectorsAndRerankerCannotChangeSpaceOrExpandCandidates(): void
    {
        foreach ([[1,2],[INF,2,3],[NAN,2,3]] as $values) {
            try { new D\Vector($values,PortFixture::space()); self::fail('Invalid vector'); } catch (D\DomainFailure) {}
        }
        try { new D\Vector([0,0,0],PortFixture::space(['normalization'=>'l2'])); self::fail('Zero norm'); } catch (D\DomainFailure) {}
        try { new D\EmbeddingBatch([new D\Vector([1,2,3],PortFixture::space(['revision'=>'2']))],PortFixture::space()); self::fail('Mismatched batch'); }
        catch (D\DomainFailure $failure) { self::assertSame('space_mismatch',$failure->errorCode); }
        $candidate = new D\Candidate(PortFixture::chunk(),1);
        $list = new D\CandidateList(PortFixture::search(),[$candidate]);
        self::assertSame([$candidate],(new D\CandidateSubset($list,[$candidate]))->candidates);
        $expanded = new D\Candidate(new D\ChunkRef($candidate->ref->revisionRef,$candidate->ref->chunkSetId,
            $candidate->ref->chunkId,new D\Sha256(str_repeat('e',64))),1);
        $this->expectException(D\DomainFailure::class); new D\CandidateSubset($list,[$expanded]);
    }

    public function testLeaseFenceTokenExpiryDeadlineAndFalseWrites(): void
    {
        $make = static fn(string $fence,string $token,string $until = '2026-11-06T12:01:00Z') =>
            new D\Lease(PortFixture::scope(),new D\JobId(PortFixture::id(300)),new D\AttemptId(PortFixture::id(301)),
                PortFixture::source(),null,new D\Decimal($fence),$token,new D\UtcInstant($until),
                new D\UtcInstant('2026-11-06T12:15:00Z'),new D\Decimal('1'));
        $old = $make('1',str_repeat('a',32)); $current = $make('2',str_repeat('b',32));
        self::assertTrue($current->isCurrent($current,$this->clock()->now()));
        foreach ([$old,$make('2',str_repeat('c',32))] as $stale) {
            self::assertFalse($current->isCurrent($stale,$this->clock()->now()));
        }
        self::assertFalse($current->isCurrent($current,new D\UtcInstant('2026-11-06T12:01:00Z')));
        self::assertFalse($current->isCurrent($current,new D\UtcInstant('2026-11-06T12:15:00Z')));
        self::assertStringNotContainsString(str_repeat('b',32),print_r($current,true));
        foreach (['heartbeat','checkpoint','complete'] as $write) {
            try { D\DomainFailure::requireWrite($current->isCurrent($old,$this->clock()->now()),'stale_lease'); self::fail($write . ' reported success'); }
            catch (D\DomainFailure $failure) { self::assertSame('stale_lease',$failure->errorCode); }
        }
        D\DomainFailure::requireWrite(true);
        $this->expectException(\InvalidArgumentException::class);
        $make('2',str_repeat('b',32),'2026-11-06T12:16:00Z');
    }

    public function testDependencyParentsAndLifecycleAxesRemainSeparate(): void
    {
        $scope = PortFixture::scope(); $a = new D\ResourceRef($scope->tenantId,'source',new D\SourceId(PortFixture::id(2)));
        $b = new D\ResourceRef($scope->tenantId,'source_version',new D\SourceVersionId(PortFixture::id(3)));
        $parents = new D\Parents($scope->tenantId,[$a,$b]);
        self::assertSame($parents->digest()->value,(new D\Parents($scope->tenantId,[$b,$a]))->digest()->value);
        try { new D\Parents($scope->tenantId,[$a,$a]); self::fail('Duplicate parent'); } catch (D\DomainFailure) {}
        try { (new D\Node($a,new D\Fingerprint(str_repeat('a',64))))->assertParents($parents); self::fail('Self parent'); } catch (D\DomainFailure) {}
        $source = new D\Source($scope->tenantId,new D\SourceId(PortFixture::id(2)),D\SourceLifecycleState::Revoked,
            new D\Decimal('2'),new D\Decimal('3'),true);
        self::assertTrue($source->retentionHold); self::assertSame(D\SourceLifecycleState::Revoked,$source->lifecycleState);
        $corrected = new D\SourceVersionState($scope->tenantId,new D\SourceVersionId(PortFixture::id(3)),D\Usability::Corrected,
            new D\SourceVersionId(PortFixture::id(4)),new D\EventId(PortFixture::id(400)));
        self::assertSame(D\Usability::Corrected,$corrected->usability);
        $tombstone = new D\Tombstone($scope->tenantId,$source->sourceId,'source_revoked',new D\EventId(PortFixture::id(401)),new D\Decimal('2'));
        self::assertSame('future_versions',$tombstone->appliesTo);
        $coverage = new D\Coverage(PortFixture::fixture()->get('history')[0]->get('ir')->get('coverage'));
        self::assertSame('complete',$coverage->toWire()->get('state'));
        self::assertSame('complete',(new D\Coverage($coverage->toWire()->with('counts',$coverage->toWire()->get('counts')->without('expected'))))->toWire()->get('state'));
        try { new D\Coverage($coverage->toWire()->with('counts',$coverage->toWire()->get('counts')->with('failed',1))); self::fail('False complete coverage'); }
        catch (\InvalidArgumentException) {}
        try { new D\Coverage($coverage->toWire()->with('diagnostics',[new JsonObject(['code'=>'error text is forbidden','severity'=>'error'])])); self::fail('Raw diagnostic text'); }
        catch (\InvalidArgumentException) {}
        $capabilities = new D\ParserCapabilities([D\Capability::ParserText],['ir-1.0']);
        $capabilities->require(D\Capability::ParserText,'ir-1.0');
        try { $capabilities->require(D\Capability::ParserText,'ir-2.0'); self::fail('Unsupported schema'); }
        catch (D\DomainFailure $failure) { self::assertSame(D\Outcome::Incompatible,$failure->outcome); }
        $processing = PortFixture::processing(PortFixture::fixture()->get('base'));
        self::assertCount(1,(new D\BuildGeneration(new D\GenerationId(PortFixture::id(14)),[PortFixture::source()],[$processing]))->sourceRefs);
        try { new D\BuildGeneration(new D\GenerationId(PortFixture::id(14)),[PortFixture::source()],[]); self::fail('Missing processing identity'); }
        catch (\InvalidArgumentException) {}
        $emptyInventory = new D\CrawlSnapshot(new D\AdapterId('recorded'),'repository:test','exact-commit',
            true,[],[],0,new D\Fingerprint(str_repeat('d',64)),$this->clock()->now());
        self::assertTrue($emptyInventory->complete); self::assertSame([],$emptyInventory->items);
        foreach ([1,4294967296] as $omitted) {
            try { new D\CrawlSnapshot($emptyInventory->connectorId,$emptyInventory->sourceNamespace,$emptyInventory->pinnedRevision,
                true,[],[],$omitted,$emptyInventory->inventoryDigest,$emptyInventory->observedAt); self::fail('Incomplete crawl claimed complete'); }
            catch (D\DomainFailure $failure) { self::assertSame(D\Outcome::Incompatible,$failure->outcome); }
        }
    }

    public function testReservationPrecedesUnknownDigestAndRedactsDocumentInputs(): void
    {
        $scope = PortFixture::scope();
        $reservation = new D\BlobWriteReservation(new D\ResourceRef($scope->tenantId,'resource',new D\ResourceId(PortFixture::id(500))),
            new D\SourceId(PortFixture::id(2)),new D\WriterId(PortFixture::id(501)),new D\Decimal('1'),new D\Decimal('1'),null,null,new D\Ulid(PortFixture::id(502)));
        $bytes = new D\SourceBytes(PortFixture::source(),PortFixture::fixture()->get('sourceBytes'),'text/plain');
        $upload = new D\SourceUpload($bytes,$reservation);
        self::assertSame($bytes,$upload->source);
        self::assertStringNotContainsString(PortFixture::fixture()->get('sourceBytes'),print_r($bytes,true));
        self::assertStringNotContainsString('provider input',print_r(new D\EmbeddingInput(PortFixture::chunk(),'provider input'),true));
        $this->expectException(\InvalidArgumentException::class);
        new D\BlobWriteReservation($reservation->resource,$reservation->sourceId,$reservation->writerId,
            $reservation->fence,$reservation->sourceEpoch,null,null,null);
    }
}
