<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{Bbox, BlobRef, DomainFailure, LocalId, Outcome, Sha256, TextLocator, Ulid, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Layers\ResolutionTier;

final readonly class DerivedArtifactRef
{
    public const ALLOWED_ORIGINS = ['source', 'ocr', 'generated', 'deterministic'];
    public const ALLOWED_PROVENANCE_KINDS = [
        'verbatim',
        'table_representation',
        'description',
        'summary',
        'pruned_verbatim_subspans',
        'navigation_outline',
        'proposition_set',
    ];

    /**
     * @param list<TextLocator> $supportingLocators
     */
    public function __construct(
        public ResolutionTier $tier,
        public Ulid $artifactId,
        public Sha256 $blobDigest,
        public int $byteLength,
        public int $tokenCount,
        public string $tokenizerId,
        public string $origin,
        public string $provenanceKind,
        public Ulid $dependencyNodeId,
        public int $protectedAtomCount,
        public array $supportingLocators = [],
        public ?BlobRef $blobRef = null,
    ) {
        if ($tier === ResolutionTier::R3Verbatim) {
            throw new \InvalidArgumentException('DerivedArtifactRef only supports R0, R1, or R2');
        }
        if ($byteLength < 1 || $byteLength > 16777216) {
            throw new \InvalidArgumentException('byteLength must be 1..16777216');
        }
        if ($tokenCount < 1 || $tokenCount > 1048576) {
            throw new \InvalidArgumentException('tokenCount must be 1..1048576');
        }
        ValueGuard::text($tokenizerId, 128);
        if (!in_array($origin, self::ALLOWED_ORIGINS, true)) {
            throw new \InvalidArgumentException('Unsupported artifact origin');
        }
        if (!in_array($provenanceKind, self::ALLOWED_PROVENANCE_KINDS, true)) {
            throw new \InvalidArgumentException('Unsupported artifact provenanceKind');
        }
        if ($protectedAtomCount < 0 || $protectedAtomCount > 65536) {
            throw new \InvalidArgumentException('protectedAtomCount must be 0..65536');
        }
        ValueGuard::objects($supportingLocators, TextLocator::class, 512);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'tier' => $this->tier->value,
            'artifactId' => $this->artifactId->value,
            'blobDigest' => $this->blobDigest->value,
            'byteLength' => $this->byteLength,
            'tokenCount' => $this->tokenCount,
            'tokenizerId' => $this->tokenizerId,
            'origin' => $this->origin,
            'provenanceKind' => $this->provenanceKind,
            'dependencyNodeId' => $this->dependencyNodeId->value,
            'protectedAtomCount' => $this->protectedAtomCount,
            'supportingLocators' => array_map(
                static fn(TextLocator $locator): JsonObject => $locator->toWire(),
                $this->supportingLocators,
            ),
        ]);
    }

    public static function fromWire(JsonObject $wire): self
    {
        $locatorsRaw = $wire->get('supportingLocators');
        if (!is_array($locatorsRaw)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $locators = [];
        foreach ($locatorsRaw as $item) {
            if (!$item instanceof JsonObject) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
            $locators[] = self::hydrateLocator($item);
        }

        return new self(
            tier: ResolutionTier::from((string) $wire->get('tier')),
            artifactId: new Ulid((string) $wire->get('artifactId')),
            blobDigest: new Sha256((string) $wire->get('blobDigest')),
            byteLength: (int) $wire->get('byteLength'),
            tokenCount: (int) $wire->get('tokenCount'),
            tokenizerId: (string) $wire->get('tokenizerId'),
            origin: (string) $wire->get('origin'),
            provenanceKind: (string) $wire->get('provenanceKind'),
            dependencyNodeId: new Ulid((string) $wire->get('dependencyNodeId')),
            protectedAtomCount: (int) $wire->get('protectedAtomCount'),
            supportingLocators: $locators,
        );
    }

    private static function hydrateLocator(JsonObject $wire): TextLocator
    {
        $kind = (string) $wire->get('kind');
        if ($kind === 'page') {
            $bboxWire = $wire->has('bbox') ? $wire->get('bbox') : null;
            $bbox = null;
            if ($bboxWire instanceof JsonObject) {
                $bbox = new Bbox(
                    (int) $bboxWire->get('x0'),
                    (int) $bboxWire->get('y0'),
                    (int) $bboxWire->get('x1'),
                    (int) $bboxWire->get('y1'),
                );
            }
            $printedLabel = $wire->get('printedLabel');
            return TextLocator::page(
                new LocalId((string) $wire->get('pageId')),
                (int) $wire->get('physicalNo'),
                is_string($printedLabel) ? $printedLabel : null,
                new LocalId((string) $wire->get('blockId')),
                (int) $wire->get('byteStart'),
                (int) $wire->get('byteEnd'),
                $bbox,
            );
        }
        if ($kind === 'segment') {
            return TextLocator::segment(
                new LocalId((string) $wire->get('segmentId')),
                new LocalId((string) $wire->get('blockId')),
                (int) $wire->get('byteStart'),
                (int) $wire->get('byteEnd'),
            );
        }
        throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
    }
}
