<?php
declare(strict_types=1);

namespace KnowKit\Tests\Contract\Ports;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('contract:ports')]
final class CatalogTest extends TestCase
{
    private function fixture(): JsonObject
    {
        $fixture = PortFixture::json('tests/fixtures/identity/v1/catalog.json');
        self::assertSame('catalog-fixture:1',$fixture->get('schemaVersion'));
        self::assertCount(3,$fixture->get('requestDigests'));
        self::assertCount(5,$fixture->get('invalidMetadata'));
        return $fixture;
    }
    private function metadata(): D\CatalogMetadata { return new D\CatalogMetadata($this->fixture()->get('metadata')); }
    private function context(?JsonObject $wire = null): D\MutationContext
    {
        return new D\MutationContext($wire ?? $this->fixture()->get('context'),PortFixture::validator());
    }
    private function source(int $id = 2, ?D\TenantId $tenant = null): D\Source
    {
        return new D\Source($tenant ?? PortFixture::scope()->tenantId,new D\SourceId(PortFixture::id($id)),
            D\SourceLifecycleState::Revoked,new D\Decimal('2'),new D\Decimal('3'),true,
            $this->fixture()->get('ownerRef'),$this->fixture()->get('origin'));
    }
    private function rejects(callable $operation, string $code, D\Outcome $outcome = D\Outcome::Incompatible): void
    {
        try { $operation(); self::fail('Boundary accepted invalid input: ' . $code); }
        catch (D\DomainFailure $failure) {
            self::assertSame($code,$failure->errorCode); self::assertSame($outcome,$failure->outcome);
        }
    }

    public function testCatalogRecordsContainRequiredFieldsWithoutChangingIdentity(): void
    {
        $scope = PortFixture::scope(); $fixture = $this->fixture();
        $kb = new D\KnowledgeBase($scope->tenantId,$scope->kbId,$scope->policyEpoch,PortFixture::snapshot()->snapshotId,
            new D\Decimal('9'),$fixture->get('name'),new D\Decimal('7'),$fixture->get('policyRef'),
            new D\ProfileId('lexical-v1'),new D\CoveragePolicy(false,['tables']));
        self::assertSame($fixture->get('name'),$kb->name); self::assertSame('7',$kb->revision->value);
        self::assertSame($fixture->get('policyRef'),$kb->policyRef); self::assertSame('lexical-v1',$kb->defaultRetrievalProfile->value);
        self::assertFalse($kb->coveragePolicy->allowPartial); self::assertSame(['tables'],$kb->coveragePolicy->requiredFeatures);
        self::assertSame('42',$kb->policyEpoch->value); self::assertSame('9',$kb->pointerVersion->value);
        $source = $this->source();
        self::assertSame($fixture->get('ownerRef'),$source->ownerRef); self::assertSame($fixture->get('origin'),$source->origin);
        self::assertSame(D\SourceLifecycleState::Revoked,$source->lifecycleState); self::assertTrue($source->retentionHold);
        $command = new D\RegisterSource($source->sourceId,$source->ownerRef,$source->origin);
        self::assertSame($source->sourceId,$command->sourceId);
        $ref = PortFixture::source();
        $blob = new D\BlobRef($scope->tenantId,$ref->sourceId,$ref->sourceDigest,new D\Uint64('12'),'test','v1','owned');
        $version = new D\SourceVersion($ref,$blob,$fixture->get('mime'),$fixture->get('rightsRef'),new D\UtcInstant($fixture->get('createdAt')));
        self::assertSame($ref,$version->ref); self::assertSame($blob,$version->blob);
        self::assertSame($fixture->get('mime'),$version->mime); self::assertSame($fixture->get('rightsRef'),$version->rightsRef);
        self::assertSame($fixture->get('createdAt'),$version->createdAt->value);
        foreach ([null,new D\CollectionId(PortFixture::id(200)),new D\CollectionId(PortFixture::id(201))] as $collectionId) {
            $member = new D\SourceMembership($scope->tenantId,$scope->kbId,$collectionId,$source->sourceId,new D\Decimal('7'),$this->metadata());
            self::assertInstanceOf(D\Membership::class,$member); self::assertSame($collectionId,$member->collectionId);
            self::assertSame($source->sourceId,$member->sourceId); self::assertSame('7',$member->revision->value);
            self::assertEquals($fixture->get('metadata'),$member->metadata->toWire());
        }
        self::assertSame('blocked',(new D\Tenant($scope->tenantId))->servingState);
        self::assertSame('ready',(new D\Tenant($scope->tenantId,'ready'))->servingState);
        foreach ([$kb,$source,$version,$member,new D\Tenant($scope->tenantId),$command] as $record) {
            self::assertTrue((new \ReflectionClass($record))->isReadOnly());
        }
        $this->expectException(\Error::class); $version->rightsRef = 'changed';
    }

