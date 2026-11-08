<?php

declare(strict_types=1);

namespace KnowKit\Answer\Domain;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\SourceVersionRef;

/** Host classifies content; no protected plaintext is retained in the manifest. */
final readonly class EgressContentItem
{
    /** @param list<SourceVersionRef> $sources */
    public function __construct(
        public EgressCategory $category,
        public Sha256 $contentDigest,
        public array $sources = [],
    ) {
        Guard::objects($sources, SourceVersionRef::class, 256);
        if ($category === EgressCategory::Evidence && $sources === []) {
            throw new \InvalidArgumentException('Evidence requires exact source versions');
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'category' => $this->category->value,
            'contentDigest' => $this->contentDigest->value,
            'sourceVersionRefs' => array_map(static fn (SourceVersionRef $source) => $source->toWire(), $this->sources),
        ]);
    }
}
