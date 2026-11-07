<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Documents;

use KnowKit\Domain\{LocalId, ResourceId, Ulid};

final readonly class AssetStorage
{
    public function __construct(public Ulid $id, public ResourceId $resourceId, public string $origin,
        public ?LocalId $parentBlockId)
    {
        if (!in_array($origin, ['source', 'ocr', 'generated'], true)) { throw new \InvalidArgumentException('Explicit asset origin required'); }
    }
}