    public function testMetadataAndProfilesRejectUnboundedOrUnknownShapes(): void
    {
        foreach ($this->fixture()->get('invalidMetadata') as $wire) {
            try { new D\CatalogMetadata($wire); self::fail('Invalid metadata accepted'); }
            catch (\InvalidArgumentException) {}
        }
        $many = [];
        for ($i = 0; $i < 33; $i++) { $many['test.key' . $i] = true; }
        foreach ([new JsonObject($many),new JsonObject(['test.label'=>str_repeat('я',257)]),new JsonObject(['test.label'=>"\xff"]),
            new JsonObject(['host.bad'=>1.25])] as $wire) {
            try { new D\CatalogMetadata($wire); self::fail('Unbounded metadata accepted'); }
            catch (\InvalidArgumentException) {}
        }
        $input = new JsonObject(['test.empty'=>'','test.integer'=>7,'test.boolean'=>false]);
        $metadata = new D\CatalogMetadata($input);
        self::assertSame(Jcs::canonicalize($input),Jcs::canonicalize($metadata->toWire()));
        self::assertNotSame($metadata->toWire(),$metadata->toWire());
        foreach (['latest','Lexical-v1','lexical_v1','a','lexical-v1 '] as $bad) {
            // "latest" is syntactically a named profile, never an automatic resolver alias.
            if ($bad === 'latest') { self::assertSame('latest',(new D\ProfileId($bad))->value); continue; }
            try { new D\ProfileId($bad); self::fail('Invalid profile ID'); } catch (\InvalidArgumentException) {}
        }
        foreach (['','unknown'] as $badState) {
            try { new D\Tenant(PortFixture::scope()->tenantId,$badState); self::fail('Invalid serving state'); }
            catch (\InvalidArgumentException) {}
        }
        foreach (['',' '] as $missing) {
            try { new D\RegisterSource($this->source()->sourceId,$missing,'host:upload'); self::fail('Missing owner'); }
            catch (\InvalidArgumentException) {}
        }
    }

    public function testCollectionParentsRequireSameTenantKbAndACompleteAcyclicChain(): void
    {
        $scope = PortFixture::scope(); $a = new D\CollectionId(PortFixture::id(200)); $b = new D\CollectionId(PortFixture::id(201));
        $make = fn(D\CollectionId $id,?D\CollectionId $parent,?D\TenantId $tenant = null,?D\KbId $kb = null) =>
            new D\Collection($tenant ?? $scope->tenantId,$kb ?? $scope->kbId,$id,$parent,new D\Decimal('7'),$this->metadata());
        $child = $make($a,$b); $root = $make($b,null); $root->assertAncestry([]); $child->assertAncestry([$root]);
        self::assertSame('7',$child->revision->value); self::assertEquals($this->metadata(),$child->metadata);
        $this->rejects(fn() => $make($a,$a),'collection_cycle');
        $this->rejects(fn() => new D\CreateCollection($a,$a,$this->metadata()),'collection_cycle');
        $this->rejects(fn() => $child->assertAncestry([$make($b,$a)]),'collection_cycle');
        $this->rejects(fn() => $child->assertAncestry([$root,$root]),'collection_cycle');
        $this->rejects(fn() => $child->assertAncestry([]),'collection_parent_missing');
        $this->rejects(fn() => $child->assertAncestry([$make(new D\CollectionId(PortFixture::id(202)),null)]),'collection_parent_mismatch');
        $this->rejects(fn() => $child->assertAncestry([$make($b,null,new D\TenantId(PortFixture::id(999)))]),'scope_mismatch',D\Outcome::Denied);
        $this->rejects(fn() => $child->assertAncestry([$make($b,null,null,new D\KbId(PortFixture::id(999)))]),'scope_mismatch',D\Outcome::Denied);
    }

