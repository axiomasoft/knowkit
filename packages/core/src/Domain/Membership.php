<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Membership
{
    public function __construct(public TenantId $tenantId, public KbId $kbId, public CollectionId $collectionId, public SourceId $sourceId) {}
}
