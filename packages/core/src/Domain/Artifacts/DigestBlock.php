<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{LocalId, TextLocator, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

final readonly class DigestBlock
{
    /**
     * @param list<ProtectedAtom> $verbatimAtoms
     * @param list<TextLocator> $supportingSpans
     */
    public function __construct(
        public LocalId $blockId,
        public string $text,
        public array $verbatimAtoms = [],
        public array $supportingSpans = [],
    ) {
        if (trim($text) === '' || preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('Nonempty UTF-8 digest block text required');
        }
        ValueGuard::objects($verbatimAtoms, ProtectedAtom::class, 1024);
        ValueGuard::objects($supportingSpans, TextLocator::class, 512);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'blockId' => $this->blockId->value,
            'text' => $this->text,
            'verbatimAtoms' => array_map(
                static fn(ProtectedAtom $atom): JsonObject => $atom->toWire(),
                $this->verbatimAtoms,
            ),
            'supportingSpans' => array_map(
                static fn(TextLocator $locator): JsonObject => $locator->toWire(),
                $this->supportingSpans,
            ),
        ]);
    }
}
