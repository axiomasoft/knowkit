<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\IrDocument;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\OcrMetadata;

/** Typed interpretation of the qualified OCR profile's closed IR 1.0 encoding. */
final class OcrMetadataReader
{
    public function forBlock(IrDocument $document, LocalId $blockId): OcrMetadata
    {
        return $document->ocrMetadata($blockId);
    }
}
