<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Reading;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Reading\CitationRecord;

/** Uses the pre-existing immutable resolver table; no new schema or latest pointer. */
final readonly class PdoCitations
{
    private const INSERT_SQL = <<<'SQL'
        INSERT INTO %s (tenant_id,id,usage_id,host_scope_hash,source_id,source_version_id,revision_id,document_id,
            chunk_set_id,chunk_id,content_digest,source_digest,ir_digest,locator_json,digests_json,payload_state)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb,?::jsonb,'present')
        SQL;

    private const LOOKUP_SQL = <<<'SQL'
        SELECT c.*,u.selection_id,u.kb_id,r.parser_name,r.parser_version,r.profile_digest
        FROM %s c JOIN %s u ON u.tenant_id=c.tenant_id AND u.id=c.usage_id
        JOIN %s r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
        WHERE c.tenant_id=? AND c.id=?
        SQL;

    public function __construct(
        private Connection $db,
    ) {}

    public function save(D\PreparedDisclosure $prepared): void
    {
        if (!$this->db->pdo->inTransaction()) {
            throw new \LogicException('Citation insertion belongs to Usage transaction');
        }
        $fragments = $prepared->evidence->toWire()->get('fragments');
        $refs = $prepared->usage->toWire()->get('fragmentRefs');
        foreach ($fragments as $index => $fragment) {
            $citation = $fragment->get('citation');
            $chunk = $refs[$index]->get('chunkRef');
            $this->db->execute(sprintf(self::INSERT_SQL, $this->db->table('citations')), [
                $citation->get('tenantId'), $citation->get('citationId'), $prepared->usage->id()->value,
                $prepared->usage->hostScopeHash()->value, $citation->get('sourceId'), $citation->get('sourceVersionId'),
                $citation->get('documentRevisionId'), $citation->get('documentId'), $chunk->get('chunkSetId'),
                $chunk->get('chunkId'), $citation->get('contentDigest'), $citation->get('sourceDigest'), $citation->get('irDigest'),
                Jcs::canonicalize($citation->get('spans')),
                Jcs::canonicalize(new JsonObject(['citation' => $citation, 'joiner' => $fragment->get('joiner')])),
            ]);
        }
    }

    public function get(D\Scope $scope, D\Ulid $id, ContractValidator $validator): CitationRecord
    {
        $sql = sprintf(self::LOOKUP_SQL, $this->db->table('citations'), $this->db->table('evidence_usages'),
            $this->db->table('document_revisions'));
        $row = $this->db->row($sql, [$scope->tenantId->value, $id->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unknown, 'citation_unknown');
        }
        $stored = StrictJson::decode($row['digests_json']);
        $citation = new D\CitationRef($stored->get('citation'), $validator);
        $source = new D\SourceVersionRef($scope->tenantId, new D\SourceId($row['source_id']),
            new D\SourceVersionId($row['source_version_id']), new D\SourceDigest($row['source_digest']));
        $revision = new D\DocumentRevisionRef($source, new D\DocumentRevisionId($row['revision_id']),
            new D\IrDigest($row['ir_digest']), new D\ParserSignature($row['parser_name'], $row['parser_version'], new D\Fingerprint($row['profile_digest'])));
        $chunk = new D\ChunkRef($revision, new D\ChunkSetId($row['chunk_set_id']), new D\ChunkId($row['chunk_id']), new D\Sha256($row['content_digest']));
        $record = new CitationRecord($citation, $chunk, new D\SelectionId($row['selection_id']), new D\KbId($row['kb_id']),
            new D\Sha256($row['host_scope_hash']), $stored->get('joiner'), $row['payload_state'] === 'present');
        if ($citation->id() != $id || $citation->documentId()->value !== $row['document_id']
            || $citation->toWire()->get('spans') != StrictJson::decode($row['locator_json'])) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }
        $record->requireScope($scope);

        return $record;
    }
}
