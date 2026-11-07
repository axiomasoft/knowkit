<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Usages;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\BlobStore;
use KnowKit\Port\CanonicalDocuments;

/** Reads accepted exact IR objects afresh. No host callback or blob operation runs under locks. */
final readonly class PdoCanonicalDocuments implements CanonicalDocuments
{
    private const OBJECT_SQL = <<<'SQL'
        SELECT b.* FROM %s r
        JOIN %s v ON v.tenant_id=r.tenant_id AND v.id=r.source_version_id
        JOIN %s b ON b.tenant_id=r.tenant_id AND b.id=r.ir_resource_id AND b.source_id=v.source_id
        WHERE r.tenant_id=? AND r.id=? AND v.source_id=? AND v.id=? AND v.source_digest=?
            AND r.ir_digest=? AND r.parser_name=? AND r.parser_version=? AND r.profile_digest=?
            AND b.state='accepted'
        SQL;

    public function __construct(
        private Connection $db,
        private BlobStore $blobs,
        private ContractValidator $validator,
    ) {}

    public function read(D\DocumentRevisionRef $revision, int $byteLimit): D\IrDocument
    {
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Canonical preload must precede BEGIN');
        }
        $source = $revision->sourceRef;
        $sql = sprintf(self::OBJECT_SQL, $this->db->table('document_revisions'),
            $this->db->table('source_versions'), $this->db->table('resource_ledger'));
        $row = $this->db->row($sql, [$source->tenantId->value, $revision->documentRevisionId->value,
            $source->sourceId->value, $source->sourceVersionId->value, $source->sourceDigest->value,
            $revision->irDigest->value, $revision->parserSignature->name, $revision->parserSignature->version,
            $revision->parserSignature->profileDigest->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'canonical_ir_unavailable');
        }
        $length = (string) $row['byte_length'];
        if ($byteLimit < 1 || strlen($length) > 8 || (int) $length > min($byteLimit, 8388608)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_preload_limit');
        }
        $ref = new D\BlobRef($source->tenantId, $source->sourceId, new D\Sha256($row['digest']),
            new D\Uint64($length), $row['storage_id'], $row['object_version'], $row['owner_kind']);
        $bytes = $this->bytes($ref, (int) $length);
        $document = new D\IrDocument(StrictJson::decode($bytes, 8388608), $this->validator);
        if (hash('sha256', $document->canonical()) !== $revision->irDigest->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }

        return $document;
    }

    public function readChunk(D\ChunkRef $chunk, int $byteLimit): string
    {
        if ($this->db->pdo->inTransaction()) {
            throw new \LogicException('Canonical preload must precede BEGIN');
        }
        $source = $chunk->revisionRef->sourceRef;
        $row = $this->db->row('SELECT b.* FROM ' . $this->db->table('chunks') . ' c'
            . ' JOIN ' . $this->db->table('chunk_sets') . ' cs ON cs.tenant_id=c.tenant_id AND cs.id=c.chunk_set_id'
            . ' JOIN ' . $this->db->table('document_revisions') . ' r ON r.tenant_id=cs.tenant_id AND r.id=cs.revision_id'
            . ' JOIN ' . $this->db->table('source_versions') . ' v ON v.tenant_id=r.tenant_id AND v.id=r.source_version_id'
            . ' JOIN ' . $this->db->table('resource_ledger') . ' b ON b.tenant_id=c.tenant_id AND b.id=c.canonical_payload_ref AND b.source_id=v.source_id'
            . " WHERE c.tenant_id=? AND c.id=? AND c.chunk_set_id=? AND c.content_digest=? AND r.id=? AND r.ir_digest=?"
            . " AND v.id=? AND v.source_id=? AND v.source_digest=? AND b.state='accepted'",
            [$source->tenantId->value, $chunk->chunkId->value, $chunk->chunkSetId->value, $chunk->chunkDigest->value,
                $chunk->revisionRef->documentRevisionId->value, $chunk->revisionRef->irDigest->value,
                $source->sourceVersionId->value, $source->sourceId->value, $source->sourceDigest->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'canonical_chunk_unavailable');
        }
        if ($row['digest'] !== $chunk->chunkDigest->value || $byteLimit < 1
            || strlen((string) $row['byte_length']) > 8 || (int) $row['byte_length'] > min($byteLimit, 8388608)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'canonical_preload_limit');
        }
        $ref = new D\BlobRef($source->tenantId, $source->sourceId, new D\Sha256($row['digest']), new D\Uint64((string) $row['byte_length']),
            $row['storage_id'], $row['object_version'], $row['owner_kind']);

        return $this->bytes($ref, (int) $row['byte_length']);
    }

    private function bytes(D\BlobRef $ref, int $length): string
    {
        $stream = $this->blobs->open($ref);
        $bytes = '';
        try {
            while (true) {
                $part = $stream->read(min(65536, $length - strlen($bytes) + 1));
                $ended = $stream->eof();
                $bytes .= $part;
                if (strlen($bytes) > $length || ($part === '' && !$ended)) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
                }
                if ($ended) {
                    break;
                }
            }
        } finally {
            $stream->close();
        }
        if (strlen($bytes) !== $length || hash('sha256', $bytes) !== $ref->digest->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'digest_mismatch');
        }

        return $bytes;
    }
}
