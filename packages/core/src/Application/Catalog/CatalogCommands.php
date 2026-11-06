<?php
declare(strict_types=1);

namespace KnowKit\Application\Catalog;

use KnowKit\Domain\{AddMembership,Collection,CreateCollection,CreateKB,KnowledgeBase,LockMode,MutationContext,Operation,ResourceRef,Scope,SourceMembership};
use KnowKit\Port\{CatalogRepository,CatalogTransaction};

/** Current owner callbacks precede SQL; the repository consumes each receipt once under guards. */
final readonly class CatalogCommands
{
    public function __construct(private CatalogRepository $catalog,private CatalogTransaction $transaction,private MutationAuthorizer $authority) {}
    public function createKnowledgeBase(Scope $scope,CreateKB $command,MutationContext $context): KnowledgeBase
    {
        $this->authority->prepare($scope,new ResourceRef($scope->tenantId,'knowledge_base',$command->kbId),Operation::CatalogCreate);
        return $this->transaction->run(function() use($scope,$command,$context) {
            $this->transaction->lockTenant($scope->tenantId,LockMode::Update);
            return $this->catalog->createKnowledgeBase($scope,$command,$context);
        });
    }
    public function createCollection(Scope $scope,CreateCollection $command,MutationContext $context): Collection
    {
        $this->authority->prepare($scope,new ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId),Operation::CollectionCreate);
        if ($command->parentId!==null) { $this->authority->prepare($scope,new ResourceRef($scope->tenantId,'collection',$command->parentId),Operation::CollectionCreate); }
        return $this->transaction->run(function() use($scope,$command,$context) {
            $this->transaction->lockTenant($scope->tenantId,LockMode::Update); $this->transaction->lockKb($scope->kbId,LockMode::Update);
            return $this->catalog->createCollection($scope,$command,$context);
        });
    }
    public function addMembership(Scope $scope,AddMembership $command,MutationContext $context): SourceMembership
    {
        $this->authority->prepare($scope,new ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId),Operation::MembershipMutate);
        if ($command->collectionId!==null) { $this->authority->prepare($scope,new ResourceRef($scope->tenantId,'collection',$command->collectionId),Operation::MembershipMutate); }
        $this->authority->prepare($scope,new ResourceRef($scope->tenantId,'source',$command->sourceId),Operation::MembershipMutate);
        return $this->transaction->run(function() use($scope,$command,$context) {
            $this->transaction->lockTenant($scope->tenantId,LockMode::Update); $this->transaction->lockKb($scope->kbId,LockMode::Update);
            $this->transaction->lockSources([$command->sourceId],LockMode::Update);
            return $this->catalog->addMembership($scope,$command,$context);
        });
    }
}
