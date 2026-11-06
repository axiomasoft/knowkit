<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\JsonObject;

final readonly class AddMembership
{
    public function __construct(public SourceId $sourceId, public ?CollectionId $collectionId, public CatalogMetadata $metadata) {}

    public function requestDigest(Scope $access, MutationContext $context): Fingerprint
    {
        return $context->requestDigest(Operation::MembershipMutate, new JsonObject([
            'tenantId'=>$access->tenantId->value, 'kbId'=>$access->kbId->value,
            'sourceId'=>$this->sourceId->value, 'collectionId'=>$this->collectionId?->value, 'metadata'=>$this->metadata->toWire(),
        ]));
    }
}
