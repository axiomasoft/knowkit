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
            // ManifestCandidate has already bound every chunk to this sidecar's document.
            // Index its metadata and verify the IR once per set, not once per chunk.
            $metadata = [];
            foreach ($result->manifest()->get('chunks') as $entry) {
                $metadata[$entry->get('chunkId')] = $entry;
            }
            $map = null;
            $cellUnits = null;
            foreach ($set->chunks as $chunk) {
                $map ??= new CanonicalMap($document, $set->revisionRef, $chunk->documentId);
                $meta = $metadata[$chunk->ref->chunkId->value]
                    ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'manifest_mismatch');
                $map->assembler->assertChunk($chunk, $meta->get('joiner'));
                $parts = $this->context($map, $meta->get('context'), $cellUnits);
                $parts[] = $chunk->text();
                $units[] = new D\SelectionUnit($chunk, implode("\n", $parts),
                    $meta->get('table') !== null || $meta->get('kind') === 'table_representation',
                    $this->assets($map, $meta->get('blockIds'), $set->revisionRef));
            }
        }

        return new D\SelectionInventory($snapshot, $manifest, $units);
    }

    /**
     * @param list<JsonObject> $context
     * @param array<array-key, list<mixed>>|null $cellUnits
     * @return list<string>
     */
    private function context(CanonicalMap $map, array $context, ?array &$cellUnits): array
    {
        $parts = [];
        foreach ($context as $entry) {
            $text = $entry->get('text');
            if ($entry->get('role') === 'table_unit') {
                $cellUnits ??= $this->cellUnits($map);
                $this->assertTableUnit($cellUnits, $entry);
            } elseif ($map->assembler->assemble($map->spans($entry->get('spans')), $entry->get('joiner')) !== $text) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'source_text_mismatch');
            }
            $parts[] = $text;
        }

        return $parts;
    }

    /** @param array<array-key, list<mixed>> $cells */
    private function assertTableUnit(array $cells, JsonObject $entry): void
    {
        foreach ($cells[self::cellKey($entry->get('cellId'))] ?? [] as $unit) {
            if ($unit === $entry->get('text')) {
                return;
            }
        }
        throw new D\DomainFailure(D\Outcome::Incompatible, 'source_text_mismatch');
    }

    /** @return array<array-key, list<mixed>> */
    private function cellUnits(CanonicalMap $map): array
    {
        $cells = [];
        foreach ($map->blocks as $block) {
            foreach ($block->get('table')?->get('cells') ?? [] as $cell) {
                $cells[self::cellKey($cell->get('cellId'))][] = $cell->get('unit');
            }
        }

        return $cells;
    }

    /** PHP array keys coerce "1" and 1; cell identity stays strict. */
    private static function cellKey(mixed $id): string
    {
        return gettype($id) . "\0" . (is_scalar($id) ? (string) $id : '');
    }

    /**
     * @param list<string> $blockIds
     * @return list<string>
     */
    private function assets(CanonicalMap $map, array $blockIds, D\DocumentRevisionRef $revision): array
    {
        $assets = [];
        foreach ($blockIds as $id) {
            $block = $map->blocks[$id] ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'source_text_mismatch');
            foreach ($block->get('assetRefs') ?? [] as $asset) {
                $assets[] = $revision->irDigest->value . ':' . $asset;
            }
        }

        return array_values(array_unique($assets));
    }
}
