<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\ChunkSets;

use KnowKit\Adapter\Postgres\Catalog\{ChunkMetadata, ChunkSetMetadata, Connection, PdoCatalogRepository, PdoCatalogTransaction};
use KnowKit\Application\Chunking\{ChunkingResult, StructuralChunker};
use KnowKit\Domain\{ChunkerProfile, DomainFailure, IrDocument, Outcome, ResourceId, TenantId};
use KnowKit\Domain\Contract\JsonObject;

/** Internal persistence, never a disclosure API. Caller preloads accepted payload resources and owns the transaction. */
final readonly class PdoChunkSetStore
{
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx, private PdoCatalogRepository $catalog)
    {
        if ($db !== $tx->db) { throw new \InvalidArgumentException('Shared transaction required'); }
    }

    /**
     * Rebuild from pinned inputs rather than accepting self-signed chunk metadata.
     * @param array<array-key,mixed> $resources Checked here as exact chunk ID => accepted ResourceId.
     */
    public function save(TenantId $tenant, StructuralChunker $chunker, IrDocument $ir, ChunkerProfile $profile, array $resources): ChunkingResult
    {
        $this->tx->requireTenant($tenant, true);
        if ($chunker->revision->sourceRef->tenantId != $tenant) { self::fail('scope_mismatch', Outcome::Denied); }
        $result = $chunker->build($ir, $profile);
        $revision = $chunker->revision;
        if ($this->db->row('SELECT id FROM ' . $this->db->table('documents') . ' WHERE tenant_id=? AND id=? AND revision_id=?',
            [$tenant->value, $chunker->documentId->value, $revision->documentRevisionId->value]) === null) { self::fail('binding_mismatch'); }
        $expected = array_map(static fn($c): string => $c->ref->chunkId->value, $result->chunkSet->chunks);
        $actual = array_keys($resources); sort($expected, SORT_STRING); sort($actual, SORT_STRING);
        if ($actual !== $expected) { self::fail('chunk_metadata_required'); }
        $metadata = [];
        foreach ($result->chunkSet->chunks as $chunk) {
            $id = $chunk->ref->chunkId->value;
            if (!$resources[$id] instanceof ResourceId) { self::fail('chunk_resource_required'); }
            $entry = $result->metadata($id);
            // Store locators and explicit roles. Text is in canonical resources, not a second JSONB text store.
            $context = array_map(static fn(JsonObject $c): JsonObject => $c->without('text'), $entry->get('context'));
            $provenance = $entry->without('chunkId')->without('chunkDigest')->without('spans')->without('byteLength')->without('ordinal')->without('structurePath')
                ->with('context', $context);
            $metadata[$id] = new ChunkMetadata($resources[$id], $provenance, $entry->get('structurePath'));
        }
        $this->catalog->insertChunkSet($tenant, $result->chunkSet, new ChunkSetMetadata($result->manifestDigest, $metadata));
        return $result;
    }

    private static function fail(string $code, Outcome $outcome = Outcome::Incompatible): never { throw new DomainFailure($outcome, $code); }
}
