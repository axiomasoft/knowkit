<?php
declare(strict_types=1);
namespace KnowKit\Application\Catalog;
use KnowKit\Domain\{LockMode,MutationContext,Operation,RegisterSource,ResourceRef,Scope,Source,SourceVersion};
use KnowKit\Port\CatalogTransaction;

final readonly class SourceCommands
{
    public function __construct(private \Closure $registerMetadata,private \Closure $acceptMetadata,
        private CatalogTransaction $transaction,private MutationAuthorizer $authority) {}
    public function register(Scope $scope,RegisterSource $command,MutationContext $context): Source
    {
        $this->authority->prepare($scope,new ResourceRef($scope->tenantId,'source',$command->sourceId),Operation::SourceRegister);
        return $this->transaction->run(function() use($scope,$command,$context) {
            $this->transaction->lockTenant($scope->tenantId,LockMode::Update);
            return ($this->registerMetadata)($scope,$command,$context);
        });
    }
    public function acceptVersion(Scope $scope,SourceVersion $version,MutationContext $context): SourceVersion
    {
        $scope->assertTenant($version->ref->tenantId);
        $this->authority->prepare($scope,new ResourceRef($scope->tenantId,'source',$version->ref->sourceId),Operation::SourceVersionAdd);
        return $this->transaction->run(function() use($scope,$version,$context) {
            $this->transaction->lockTenant($scope->tenantId,LockMode::Update);
            $this->transaction->lockSources([$version->ref->sourceId],LockMode::Update);
            return ($this->acceptMetadata)($scope,$version,$context);
        });
    }
}
