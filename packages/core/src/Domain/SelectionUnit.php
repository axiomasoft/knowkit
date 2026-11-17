<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Private canonical unit, including headings/headers/units/notes in its budget text. */
final readonly class SelectionUnit
{
    /** @param list<string> $assetKeys */
    public function __construct(
        public Chunk $chunk,
        private string $budgetText,
        public bool $table,
        public array $assetKeys = [],
    ) {
        if (preg_match('//u', $budgetText) !== 1 || !str_contains($budgetText, $chunk->text())) {
            throw new DomainFailure(Outcome::Incompatible, 'source_text_mismatch');
        }
        ValueGuard::objects($chunk->spans, Span::class);
        foreach ($assetKeys as $key) {
            ValueGuard::text($key, 512);
        }
    }

    public function budgetText(): string
    {
        return $this->budgetText;
    }

    public function overlaps(self $other): bool
    {
        if (!$this->chunk->ref->revisionRef->equals($other->chunk->ref->revisionRef)
            || !$this->chunk->documentId->equals($other->chunk->documentId)) {
            return false;
        }
        foreach ($this->chunk->spans as $left) {
            $a = $left->locator->toWire();
            foreach ($other->chunk->spans as $right) {
                $b = $right->locator->toWire();
                $key = $a->get('kind') === 'page' ? 'pageId' : 'segmentId';
                if ($a->get('kind') === $b->get('kind') && $a->get($key) === $b->get($key)
                    && $left->textDigest->equals($right->textDigest)
                    && $a->get('byteStart') < $b->get('byteEnd') && $b->get('byteStart') < $a->get('byteEnd')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function __debugInfo(): array
    {
        return ['ref' => $this->chunk->ref];
    }
}
