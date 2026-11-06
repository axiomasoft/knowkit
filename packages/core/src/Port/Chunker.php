<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{IrDocument, ChunkerProfile, ChunkSet};

interface Chunker
{
    public function chunk(IrDocument $document, ChunkerProfile $profile): ChunkSet;
}
