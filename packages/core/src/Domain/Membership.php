<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** Compatibility name; canonical catalog records may use SourceMembership. */
readonly class Membership
{
    final public function __construct(public TenantId $tenantId, public KbId $kbId, public ?CollectionId $collectionId,
        public SourceId $sourceId, public Decimal $revision, public CatalogMetadata $metadata) {}
}
