<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class CreateCollection
{
    public function __construct(public CollectionId $collectionId, public ?CollectionId $parentId, public CatalogMetadata $metadata)
    {
        if ($parentId?->equals($collectionId)) { throw new DomainFailure(Outcome::Incompatible, 'collection_cycle'); }
    }

    public function requestDigest(Scope $access, MutationContext $context): Fingerprint
    {
        return $context->requestDigest(Operation::CollectionCreate, new JsonObject([
            'tenantId'=>$access->tenantId->value, 'kbId'=>$access->kbId->value,
            'collectionId'=>$this->collectionId->value, 'parentId'=>$this->parentId?->value, 'metadata'=>$this->metadata->toWire(),
        ]));
    }
}
