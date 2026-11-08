<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\TenantId;

final readonly class HistoryDependency
{
    public function __construct(
        public TenantId $tenantId,
        public string $ref,
        public string $revision,
        public Sha256 $contentDigest,
    ) {
        Guard::reference($ref);
        Guard::reference($revision);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'tenantId' => $this->tenantId->value,
            'ref' => $this->ref,
            'revision' => $this->revision,
            'contentDigest' => $this->contentDigest->value,
        ]);
    }
}
