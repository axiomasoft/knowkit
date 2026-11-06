<?php
declare(strict_types=1);

namespace KnowKit\Domain;

/** null selects all memberships in the KB, including memberships without a collection. */
final readonly class CollectionFilter
{
    public function __construct(public ?CollectionId $collectionId = null) {}
}
