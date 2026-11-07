<?php

declare(strict_types=1);

namespace KnowKit\Domain\Reading;

use KnowKit\Domain as D;

/** Exact catalog operand, never a public object URL. */
final readonly class BlobObject
{
    public function __construct(
        public D\ResourceId $resourceId,
        public D\BlobRef $blob,
        public string $mediaType,
    ) {}
}