    public function testMutationDigestsBindOperationBusinessFieldsTenantAndExpectedState(): void
    {
        $fixture = $this->fixture(); $scope = PortFixture::scope(); $context = $this->context();
        $commands = [
            new D\CreateKB($scope->kbId,$fixture->get('name'),$fixture->get('policyRef'),new D\ProfileId('lexical-v1'),new D\CoveragePolicy(false,['tables'])),
            new D\CreateCollection(new D\CollectionId(PortFixture::id(200)),null,$this->metadata()),
            new D\AddMembership($this->source()->sourceId,null,$this->metadata()),
        ];
        self::assertSame('catalog-test',$context->idempotencyKey()->value);
        foreach ($commands as $i => $command) {
            $digest = $command->requestDigest($scope,$context);
            self::assertSame($fixture->get('requestDigests')[$i],$digest->value);
            $retry = $fixture->get('context')->with('requestId',PortFixture::id(700))->with('idempotencyKey','another-key');
            self::assertSame($digest->value,$command->requestDigest($scope,$this->context($retry))->value);
            foreach (['expectedRevision','expectedEpoch'] as $expected) {
                self::assertNotSame($digest->value,$command->requestDigest($scope,$this->context($retry->with($expected,'8')))->value);
            }
            $foreignScope = new D\Scope(new D\TenantId(PortFixture::id(999)),$scope->kbId,$scope->actorId,$scope->purpose,$scope->policyEpoch,$scope->hostScopeHash);
            self::assertNotSame($digest->value,$command->requestDigest($foreignScope,$context)->value);
        }
        foreach ([
            new D\CreateKB($scope->kbId,'Renamed',$fixture->get('policyRef'),new D\ProfileId('lexical-v1'),new D\CoveragePolicy(false,['tables'])),
            new D\CreateKB($scope->kbId,$fixture->get('name'),'policy:other',new D\ProfileId('lexical-v1'),new D\CoveragePolicy(false,['tables'])),
            new D\CreateKB($scope->kbId,$fixture->get('name'),$fixture->get('policyRef'),new D\ProfileId('hybrid-v1'),new D\CoveragePolicy(false,['tables'])),
            new D\CreateKB($scope->kbId,$fixture->get('name'),$fixture->get('policyRef'),new D\ProfileId('lexical-v1'),new D\CoveragePolicy(true,['tables'])),
            new D\CreateKB($scope->kbId,$fixture->get('name'),$fixture->get('policyRef'),new D\ProfileId('lexical-v1'),new D\CoveragePolicy(false,[])),
        ] as $changed) {
            self::assertNotSame($commands[0]->requestDigest($scope,$context)->value,$changed->requestDigest($scope,$context)->value);
        }
        $fields = new JsonObject(['tenantId'=>$scope->tenantId->value]);
        self::assertNotSame($context->requestDigest(D\Operation::CatalogCreate,$fields)->value,
            $context->requestDigest(D\Operation::CollectionCreate,$fields)->value);
        self::assertNotSame($commands[1]->requestDigest($scope,$context)->value,
            (new D\CreateCollection(new D\CollectionId(PortFixture::id(200)),new D\CollectionId(PortFixture::id(201)),$this->metadata()))->requestDigest($scope,$context)->value);
        self::assertNotSame($commands[2]->requestDigest($scope,$context)->value,
            (new D\AddMembership($this->source()->sourceId,new D\CollectionId(PortFixture::id(200)),$this->metadata()))->requestDigest($scope,$context)->value);
        $this->rejects(fn() => (new D\CreateKB(new D\KbId(PortFixture::id(999)),$fixture->get('name'),$fixture->get('policyRef'),
            new D\ProfileId('lexical-v1'),new D\CoveragePolicy()))->requestDigest($scope,$context),'scope_mismatch',D\Outcome::Denied);
        $context->assertExpected(new D\Decimal('7'),new D\Decimal('42'));
        $this->rejects(fn() => $context->assertExpected(new D\Decimal('8'),new D\Decimal('42')),'stale_write');
        $this->rejects(fn() => $context->assertExpected(new D\Decimal('7'),new D\Decimal('43')),'POLICY_CHANGED',D\Outcome::Denied);
        $this->context($fixture->get('context')->without('expectedRevision')->without('expectedEpoch'))
            ->assertExpected(new D\Decimal('8'),new D\Decimal('43'));
    }

