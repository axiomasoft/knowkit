<?php
declare(strict_types=1);
namespace KnowKit\Port;

use KnowKit\Domain\{ChunkerProfile, ChunkingResult, DocumentId, DocumentRevisionRef, IrDocument};

/** Trusted revision-bound builder; build revalidates canonical spans against the exact inputs. */
interface ChunkSetBuilder extends Chunker
{
    public function revision(): DocumentRevisionRef;
    public function documentId(): DocumentId;
    public function build(IrDocument $document, ChunkerProfile $profile): ChunkingResult;
}
