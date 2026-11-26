<?php
declare(strict_types=1);

namespace KnowKit\Tests\Contract\Ports;

use KnowKit\Application as A;
use KnowKit\Domain as D;
use KnowKit\Port as P;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('contract:ports')]
final class PortContractsTest extends TestCase
{
    private function signature(string $class, string $method, array $parameters, string $return): void
    {
        $reflection = new \ReflectionMethod($class,$method);
        self::assertSame($parameters,array_map(static fn(\ReflectionParameter $p): string => (string) $p->getType(),$reflection->getParameters()),$class . '::' . $method);
        self::assertSame($return,(string) $reflection->getReturnType());
    }

    public function testApprovedPolicyAndDisclosureSignatures(): void
    {
        $this->signature(P\AccessPolicy::class,'authorizeQuery',[D\Scope::class],D\QueryGrant::class);
        $this->signature(P\AccessPolicy::class,'decide',[D\Scope::class,D\ResourceRef::class,D\Operation::class],D\DecisionReceipt::class);
        $this->signature(P\EgressPolicy::class,'mayEgress',[D\Scope::class,D\EgressRequest::class],D\DecisionReceipt::class);
        $this->signature(A\Disclosure::class,'disclose',[D\Scope::class,D\DisclosureRequest::class],D\DisclosureOutcome::class);
        $this->signature(P\EmbeddingProvider::class,'capabilities',[],D\EmbeddingCapabilities::class);
        $this->signature(P\EmbeddingProvider::class,'embedDocuments',[D\EmbeddingAccess::class,'array',D\SpaceRef::class],D\Result::class);
        $this->signature(P\EmbeddingProvider::class,'embedQuery',[D\EmbeddingAccess::class,D\QueryInput::class,D\SpaceRef::class],D\Result::class);
        $this->signature(P\Clock::class,'now',[],D\UtcInstant::class);
        $this->signature(P\BlobStore::class,'listUnreferenced',[D\UtcInstant::class,D\Cursor::class],D\ResourcePage::class);
    }

