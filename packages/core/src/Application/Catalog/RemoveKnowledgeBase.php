<?php
declare(strict_types=1);

namespace KnowKit\Application\Catalog;

use KnowKit\Domain\{CollectionFilter, LockMode, MutationContext, Operation, PageCursor, ResourceRef, Scope, Sha256};
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Port\{CatalogRepository, CatalogTransaction};

final readonly class RemoveKnowledgeBase
{
    public function __construct(
        private CatalogRepository $catalog,
        private \Closure $removeMetadata,
        private CatalogTransaction $transaction,
        private MutationAuthorizer $authority,
    ) {
    }

    /** @return array<array-key, mixed> */
    public function remove(Scope $scope, MutationContext $context): array
    {
        $this->authority->prepare(
            $scope,
            new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId),
            Operation::LifecycleDelete,
        );
        $page = new PageCursor(
            $scope->tenantId,
            $scope->kbId,
            new CollectionFilter(),
            new Sha256(hash('sha256', Jcs::canonicalize($scope->toWire()))),
        );
        $byId = [];
        do {
            $result = $this->catalog->listSources($scope->tenantId, $scope->kbId, $page->filter, $page);
            foreach ($result->sources as $source) {
                $byId[$source->sourceId->value] = $source->sourceId;
                $this->authority->prepare(
                    $scope,
                    new ResourceRef($scope->tenantId, 'source', $source->sourceId),
                    Operation::LifecycleDelete,
                );
            }
            $page = $result->next;
        } while ($page !== null);
        ksort($byId, SORT_STRING);
        $ids = array_values($byId);
        return $this->transaction->run(function () use ($scope, $context, $ids): array {
            $this->transaction->lockTenant($scope->tenantId, LockMode::Update);
            $this->transaction->lockKb($scope->kbId, LockMode::Update);
            $this->transaction->lockSources($ids, LockMode::Update);
            return ($this->removeMetadata)($scope, $context);
        });
    }
}