    public function testPaginationBindsTenantKbFilterAndSecurityScopeBeforeReturningRows(): void
    {
        $scope = PortFixture::scope(); $filter = new D\CollectionFilter(new D\CollectionId(PortFixture::id(200)));
        $security = $scope->bindingDigest(new D\Sha256(str_repeat('b',64)));
        $page = new D\PageCursor($scope->tenantId,$scope->kbId,$filter,$security,null,2);
        $page->assertBinding($scope->tenantId,$scope->kbId,$filter,$security);
        foreach ([
            [new D\TenantId(PortFixture::id(999)),$scope->kbId,$filter,$security],
            [$scope->tenantId,new D\KbId(PortFixture::id(999)),$filter,$security],
            [$scope->tenantId,$scope->kbId,new D\CollectionFilter(),$security],
            [$scope->tenantId,$scope->kbId,$filter,new D\Sha256(str_repeat('c',64))],
        ] as $args) { $this->rejects(fn() => $page->assertBinding(...$args),'scope_mismatch',D\Outcome::Denied); }
        foreach ([0,101] as $limit) {
            try { new D\PageCursor($scope->tenantId,$scope->kbId,$filter,$security,null,$limit); self::fail('Unbounded page'); }
            catch (\InvalidArgumentException) {}
        }
        $next = new D\PageCursor($scope->tenantId,$scope->kbId,$filter,$security,'opaque-continuation',2);
        $sources = [$this->source(2),$this->source(3)];
        self::assertSame($sources,(new D\SourcePage($page,$sources,$next))->sources);
        self::assertSame([],(new D\SourcePage($page,[],null))->sources);
        $this->rejects(fn() => new D\SourcePage($page,[$this->source(2,new D\TenantId(PortFixture::id(999)))],null),'scope_mismatch',D\Outcome::Denied);
        $this->rejects(fn() => new D\SourcePage($page,array_reverse($sources),null),'adapter_contract_violation');
        $this->rejects(fn() => new D\SourcePage($page,[$sources[0],$sources[0]],null),'adapter_contract_violation');
        $this->rejects(fn() => new D\SourcePage($page,[],$next),'adapter_contract_violation');
        $this->rejects(fn() => new D\SourcePage($page,$sources,$page),'adapter_contract_violation');
        $this->rejects(fn() => new D\SourcePage($next,$sources,$next),'adapter_contract_violation');
        try { new D\SourcePage($page,[...$sources,$this->source(4)],null); self::fail('Page exceeds caller limit'); }
        catch (\InvalidArgumentException) {}
    }

    public function testExactVersionsAndSnapshotSelectorsRejectSubstitution(): void
    {
        $ref = PortFixture::source(); $fixture = $this->fixture();
        $makeVersion = fn(D\TenantId $tenant,D\SourceId $source,D\Sha256 $digest) => new D\SourceVersion($ref,
            new D\BlobRef($tenant,$source,$digest,new D\Uint64('12'),'test','v1','owned'),
            $fixture->get('mime'),$fixture->get('rightsRef'),new D\UtcInstant($fixture->get('createdAt')));
        $this->rejects(fn() => $makeVersion(new D\TenantId(PortFixture::id(999)),$ref->sourceId,$ref->sourceDigest),'scope_mismatch',D\Outcome::Denied);
        $this->rejects(fn() => $makeVersion($ref->tenantId,new D\SourceId(PortFixture::id(999)),$ref->sourceDigest),'immutable_conflict');
        $this->rejects(fn() => $makeVersion($ref->tenantId,$ref->sourceId,new D\SourceDigest(str_repeat('d',64))),'immutable_conflict');
        $snapshot = new D\PublicationSnapshot(PortFixture::snapshot(),[new D\GenerationId(PortFixture::id(14))]);
        $exact = D\SnapshotSelector::exact($snapshot->ref); $exact->assertResolved($snapshot);
        self::assertSame($snapshot->ref,$exact->ref); self::assertNull(D\SnapshotSelector::active()->ref);
        $this->rejects(fn() => $exact->assertScope(new D\TenantId(PortFixture::id(999)),$snapshot->ref->kbId),'scope_mismatch',D\Outcome::Denied);
        $this->rejects(fn() => $exact->assertScope($snapshot->ref->tenantId,new D\KbId(PortFixture::id(999))),'scope_mismatch',D\Outcome::Denied);
        foreach ([
            new D\SnapshotRef($ref->tenantId,$snapshot->ref->kbId,new D\SnapshotId(PortFixture::id(111)),$snapshot->ref->manifestDigest),
            new D\SnapshotRef($ref->tenantId,$snapshot->ref->kbId,$snapshot->ref->snapshotId,new D\Fingerprint(str_repeat('d',64))),
        ] as $changed) {
            $this->rejects(fn() => $exact->assertResolved(new D\PublicationSnapshot($changed,$snapshot->generationIds)),'immutable_conflict');
        }
    }
}
