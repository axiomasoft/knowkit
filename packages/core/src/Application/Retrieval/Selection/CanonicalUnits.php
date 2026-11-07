<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Selection;

use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\SelectionInventoryBuilder;

/** Reuses the canonical span verifier; backend text never enters selection. */
final readonly class CanonicalUnits implements SelectionInventoryBuilder
{
    /** @param array<string, D\IrDocument> $documents Keyed by exact IR digest. */
    public function build(D\SnapshotRef $snapshot, D\ManifestCandidate $manifest, array $documents): D\SelectionInventory
    {
        $units = [];
        foreach ($manifest->sets as $result) {
            $set = $result->chunkSet;
            $document = $documents[$set->revisionRef->irDigest->value]
                ?? throw new D\DomainFailure(D\Outcome::Unavailable, 'canonical_ir_unavailable');
            foreach ($set->chunks as $chunk) {
                $map = new CanonicalMap($document, $set->revisionRef, $chunk->documentId);
                $meta = $result->metadata($chunk->ref->chunkId->value);
                $map->assembler->assertChunk($chunk, $meta->get('joiner'));
                $parts = $this->context($map, $meta->get('context'));
                $parts[] = $chunk->text();
                $units[] = new D\SelectionUnit($chunk, implode("\n", $parts),
                    $meta->get('table') !== null || $meta->get('kind') === 'table_representation',
                    $this->assets($map, $meta->get('blockIds'), $set->revisionRef));
            }
        }

        return new D\SelectionInventory($snapshot, $manifest, $units);
    }

    /** @param list<JsonObject> $context @return list<string> */
    private function context(CanonicalMap $map, array $context): array
    {
        $parts = [];
        foreach ($context as $entry) {
            $text = $entry->get('text');
            if ($entry->get('role') === 'table_unit') {
                $this->assertTableUnit($map, $entry);
            } elseif ($map->assembler->assemble($map->spans($entry->get('spans')), $entry->get('joiner')) !== $text) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'source_text_mismatch');
            }
            $parts[] = $text;
        }

        return $parts;
    }

    private function assertTableUnit(CanonicalMap $map, JsonObject $entry): void
    {
        foreach ($map->blocks as $block) {
            foreach ($block->get('table')?->get('cells') ?? [] as $cell) {
                if ($cell->get('cellId') === $entry->get('cellId') && $cell->get('unit') === $entry->get('text')) {
                    return;
                }
            }
        }
        throw new D\DomainFailure(D\Outcome::Incompatible, 'source_text_mismatch');
    }

    /** @param list<string> $blockIds @return list<string> */
    private function assets(CanonicalMap $map, array $blockIds, D\DocumentRevisionRef $revision): array
    {
        $assets = [];
        foreach ($blockIds as $id) {
            foreach ($map->blocks[$id]->get('assetRefs') ?? [] as $asset) {
                $assets[] = $revision->irDigest->value . ':' . $asset;
            }
        }

        return array_values(array_unique($assets));
    }
}
