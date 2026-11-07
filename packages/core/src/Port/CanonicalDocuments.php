<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\ChunkRef;

interface CanonicalDocuments
{
    /** Fresh immutable object read, outside SQL transactions; fail before exceeding byteLimit. */
    public function read(DocumentRevisionRef $revision, int $byteLimit): IrDocument;

    public function readChunk(ChunkRef $chunk, int $byteLimit): string;
}
