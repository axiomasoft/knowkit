<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{DocumentRevisionRef, Sha256, TextLocator, ValueGuard};
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Domain\Layers\ResolutionTier;

final readonly class PropositionSetArtifact
{
    public ResolutionTier $tier;

    /**
     * @param list<PropositionItem> $propositions
     * @param list<ProtectedAtom> $verbatimAtoms
     * @param list<TextLocator> $supportingSpans
     */
    public function __construct(
        public DocumentRevisionRef $documentRevisionRef,
        public array $propositions,
        public array $verbatimAtoms = [],
        public array $supportingSpans = [],
    ) {
        $this->tier = ResolutionTier::R2Propositions;
        ValueGuard::objects($propositions, PropositionItem::class, 8192);
        ValueGuard::objects($verbatimAtoms, ProtectedAtom::class, 4096);
        ValueGuard::objects($supportingSpans, TextLocator::class, 512);
    }

    /** @return list<ProtectedAtom> */
    public function allVerbatimAtoms(): array
    {
        $atoms = $this->verbatimAtoms;
        foreach ($this->propositions as $item) {
            foreach ($item->verbatimAtoms as $atom) {
                $atoms[] = $atom;
            }
        }
        return $atoms;
    }

    /** @return list<TextLocator> */
    public function allSupportingSpans(): array
    {
        $spans = $this->supportingSpans;
        foreach ($this->propositions as $item) {
            foreach ($item->supportingSpans as $span) {
                $spans[] = $span;
            }
        }
        return $spans;
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'schemaVersion' => 'proposition-set:1',
            'tier' => $this->tier->value,
            'documentRevisionRef' => $this->documentRevisionRef->toWire(),
            'propositions' => array_map(
                static fn(PropositionItem $item): JsonObject => $item->toWire(),
                $this->propositions,
            ),
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

    public function digest(): Sha256
    {
        return new Sha256(hash('sha256', Jcs::canonicalize($this->toWire())));
    }
}
