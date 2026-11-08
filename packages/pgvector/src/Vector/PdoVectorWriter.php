<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Vector;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\IndexGenerations\ResourceInventory;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\PgVector\Domain\DocumentVectors;
use KnowKit\PgVector\Domain\ManifestView;
use KnowKit\PgVector\Domain\VectorPayload;
use KnowKit\Port\IdGenerator;

/** Caller owns the catalog transaction and tenant update guard. All provider I/O is already complete. */
final readonly class PdoVectorWriter
{
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private PdoGenerationBackend $backend,
        private DocumentVectors $documents,
        private IdGenerator $ids,
    ) {
        if ($db !== $tx->db) {
            throw new \InvalidArgumentException('Shared catalog connection required');
        }
    }

    public function index(ManifestView $view, D\ChunkSet $set, D\EmbeddingSpace $space): D\IndexReceipt
    {
        $this->tx->requireTenant($view->tenant, true);
        if (!$view->tenant->equals($set->revisionRef->sourceRef->tenantId)) {
            self::denied();
        }
        $generation = $this->backend->generation($view);
        if ($generation['state'] !== 'building') {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'generation_sealed');
        }
        $digest = $view->setDigest($set);
        $this->documents->assertInventory($view);
        $payloads = $this->documents->forSet($set, $space);
        (new ResourceInventory($this->db))->assertExact($view->candidate);
        $this->stageSpace($view, $space);
        foreach ($set->chunks as $chunk) {
            $this->write($view, $chunk, $payloads[$chunk->ref->chunkId->value]);
        }
        $this->db->execute('UPDATE ' . $this->db->table('index_generations') . ' g SET chunk_count=(SELECT count(*) FROM '
            . $this->db->table('index_memberships') . ' i WHERE i.tenant_id=g.tenant_id AND i.generation_id=g.id)
            WHERE g.tenant_id=? AND g.id=?', [$view->tenant->value, $view->generation->value]);

        return new D\IndexReceipt($view->tenant, $view->generation, $set->chunkSetId, $digest,
            count($set->chunks), $view->fingerprint);
    }

    private function stageSpace(ManifestView $view, D\EmbeddingSpace $space): void
    {
        $table = $this->db->table('vector_generations');
        $this->db->execute("INSERT INTO $table (tenant_id,generation_id,manifest_digest,space_digest,space_json)
            VALUES (?,?,?,?,?) ON CONFLICT (tenant_id,generation_id) DO NOTHING",
            [$view->tenant->value, $view->generation->value, $view->candidate->digest->value,
                $space->digest()->value, Jcs::canonicalize($space->toWire())]);
        $this->backend->assertSpaceReceipt($view);
    }

    private function write(ManifestView $view, D\Chunk $chunk, VectorPayload $payload): void
    {
        $ref = $chunk->ref;
        $keys = [$view->tenant->value, $view->generation->value, $ref->chunkId->value];
        $identity = [$ref->chunkDigest->value, $ref->revisionRef->sourceRef->sourceVersionId->value,
            $ref->revisionRef->documentRevisionId->value, $ref->chunkSetId->value];
        $members = $this->db->table('index_memberships');
        $vectors = $this->db->table('vector_documents');
        $this->db->execute("INSERT INTO $members (id,tenant_id,generation_id,chunk_id,chunk_digest,source_version_id,revision_id,chunk_set_id)
            VALUES (?,?,?,?,?,?,?,?) ON CONFLICT (tenant_id,generation_id,chunk_id) DO NOTHING",
            [$this->ids->next()->value, ...$keys, ...$identity]);
        $match = $this->db->row("SELECT id FROM $members WHERE tenant_id=? AND generation_id=? AND chunk_id=?
            AND chunk_digest=? AND source_version_id=? AND revision_id=? AND chunk_set_id=?", [...$keys, ...$identity]);
        if ($match === null) {
            self::conflict();
        }
        $this->db->execute("INSERT INTO $vectors (tenant_id,generation_id,chunk_id,space_digest,vector_digest,embedding)
            VALUES (?,?,?,?,?,CAST(? AS public.vector)) ON CONFLICT (tenant_id,generation_id,chunk_id) DO NOTHING",
            [...$keys, $view->fingerprint->value, $payload->digest, $payload->literal]);
        $match = $this->db->row("SELECT vector_digest FROM $vectors WHERE tenant_id=? AND generation_id=? AND chunk_id=?
            AND space_digest=? AND vector_digest=? AND embedding OPERATOR(public.=) CAST(? AS public.vector)",
            [...$keys, $view->fingerprint->value, $payload->digest, $payload->literal]);
        if ($match === null) {
            self::conflict();
        }
    }

    private static function conflict(): never
    {
        throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
    }

    private static function denied(): never
    {
        throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
    }
}
