<?php

declare(strict_types=1);

namespace KnowKit\Domain\Navigation;

use KnowKit\Domain as D;

final readonly class NavigationPlan
{
    public function __construct(
        public D\DocumentId $documentId,
        public D\DisclosurePlan $guards,
        public D\FrozenJson $coverage,
    ) {}

    public function requireSame(self $current): void
    {
        if ($this->documentId != $current->documentId) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }
        if ($this->coverage->canonical() !== $current->coverage->canonical()) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }
        $this->guards->requireSame($current->guards);
    }
}
