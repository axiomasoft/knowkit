<?php
declare(strict_types=1);

namespace KnowKit\Application\Artifacts;

use KnowKit\Domain\{Bbox, DomainFailure, IrDocument, LocalId, Outcome, TextLocator};
use KnowKit\Domain\Artifacts\{DigestArtifact, PropositionSetArtifact, ProtectedAtom};
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Port\AtomVerifier;

final readonly class AtomicSpanGuard
{
    private const MANDATORY_KINDS = ['formula', 'code', 'table'];

    /** @return list<ProtectedAtom> */
    public function extractRequiredAtoms(IrDocument $ir): array
    {
        $wire = $ir->toWire();
        $pageTexts = [];
        foreach ($wire->get('pages') as $page) {
            if ($page instanceof JsonObject) {
                $pageTexts[(string) $page->get('pageId')] = (string) $page->get('text');
            }
        }
        $segmentTexts = [];
        foreach ($wire->get('segments') as $segment) {
            if ($segment instanceof JsonObject) {
                $segmentTexts[(string) $segment->get('segmentId')] = (string) $segment->get('text');
            }
        }

        $atoms = [];
        foreach ($wire->get('blocks') as $block) {
            if (!$block instanceof JsonObject) {
                continue;
            }
            $kind = (string) $block->get('kind');
            $isMandatoryKind = in_array($kind, self::MANDATORY_KINDS, true);
            $isMetadataAtomic = false;
            if ($block->has('metadata')) {
                $meta = $block->get('metadata');
                if ($meta instanceof JsonObject && $meta->has('atomic') && $meta->get('atomic') === true) {
                    $isMetadataAtomic = true;
                }
            }
            if (!$isMandatoryKind && !$isMetadataAtomic) {
                continue;
            }

            $content = $this->extractAtomContent($block, $pageTexts, $segmentTexts);
            if ($content === '') {
                throw new DomainFailure(Outcome::Incompatible, 'atom_integrity_failed');
            }

            $firstLocator = null;
            foreach ($block->get('spans') as $span) {
                if ($span instanceof JsonObject) {
                    $firstLocator = $this->toLocator($span);
                    break;
                }
            }

            $atomKind = $isMandatoryKind ? $kind : 'atomic';
            $atoms[] = ProtectedAtom::fromContent(
                atomId: new LocalId((string) $block->get('blockId')),
                kind: $atomKind,
                content: $content,
                locator: $firstLocator,
            );
        }

        return $atoms;
    }

    public function assertPreserved(
        IrDocument $ir,
        DigestArtifact|PropositionSetArtifact $artifact,
        ?AtomVerifier $verifier = null,
    ): void {
        $required = $this->extractRequiredAtoms($ir);
        if ($required === []) {
            return;
        }

        $derivedAtoms = $artifact->allVerbatimAtoms();

        foreach ($required as $sourceAtom) {
            if ($this->isAtomPreserved($sourceAtom, $derivedAtoms, $verifier)) {
                continue;
            }
            throw new DomainFailure(Outcome::Incompatible, 'atom_integrity_failed');
        }
    }

    /**
     * @param list<ProtectedAtom> $derivedAtoms
     */
    private function isAtomPreserved(
        ProtectedAtom $sourceAtom,
        array $derivedAtoms,
        ?AtomVerifier $verifier,
    ): bool {
        foreach ($derivedAtoms as $derivedAtom) {
            if ($sourceAtom->equals($derivedAtom)) {
                return true;
            }
        }

        if ($verifier !== null) {
            foreach ($derivedAtoms as $derivedAtom) {
                if ($derivedAtom->kind === $sourceAtom->kind && $verifier->equivalent($sourceAtom, $derivedAtom)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $pageTexts
     * @param array<string, string> $segmentTexts
     */
    private function extractAtomContent(JsonObject $block, array $pageTexts, array $segmentTexts): string
    {
        $parts = [];
        foreach ($block->get('spans') as $span) {
            if (!$span instanceof JsonObject) {
                continue;
            }
            $kind = (string) $span->get('kind');
            $start = (int) $span->get('byteStart');
            $end = (int) $span->get('byteEnd');
            $unitText = $kind === 'page'
                ? ($pageTexts[(string) $span->get('pageId')] ?? '')
                : ($segmentTexts[(string) $span->get('segmentId')] ?? '');
            if ($unitText !== '' && $end > $start && $end <= strlen($unitText)) {
                $parts[] = substr($unitText, $start, $end - $start);
            }
        }
        $joined = implode('', $parts);
        if ((string) $block->get('kind') === 'table' && $block->has('table')) {
            $canonicalTable = Jcs::canonicalize($block->get('table'));
            return $joined !== '' ? $joined . "\n" . $canonicalTable : $canonicalTable;
        }
        return $joined;
    }

    private function toLocator(JsonObject $span): TextLocator
    {
        $kind = (string) $span->get('kind');
        if ($kind === 'page') {
            $bboxWire = $span->has('bbox') ? $span->get('bbox') : null;
            $bbox = $bboxWire instanceof JsonObject
                ? new Bbox(
                    (int) $bboxWire->get('x0'),
                    (int) $bboxWire->get('y0'),
                    (int) $bboxWire->get('x1'),
                    (int) $bboxWire->get('y1'),
                )
                : null;
            $printed = $span->get('printedLabel');
            return TextLocator::page(
                new LocalId((string) $span->get('pageId')),
                (int) $span->get('physicalNo'),
                is_string($printed) ? $printed : null,
                new LocalId((string) $span->get('blockId')),
                (int) $span->get('byteStart'),
                (int) $span->get('byteEnd'),
                $bbox,
            );
        }
        return TextLocator::segment(
            new LocalId((string) $span->get('segmentId')),
            new LocalId((string) $span->get('blockId')),
            (int) $span->get('byteStart'),
            (int) $span->get('byteEnd'),
        );
    }
}
