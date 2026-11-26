<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\ChunkSets;

use InvalidArgumentException;
use KnowKit\Adapter\Postgres\Catalog\ChunkMetadata;
use KnowKit\Adapter\Postgres\Catalog\ChunkSetMetadata;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogRepository;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Domain\ChunkerProfile;
use KnowKit\Domain\ChunkingResult;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\DocumentId;
use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\ResourceId;
use KnowKit\Domain\TenantId;
use KnowKit\Port\ChunkSetBuilder;

/** Internal persistence, never a disclosure API. Caller preloads accepted payload resources and owns the transaction. */
final readonly class PdoChunkSetStore
{
    private const VERIFY_DOCUMENT_REVISION_SQL = <<<'SQL'
        SELECT id
        FROM %s
        WHERE tenant_id = ?
          AND id = ?
          AND revision_id = ?
        SQL;

    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private PdoCatalogRepository $catalog,
    ) {
        if ($db !== $tx->db) {
            throw new InvalidArgumentException('Shared transaction required');
        }
    }

    /**
     * Rebuild from pinned inputs rather than accepting self-signed chunk metadata.
     *
     * @param array<array-key, mixed> $resources Checked here as exact chunk ID => accepted ResourceId.
     */
    public function save(
        TenantId $tenant,
        ChunkSetBuilder $chunker,
        IrDocument $ir,
        ChunkerProfile $profile,
        array $resources,
    ): ChunkingResult {
        $this->tx->requireTenant($tenant, true);

        $revision = $chunker->revision();
        $documentId = $chunker->documentId();

        if (!$revision->sourceRef->tenantId->equals($tenant)) {
            self::fail('scope_mismatch', Outcome::Denied);
        }

        $result = $chunker->build($ir, $profile);
        $this->assertChunkSetBinding($tenant, $documentId, $revision, $profile, $result);

        $metadata = $this->buildChunkMetadataMap($result, $resources);
        $this->catalog->insertChunkSet(
            $tenant,
            $result->chunkSet,
            new ChunkSetMetadata($result->manifestDigest, $metadata),
        );

        return $result;
    }

    private function assertChunkSetBinding(
        TenantId $tenant,
        DocumentId $documentId,
        DocumentRevisionRef $revision,
        ChunkerProfile $profile,
        ChunkingResult $result,
    ): void {
        if (
            Jcs::canonicalize($result->chunkSet->revisionRef->toWire()) !== Jcs::canonicalize($revision->toWire())
            || Jcs::canonicalize($result->chunkSet->chunkerSignature->toWire()) !== Jcs::canonicalize($profile->signature->toWire())
        ) {
            self::fail('binding_mismatch');
        }

        foreach ($result->chunkSet->chunks as $chunk) {
            if (!$chunk->documentId->equals($documentId)) {
                self::fail('binding_mismatch');
            }
        }

        $sql = sprintf(self::VERIFY_DOCUMENT_REVISION_SQL, $this->db->table('documents'));
        $row = $this->db->row($sql, [
            $tenant->value,
            $documentId->value,
            $revision->documentRevisionId->value,
        ]);

        if ($row === null) {
            self::fail('binding_mismatch');
        }
    }

    /**
     * @param array<array-key, mixed> $resources
     * @return array<string, ChunkMetadata>
     */
    private function buildChunkMetadataMap(ChunkingResult $result, array $resources): array
    {
        $expected = $result->expectedChunkIds();
        $actual = array_map(static fn (int|string $key): string => (string) $key, array_keys($resources));
        sort($actual, SORT_STRING);

        if ($actual !== $expected) {
            self::fail('chunk_metadata_required');
        }

        $metadata = [];
        foreach ($result->chunkSet->chunks as $chunk) {
            $chunkId = $chunk->ref->chunkId->value;
            $resourceId = $resources[$chunkId] ?? null;

            if (!$resourceId instanceof ResourceId) {
                self::fail('chunk_resource_required');
            }

            $metadata[$chunkId] = new ChunkMetadata(
                $resourceId,
                $result->provenance($chunkId),
                $result->structurePath($chunkId),
            );
        }

        return $metadata;
    }

    private static function fail(string $code, Outcome $outcome = Outcome::Incompatible): never
    {
        throw new DomainFailure($outcome, $code);
    }
}
