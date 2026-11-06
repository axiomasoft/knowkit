<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Collection
{
    public function __construct(public TenantId $tenantId, public KbId $kbId, public CollectionId $collectionId,
        public ?CollectionId $parentId, public Decimal $revision, public CatalogMetadata $metadata)
    {
        if ($parentId?->equals($collectionId)) { throw new DomainFailure(Outcome::Incompatible, 'collection_cycle'); }
    }

    /** @param list<Collection> $ancestors authoritative direct-parent-to-root chain, loaded under locks */
    public function assertAncestry(array $ancestors): void
    {
        ValueGuard::objects($ancestors, self::class);
        $expected = $this->parentId; $seen = [$this->collectionId->value=>true];
        foreach ($ancestors as $ancestor) {
            if (!$this->tenantId->equals($ancestor->tenantId) || !$this->kbId->equals($ancestor->kbId)) {
                throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
            }
            if (isset($seen[$ancestor->collectionId->value])) { throw new DomainFailure(Outcome::Incompatible, 'collection_cycle'); }
            if ($expected === null || !$expected->equals($ancestor->collectionId)) {
                throw new DomainFailure(Outcome::Incompatible, 'collection_parent_mismatch');
            }
            $seen[$ancestor->collectionId->value] = true; $expected = $ancestor->parentId;
        }
        if ($expected !== null) {
            throw new DomainFailure(Outcome::Incompatible, isset($seen[$expected->value]) ? 'collection_cycle' : 'collection_parent_missing');
        }
    }
}
