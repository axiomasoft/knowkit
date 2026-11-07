<?php
declare(strict_types=1);
namespace KnowKit\Tests\Security\Policy;

use KnowKit\Application\Catalog\RemoveKnowledgeBase;
use KnowKit\Domain as D;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('security:policy')]
final class OwnershipTest extends TestCase
{
    public function testKnowledgeBaseRemovalDeletesManagedInventoryAndOnlyDetachesExternal(): void
    {
        CatalogFixture::run(function(CatalogFixture $f) {
            $f->tenant(); $f->kb(); $source=$f->source(); $scope=$f->scope();
            $f->commands->addMembership($scope,new D\AddMembership($source->sourceId,null,new D\CatalogMetadata()),$f->context('member','0','0'));
            $f->resource(300,2,1,'owned'); $f->resource(301,2,1,'external');
            $scope=new D\Scope($scope->tenantId,$scope->kbId,$scope->actorId,$scope->purpose,new D\Decimal('1'),$scope->hostScopeHash);
            $remove=new RemoveKnowledgeBase($f->repo,$f->repo->removeKnowledgeBase(...),$f->tx,$f->authority);
            $tasks=$remove->remove($scope,$f->context('remove','1','1')); self::assertCount(1,$tasks);
            $inventory=json_decode($f->db->execute('SELECT required_resources_json FROM deletion_tasks')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            self::assertSame([CatalogFixture::id(300)],array_column($inventory,'resourceId'));
            self::assertSame('accepted',$f->db->execute("SELECT state FROM resource_ledger WHERE owner_kind='external'")->fetchColumn());
            self::assertSame(0,$f->count('source_memberships')); self::assertSame(0,$f->count('deletion_attempts'));
            self::assertSame('2',$f->repo->getKb($scope->tenantId,$scope->kbId)->policyEpoch->value);
        });
    }
}