    public function testAllPublicTypesResolveAndCatalogBoundariesAreScoped(): void
    {
        foreach (['Port','Application','Registry'] as $layer) {
            foreach (glob(PortFixture::root() . '/packages/core/src/' . $layer . '/*.php') as $file) {
                $class = 'KnowKit\\' . $layer . '\\' . basename($file,'.php');
                $reflection = new \ReflectionClass($class);
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->isConstructor()) { continue; }
                    foreach (array_merge(array_map(static fn($p) => $p->getType(),$method->getParameters()),[$method->getReturnType()]) as $type) {
                        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
                        foreach ($types as $named) {
                            self::assertInstanceOf(\ReflectionNamedType::class,$named,$class . '::' . $method->getName());
                            if (!$named->isBuiltin() && $named->getName() !== 'self') {
                                self::assertTrue(class_exists($named->getName()) || interface_exists($named->getName()),$named->getName());
                            }
                        }
                    }
                }
            }
        }
        foreach ((new \ReflectionClass(P\CatalogRepository::class))->getMethods() as $method) {
            $expected = in_array($method->getName(),['createKnowledgeBase','createCollection','addMembership'],true)
                ? D\Scope::class : D\TenantId::class;
            self::assertSame($expected,(string) $method->getParameters()[0]->getType(),$method->getName());
        }
        self::assertSame(['append'],array_map(static fn($m): string => $m->getName(),(new \ReflectionClass(P\Outbox::class))->getMethods()));
        self::assertSame(['leaseDelivery','ack'],array_map(static fn($m): string => $m->getName(),(new \ReflectionClass(P\OutboxDelivery::class))->getMethods()));
    }

    public function testApprovedCatalogSignaturesRetainLegacyEntryPoints(): void
    {
        foreach ([
            ['createKnowledgeBase',[D\Scope::class,D\CreateKB::class,D\MutationContext::class],D\KnowledgeBase::class],
            ['createCollection',[D\Scope::class,D\CreateCollection::class,D\MutationContext::class],D\Collection::class],
            ['addMembership',[D\Scope::class,D\AddMembership::class,D\MutationContext::class],D\SourceMembership::class],
            ['getTenant',[D\TenantId::class],'?' . D\Tenant::class],
            ['findKnowledgeBase',[D\TenantId::class,D\KbId::class],'?' . D\KnowledgeBase::class],
            ['getCollection',[D\TenantId::class,D\KbId::class,D\CollectionId::class],'?' . D\Collection::class],
            ['listSources',[D\TenantId::class,D\KbId::class,D\CollectionFilter::class,D\PageCursor::class],D\SourcePage::class],
            ['getVersion',[D\TenantId::class,D\SourceVersionRef::class],'?' . D\SourceVersion::class],
            ['resolveSnapshot',[D\TenantId::class,D\KbId::class,D\SnapshotSelector::class,D\ProfileId::class],'?' . D\PublicationSnapshot::class],
            ['getKb',[D\TenantId::class,D\KbId::class],'?' . D\KnowledgeBase::class],
            ['getSource',[D\TenantId::class,D\SourceId::class],'?' . D\Source::class],
            ['getSourceVersion',[D\TenantId::class,D\SourceVersionId::class],'?' . D\SourceVersion::class],
            ['listMemberships',[D\TenantId::class,D\SourceId::class],'array'],
            ['insertTenant',[D\TenantId::class,D\Tenant::class],'void'],
            ['insertCollection',[D\TenantId::class,D\Collection::class],'void'],
            ['insertKb',[D\TenantId::class,D\KnowledgeBase::class],'void'],
            ['insertSource',[D\TenantId::class,D\Source::class],'void'],
            ['insertSourceVersion',[D\TenantId::class,D\SourceVersion::class],'void'],
            ['insertMembership',[D\TenantId::class,D\Membership::class],'void'],
        ] as [$name,$parameters,$return]) {
            $this->signature(P\CatalogRepository::class,$name,$parameters,$return);
            foreach ((new \ReflectionMethod(P\CatalogRepository::class,$name))->getParameters() as $parameter) {
                self::assertFalse($parameter->isOptional(),'No implicit tenant, selector, profile or mutation context');
            }
        }
    }

    public function testCoreRemainsVendorFreeAndHasNoAnswerOrFrameworkImports(): void
    {
        $result = (new Qa(PortFixture::root()))->dag();
        self::assertGreaterThan(100,$result['phpFiles']);
        self::assertGreaterThanOrEqual(1,$result['packages']);
        foreach (glob(PortFixture::root() . '/packages/core/src/Application/*.php') as $path) {
            $symbol = 'KnowKit\\Application\\' . basename($path,'.php');
            self::assertTrue(interface_exists($symbol) || class_exists($symbol));
        }
    }

    public function testSchemaBoundDtoRoundTripsAndFreezesItsInput(): void
    {
        foreach ([
            [D\IrDocument::class,'document-ir.segments'],
            [D\QueryGrant::class,'query-grant.valid'],
            [D\DecisionReceipt::class,'decision-receipt.valid'],
            [D\EgressRequest::class,'egress-request.model-context'],
            [D\DisclosureRequest::class,'disclosure-request.direct'],
            [D\Selection::class,'selection.valid'],
            [D\EvidenceSet::class,'evidence-set.valid'],
            [D\Usage::class,'usage.valid'],
            [D\IndexManifest::class,'index-manifest.valid'],
            [D\DomainEvent::class,'domain-event.usage-provider-accepted'],
            [D\Job::class,'job.leased'],
            [D\JobEnvelope::class,'job-envelope.valid'],
            [D\WorkerResult::class,'worker-result.valid'],
            [D\PublishReceipt::class,'publish-receipt.valid'],
            [D\RestoreReceipt::class,'restore-receipt.blocked'],
            [D\DeletionReceipt::class,'deletion-attempt.unknown'],
            [D\MutationContext::class,'mutation-context.valid'],
            [D\CitationRef::class,'citation-ref.multi-span'],
        ] as [$type,$name]) {
            $wire = PortFixture::json('tests/fixtures/contracts/v1/valid/' . $name . '.json');
            $value = new $type($wire,PortFixture::validator());
            self::assertSame(D\Contract\Jcs::canonicalize($wire),$value->canonical());
            $wire->with('untrusted','changed');
            self::assertFalse($value->toWire()->has('untrusted'));
            try { new $type($wire->with('untrusted',true),PortFixture::validator()); self::fail('Unknown wire member accepted'); }
            catch (D\Contract\ContractViolation $violation) { self::assertSame('unknown_field', $violation->errorCode); }
        }
    }

    public function testAllOutcomeSemanticsRemainDistinct(): void
    {
        foreach ([D\Outcome::Denied,D\Outcome::Unknown,D\Outcome::Unavailable,D\Outcome::Incompatible] as $outcome) {
            $result = D\Result::failure(new D\DomainFailure($outcome,'safe_code'));
            self::assertSame($outcome,$result->outcome); self::assertNull($result->data); self::assertFalse($result->degraded);
        }
        self::assertSame(D\Outcome::Empty,D\Result::empty()->outcome);
        self::assertSame(D\Outcome::Success,D\Result::success(new D\Limit(1))->outcome);
        $this->expectException(\InvalidArgumentException::class);
        D\Result::success(new D\Limit(1),true);
    }

    public function testManifestCoversEveryExecutedPortsTest(): void
    {
        foreach (['IdentityTest','RegistryTest','BoundaryTest','CatalogTest'] as $class) { require_once __DIR__ . '/' . $class . '.php'; }
        $manifest = json_decode((string) file_get_contents(PortFixture::root() . '/tests/fixtures/check-groups/contract-ports.json'),true,512,JSON_THROW_ON_ERROR);
        foreach (['R05-A01','R05-A02','PORT-REGISTRY-TYPO','PORT-UNKNOWN-CAPABILITY','PORT-REFLECTION','PORT-NO-ANSWER',
            'PORT-CATALOG-RECORDS','PORT-CATALOG-SIGNATURES','PORT-CATALOG-IDEMPOTENCY','PORT-CATALOG-ANCESTRY',
            'PORT-CATALOG-PAGINATION','PORT-CATALOG-EXACT-REFS','PORT-CATALOG-METADATA'] as $id) {
            self::assertContains($id,$manifest['requiredCaseIds']);
        }
        $mapped = array_merge(...array_values($manifest['testCaseMap']));
        foreach ([IdentityTest::class,RegistryTest::class,self::class,BoundaryTest::class,CatalogTest::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (str_starts_with($method->getName(),'test')) {
                    self::assertContains($class . '::' . $method->getName(),$mapped);
                }
            }
        }
        (new Qa(PortFixture::root()))->fixtures($manifest);
    }
}
