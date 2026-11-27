<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{LocalId, TextLocator, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

final readonly class PropositionItem
{
    /**
     * @param list<ProtectedAtom> $verbatimAtoms
     * @param list<TextLocator> $supportingSpans
     */
    public function __construct(
        public LocalId $propositionId,
        public string $statement,
        public array $verbatimAtoms = [],
        public array $supportingSpans = [],
    ) {
        if (trim($statement) === '' || preg_match('//u', $statement) !== 1) {
            throw new \InvalidArgumentException('Nonempty UTF-8 proposition statement required');
        }
        ValueGuard::objects($verbatimAtoms, ProtectedAtom::class, 1024);
        ValueGuard::objects($supportingSpans, TextLocator::class, 512);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'propositionId' => $this->propositionId->value,
            'statement' => $this->statement,
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
