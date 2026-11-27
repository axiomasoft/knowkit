<?php

declare(strict_types=1);

namespace KnowKit\Distill\Application;

use KnowKit\Application\Access\{AccessContext, PolicyChecks};
use KnowKit\Application\Artifacts\AtomicSpanGuard;
use KnowKit\Distill\Domain\{DistillJobKinds, DistillProfile, DistillRequest};
use KnowKit\Distill\Port\DistillModelTransport;
use KnowKit\Domain\Artifacts\{DigestArtifact, DigestBlock, ProtectedAtom};
use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject, StrictJson};
use KnowKit\Domain\{DocumentRevisionRef, DomainFailure, IrDocument, LocalId, Outcome, ResourceRef, Scope, TextLocator};

/**
 * Distills R1 digest while protecting formula/code/table/atomic spans with collision-free
 * @@KK_ATOM_<nonce>_<index>@@ placeholders and verifying byte-exact preservation via AtomicSpanGuard.
 */
final readonly class R1DigestDistiller
{
    public const SMALL_DOCUMENT_FLOOR_BYTES = 4096;

    public function __construct(
        private PolicyChecks $policy,
        private ContractValidator $validator,
        private DistillModelTransport $transport,
        private AtomicSpanGuard $guard = new AtomicSpanGuard(),
    ) {
    }

    /**
     * @param \Closure(Scope, \KnowKit\Domain\EgressRequest): JsonObject $currentBinding
     * @param list<ResourceRef> $collections
     */
    public function distill(
        AccessContext $context,
        IrDocument $ir,
        DocumentRevisionRef $revisionRef,
        DistillProfile $profile,
        \Closure $currentBinding,
        array $collections = [],
    ): DigestArtifact {
        $atoms = $this->guard->extractRequiredAtoms($ir);
        $extracted = DistillationOrchestrator::extractIrBlocks($ir);
        $nonce = self::selectCollisionFreeNonce($revisionRef, $atoms, $extracted['corpusText']);

        /** @var array<string, array{index: int, marker: string, atom: ProtectedAtom}> $atomByBlockId */
        $atomByBlockId = [];
        /** @var list<array{marker: string, atom: ProtectedAtom}> $indexedAtoms */
        $indexedAtoms = [];
        foreach ($atoms as $index => $atom) {
            $marker = self::markerFor($nonce, $index);
            $atomByBlockId[$atom->atomId->value] = [
                'index' => $index,
                'marker' => $marker,
                'atom' => $atom,
            ];
            $indexedAtoms[] = ['marker' => $marker, 'atom' => $atom];
        }

        $maskedBlocks = [];
        /** @var array<string, list<TextLocator>> $blockLocators */
        $blockLocators = [];
        $sourceProseBytes = 0;
        foreach ($extracted['blocks'] as $block) {
            $blockId = $block['blockId'];
            $blockLocators[$blockId] = $block['locators'];
            $isAtom = isset($atomByBlockId[$blockId]);
            if (!$isAtom) {
                $sourceProseBytes += strlen($block['text']);
            }
            $text = $isAtom ? $atomByBlockId[$blockId]['marker'] : $block['text'];
            $maskedBlocks[] = new JsonObject([
                'blockId' => $blockId,
                'kind' => $block['kind'],
                'text' => $text,
            ]);
        }

        $promptPayload = new JsonObject([
            'job' => DistillJobKinds::DISTILL_R1_DIGEST,
            'blocks' => $maskedBlocks,
        ]);
        $request = new DistillRequest(
            DistillJobKinds::r1Digest(),
            $profile,
            $revisionRef,
            Jcs::canonicalize($promptPayload),
        );

        $output = DistillationOrchestrator::transmitGated(
            $this->policy,
            $this->validator,
            $this->transport,
            $context,
            $request,
            $currentBinding,
            $collections,
        );

        $raw = $output->assertUsable($profile->maxOutputBytes);
        $decoded = StrictJson::decode($raw, $profile->maxOutputBytes);
        if (!$decoded instanceof JsonObject || !is_array($decoded->get('blocks'))) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_r1_digest_output');
        }

        $rawBlocks = $decoded->get('blocks');
        $combinedTexts = [];
        foreach ($rawBlocks as $rawBlock) {
            if (!$rawBlock instanceof JsonObject || !is_string($rawBlock->get('blockId')) || !is_string($rawBlock->get('text'))) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_r1_digest_output');
            }
            $combinedTexts[] = $rawBlock->get('text');
        }
        self::assertExactMarkers($nonce, $indexedAtoms, implode("\n", $combinedTexts));

        $digestBlocks = [];
        $nonAtomOutputBytes = 0;
        foreach ($rawBlocks as $rawBlock) {
            /** @var JsonObject $rawBlock */
            $blockId = (string) $rawBlock->get('blockId');
            $restored = self::restoreMarkers((string) $rawBlock->get('text'), $indexedAtoms);
            $atomBytes = 0;
            foreach ($restored['atoms'] as $atom) {
                $atomBytes += strlen($atom->content);
            }
            $nonAtomOutputBytes += max(0, strlen($restored['text']) - $atomBytes);
            $digestBlocks[] = new DigestBlock(
                blockId: new LocalId($blockId),
                text: $restored['text'],
                verbatimAtoms: $restored['atoms'],
                supportingSpans: $blockLocators[$blockId] ?? [],
            );
        }

        self::assertWithinRatioCeiling(
            $nonAtomOutputBytes,
            $sourceProseBytes,
            $profile->r1MaxRatioBps,
        );

        $artifact = new DigestArtifact(
            documentRevisionRef: $revisionRef,
            blocks: $digestBlocks,
        );
        $this->guard->assertPreserved($ir, $artifact);

        return $artifact;
    }

    /** @param list<ProtectedAtom> $atoms */
    public static function selectCollisionFreeNonce(
        DocumentRevisionRef $revisionRef,
        array $atoms,
        string $corpusText,
    ): string {
        $digestHex = $revisionRef->irDigest->value;
        $haystack = $corpusText;
        foreach ($atoms as $atom) {
            $haystack .= "\n" . $atom->content;
        }

        foreach ([8, 12, 16, 24, 32, 64] as $length) {
            $candidate = substr($digestHex, 0, $length);
            $prefix = '@@KK_ATOM_' . $candidate . '_';
            if (!str_contains($haystack, $prefix)) {
                return $candidate;
            }
        }

        throw new DomainFailure(Outcome::Incompatible, 'atom_placeholder_collision');
    }

    public static function markerFor(string $nonce, int $index): string
    {
        return '@@KK_ATOM_' . $nonce . '_' . $index . '@@';
    }

    /** @param list<array{marker: string, atom: ProtectedAtom}> $indexedAtoms */
    public static function assertExactMarkers(string $nonce, array $indexedAtoms, string $combinedText): void
    {
        foreach ($indexedAtoms as $entry) {
            if (substr_count($combinedText, $entry['marker']) !== 1) {
                throw new DomainFailure(Outcome::Incompatible, 'atom_integrity_failed');
            }
        }

        $pattern = '/@@KK_ATOM_' . preg_quote($nonce, '/') . '_[0-9]+@@/';
        $matches = [];
        $count = preg_match_all($pattern, $combinedText, $matches);
        if ($count === false || $count !== count($indexedAtoms)) {
            throw new DomainFailure(Outcome::Incompatible, 'atom_integrity_failed');
        }
    }

    /**
     * @param list<array{marker: string, atom: ProtectedAtom}> $indexedAtoms
     * @return array{text: string, atoms: list<ProtectedAtom>}
     */
    public static function restoreMarkers(string $text, array $indexedAtoms): array
    {
        $replacements = [];
        $usedAtoms = [];
        foreach ($indexedAtoms as $entry) {
            if (str_contains($text, $entry['marker'])) {
                $replacements[$entry['marker']] = $entry['atom']->content;
                $usedAtoms[] = $entry['atom'];
            }
        }
        // str_replace would rescan inserted atom text and can mint the next marker.
        if ($replacements !== []) {
            $text = strtr($text, $replacements);
        }

        return ['text' => $text, 'atoms' => $usedAtoms];
    }

    public static function assertWithinRatioCeiling(
        int $nonAtomOutputBytes,
        int $sourceProseBytes,
        int $maxRatioBps,
    ): void {
        if ($sourceProseBytes <= self::SMALL_DOCUMENT_FLOOR_BYTES) {
            return;
        }
        if ($nonAtomOutputBytes * 10000 > $sourceProseBytes * $maxRatioBps) {
            throw new DomainFailure(Outcome::Incompatible, 'limit_exceeded');
        }
    }
}
