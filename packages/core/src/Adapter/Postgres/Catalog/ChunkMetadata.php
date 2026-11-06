<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Catalog;
use KnowKit\Domain\ResourceId;
use KnowKit\Domain\Contract\JsonObject;

/** Validated by the owning chunker; an exact ChunkRef alone does not supply provenance or a storage locator. */
final readonly class ChunkMetadata
{
    public function __construct(public ResourceId $canonicalResourceId,public JsonObject $provenance,public array $structurePath)
    {
        if (!in_array($provenance->get('kind'),['verbatim','table_representation','description','summary'],true)) {
            throw new \InvalidArgumentException('Explicit provenance required');
        }
    }
}
