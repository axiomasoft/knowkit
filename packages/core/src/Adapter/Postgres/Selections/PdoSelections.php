<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Selections;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain\SelectionRecordCodec;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\SelectionStore;

/** Tenant-qualified immutable inserts. Tokens and canonical document bytes never enter SQL. */
final readonly class PdoSelections implements SelectionStore
{
    private const INSERT_SQL = <<<'SQL'
        INSERT INTO %s (tenant_id,id,token_hash,host_scope_hash,scope_binding_json,scope_binding_digest,
            run_ref,query_hash,snapshot_id,generation_id,policy_binding_json,profile_id,space_digest,
            candidate_refs_json,expires_at,record_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        SQL;

    private const REF_SQL = <<<'SQL'
        SELECT c.id FROM %s sm
        JOIN %s c ON c.tenant_id=sm.tenant_id AND c.chunk_set_id=sm.chunk_set_id
        JOIN %s r ON r.tenant_id=sm.tenant_id AND r.id=sm.revision_id
        JOIN %s v ON v.tenant_id=sm.tenant_id AND v.id=sm.source_version_id AND v.id=r.source_version_id
        JOIN %s i ON i.tenant_id=sm.tenant_id AND i.generation_id=sm.generation_id
            AND i.chunk_id=c.id AND i.chunk_digest=c.content_digest AND i.chunk_set_id=sm.chunk_set_id
            AND i.revision_id=r.id AND i.source_version_id=v.id
        WHERE sm.tenant_id=? AND sm.kb_id=? AND sm.snapshot_id=? AND sm.generation_id=?
            AND sm.source_id=? AND sm.source_version_id=? AND sm.revision_id=? AND sm.chunk_set_id=?
            AND c.id=? AND c.content_digest=? AND v.source_digest=? AND r.ir_digest=?
            AND r.parser_name=? AND r.parser_version=? AND r.profile_digest=?
        SQL;

    public function __construct(
        private Connection $db,
        private SelectionRecordCodec $codec,
    ) {}

    public function put(D\Scope $scope, D\Sha256 $tokenHash, D\SelectionRecord $record): void
    {
        $this->assertScope($scope, $record);
        $s = $record->selection->toWire();
        $this->assertSnapshot($scope, $record);
        $this->db->execute(sprintf(self::INSERT_SQL, $this->db->table('selections')), [
            $scope->tenantId->value, $record->selection->id()->value, $tokenHash->value,
            $scope->hostScopeHash->value, Jcs::canonicalize($scope->toWire()), $record->selection->scopeBindingDigest()->value,
            $record->selection->runRef(), $record->query->digest()->value, $record->snapshot->snapshotId->value,
            $record->selection->generationId()->value, Jcs::canonicalize($s->get('policyBinding')),
            $record->query->limits->profile, $record->query->spaceFingerprint?->value,
            Jcs::canonicalize($s->get('candidateRefs')), $record->selection->expiresAt()->value,
            Jcs::canonicalize($this->codec->encode($record)),
        ]);
    }

    public function get(D\Scope $scope, D\Sha256 $tokenHash): ?D\SelectionRecord
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('selections')
            . ' WHERE tenant_id=? AND token_hash=? AND host_scope_hash=?',
            [$scope->tenantId->value, $tokenHash->value, $scope->hostScopeHash->value]);
        if ($row === null) {
            return null;
        }
        if ($row['record_json'] === null) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'selection_record_version');
        }
        try {
            $record = $this->codec->decode(StrictJson::decode($row['record_json']));
        } catch (D\DomainFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'selection_record_invalid');
        }
        $this->assertScope($scope, $record);
        $this->assertColumns($record, $row);
        $this->assertSnapshot($scope, $record);

        return $record;
    }

    private function assertColumns(D\SelectionRecord $record, array $row): void
    {
        $s = $record->selection->toWire();
        $expected = [
            'id' => $record->selection->id()->value,
            'query_hash' => $record->query->digest()->value,
            'scope_binding_digest' => $record->selection->scopeBindingDigest()->value,
            'snapshot_id' => $record->snapshot->snapshotId->value,
            'generation_id' => $record->selection->generationId()->value,
            'run_ref' => $record->selection->runRef(),
            'profile_id' => $record->query->limits->profile,
            'space_digest' => $record->query->spaceFingerprint?->value,
        ];
        foreach ($expected as $column => $value) {
            if ($row[$column] !== $value) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
            }
        }
        foreach (['candidate_refs_json' => $s->get('candidateRefs'), 'policy_binding_json' => $s->get('policyBinding'),
            'scope_binding_json' => $record->context->scope->toWire()] as $column => $value) {
            if (Jcs::canonicalize(StrictJson::decode($row[$column])) !== Jcs::canonicalize($value)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
            }
        }
        $storedExpires = (new \DateTimeImmutable((string) $row['expires_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $expectedExpires = (new \DateTimeImmutable($record->selection->expiresAt()->value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        if ($storedExpires !== $expectedExpires) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }
    }

    private function assertScope(D\Scope $scope, D\SelectionRecord $record): void
    {
        $stored = $record->context->scope;
        $scope->assertTenant($stored->tenantId);
        if (!$scope->kbId->equals($stored->kbId) || !$scope->actorId->equals($stored->actorId)
            || $scope->purpose !== $stored->purpose || !$scope->hostScopeHash->equals($stored->hostScopeHash)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        if ($scope->policyEpoch->value !== $stored->policyEpoch->value) {
            throw new D\DomainFailure(D\Outcome::Denied, 'POLICY_CHANGED');
        }
    }

    private function assertSnapshot(D\Scope $scope, D\SelectionRecord $record): void
    {
        $row = $this->db->row('SELECT s.manifest_digest FROM ' . $this->db->table('publication_snapshots')
            . ' s JOIN ' . $this->db->table('snapshot_generations')
            . ' sg ON sg.tenant_id=s.tenant_id AND sg.kb_id=s.kb_id AND sg.snapshot_id=s.id'
            . ' WHERE s.tenant_id=? AND s.kb_id=? AND s.id=? AND sg.generation_id=? AND s.sealed',
            [$scope->tenantId->value, $scope->kbId->value, $record->snapshot->snapshotId->value,
                $record->selection->generationId()->value]);
        if ($row === null || $row['manifest_digest'] !== $record->snapshot->manifestDigest->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'snapshot_mismatch');
        }
        $this->assertRefs($scope, $record);
    }

    private function assertRefs(D\Scope $scope, D\SelectionRecord $record): void
    {
        $sql = sprintf(self::REF_SQL, $this->db->table('snapshot_memberships'), $this->db->table('chunks'),
            $this->db->table('document_revisions'), $this->db->table('source_versions'), $this->db->table('index_memberships'));
        foreach ($record->selection->candidateRefs() as $ref) {
            $r = $ref->revisionRef;
            $v = $r->sourceRef;
            $scope->assertTenant($v->tenantId);
            $row = $this->db->row($sql, [$scope->tenantId->value, $scope->kbId->value,
                $record->snapshot->snapshotId->value, $record->selection->generationId()->value,
                $v->sourceId->value, $v->sourceVersionId->value, $r->documentRevisionId->value, $ref->chunkSetId->value,
                $ref->chunkId->value, $ref->chunkDigest->value, $v->sourceDigest->value, $r->irDigest->value,
                $r->parserSignature->name, $r->parserSignature->version, $r->parserSignature->profileDigest->value]);
            if ($row === null) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'candidate_not_in_manifest');
            }
        }
    }
}
