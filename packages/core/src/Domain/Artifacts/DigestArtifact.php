<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{DocumentRevisionRef, Sha256, TextLocator, ValueGuard};
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Domain\Layers\ResolutionTier;

final readonly class DigestArtifact
{
    public ResolutionTier $tier;

    /**
     * @param list<DigestBlock> $blocks
     * @param list<ProtectedAtom> $verbatimAtoms
     * @param list<TextLocator> $supportingSpans
     */
    public function __construct(
        public DocumentRevisionRef $documentRevisionRef,
        public array $blocks,
        public array $verbatimAtoms = [],
        public array $supportingSpans = [],
    ) {
        $this->tier = ResolutionTier::R1Digest;
        ValueGuard::objects($blocks, DigestBlock::class, 4096);
        ValueGuard::objects($verbatimAtoms, ProtectedAtom::class, 4096);
        ValueGuard::objects($supportingSpans, TextLocator::class, 512);
    }

    /** @return list<ProtectedAtom> */
    public function allVerbatimAtoms(): array
    {
        $atoms = $this->verbatimAtoms;
        foreach ($this->blocks as $block) {
            foreach ($block->verbatimAtoms as $atom) {
                $atoms[] = $atom;
            }
        }
        return $atoms;
    }

    /** @return list<TextLocator> */
    public function allSupportingSpans(): array
    {
        $spans = $this->supportingSpans;
        foreach ($this->blocks as $block) {
            foreach ($block->supportingSpans as $span) {
                $spans[] = $span;
            }
        }
        return $spans;
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'schemaVersion' => 'digest-artifact:1',
            'tier' => $this->tier->value,
            'documentRevisionRef' => $this->documentRevisionRef->toWire(),
            'blocks' => array_map(
                static fn(DigestBlock $block): JsonObject => $block->toWire(),
                $this->blocks,
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
