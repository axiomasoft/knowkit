<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Confidence describes extraction quality in permille, never factual truth. */
final readonly class OcrMetadata
{
    private function __construct(
        public int $physicalNo,
        public int $ordinal,
        public string $language,
        public Fingerprint $modelFingerprint,
        public int $confidence,
    ) {
    }

    public static function fromBlockId(LocalId $blockId): self
    {
        $pattern = '/^ocr-p([1-9][0-9]*)-b([1-9][0-9]*):lang-(eng|rus):model-([0-9a-f]{64}):conf-(0|[1-9][0-9]{0,3})$/D';
        if (preg_match($pattern, $blockId->value, $parts) !== 1 || (int) $parts[5] > 1000
            || (int) $parts[1] > 4294967295 || (int) $parts[2] > 4294967295) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_ocr_metadata');
        }

        return new self(
            (int) $parts[1],
            (int) $parts[2],
            $parts[3],
            new Fingerprint($parts[4]),
            (int) $parts[5],
        );
    }

    public static function fromDocument(IrDocument $document, LocalId $blockId): self
    {
        $block = $document->block($blockId);
        $metadata = self::fromBlockId($blockId);
        if ($block->get('origin') !== 'ocr' || $block->get('provenance')->get('kind') !== 'verbatim') {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_ocr_provenance');
        }
        $assets = $document->assets();
        $references = $block->get('provenance')->get('supportingRefs');
        foreach ($block->get('spans') as $span) {
            if ($span->get('kind') !== 'page' || $span->get('physicalNo') !== $metadata->physicalNo
                || !$span->has('bbox')) {
                throw new DomainFailure(Outcome::Incompatible, 'invalid_ocr_locator');
            }
            self::requirePreview($assets, $references, $span->get('pageId'));
        }
        if ($block->get('spans') === []) {
            throw new DomainFailure(Outcome::Incompatible, 'invalid_ocr_locator');
        }

        return $metadata;
    }

    /**
     * @param list<\KnowKit\Domain\Contract\JsonObject> $assets
     * @param list<string> $references
     */
    private static function requirePreview(array $assets, array $references, string $pageId): void
    {
        foreach ($assets as $asset) {
            if ($asset->get('pageId') === $pageId && in_array($asset->get('assetId'), $references, true)
                && $asset->get('mediaType') === 'image/png') {
                return;
            }
        }

        throw new DomainFailure(Outcome::Incompatible, 'ocr_preview_missing');
    }
}
