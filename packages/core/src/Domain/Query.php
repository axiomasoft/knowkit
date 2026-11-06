<?php
declare(strict_types=1);

namespace KnowKit\Domain;

final readonly class Query
{
    /**
     * @param list<SourceVersionId>|null $sourceVersionIds
     * @param list<CollectionId>|null $collectionIds
     */
    public function __construct(private string $text, public ?array $sourceVersionIds = null, public ?array $collectionIds = null)
    {
        ValueGuard::text($text, 16384);
        if ($sourceVersionIds !== null) { ValueGuard::uniqueIds($sourceVersionIds, SourceVersionId::class); }
        if ($collectionIds !== null) { ValueGuard::uniqueIds($collectionIds, CollectionId::class); }
    }
    public function text(): string { return $this->text; }
    public function __debugInfo(): array { return ['byteLength' => strlen($this->text)]; }
}
