<?php

declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Query
{
    /**
     * @param list<SourceVersionId>|null $sourceVersionIds
     * @param list<CollectionId>|null $collectionIds
     */
    public function __construct(
        #[\SensitiveParameter] private string $text,
        public ?array $sourceVersionIds = null,
        public ?array $collectionIds = null,
    ) {
        if (strlen($text) > 4096) {
            throw new \InvalidArgumentException('Bounded UTF-8 query required');
        }
        ValueGuard::text(value: $text, max: 4096);
        if ($sourceVersionIds !== null) {
            ValueGuard::uniqueIds(ids: $sourceVersionIds, type: SourceVersionId::class);
        }
        if ($collectionIds !== null) {
            ValueGuard::uniqueIds(ids: $collectionIds, type: CollectionId::class);
        }
    }

    public function text(): string
    {
        return $this->text;
    }

    public function __debugInfo(): array
    {
        return ['byteLength' => strlen($this->text)];
    }
}
