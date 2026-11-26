<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Lexical;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\SelectionCatalog;
use KnowKit\Port\SelectionInventoryBuilder;

final readonly class PdoSelectionCatalog implements SelectionCatalog
{
    private const LOAD_SQL = <<<'SQL'
        SELECT s.manifest_digest AS snapshot_digest, g.manifest_digest, g.manifest_json
        FROM %s s
        JOIN %s sg ON sg.tenant_id=s.tenant_id AND sg.kb_id=s.kb_id AND sg.snapshot_id=s.id
        JOIN %s g ON g.tenant_id=sg.tenant_id AND g.kb_id=sg.kb_id AND g.id=sg.generation_id
            AND g.profile_id=sg.profile_id
        WHERE s.tenant_id=? AND s.kb_id=? AND s.id=? AND sg.generation_id=?
            AND s.sealed AND g.state IN ('sealed','active','retired')
        SQL;

    /** @param array<string, D\IrDocument> $documents Exact verified canonical buffers, not backend snippets. */
    public function __construct(
        private Connection $db,
        private CanonicalChunkSets $canonical,
        private ContractValidator $validator,
        private array $documents,
        private SelectionInventoryBuilder $inventoryBuilder,
    ) {}

    public function load(D\Scope $scope, D\SnapshotRef $snapshot, D\GenerationId $generation): D\SelectionInventory
    {
        $scope->assertTenant($snapshot->tenantId);
        if (!$scope->kbId->equals($snapshot->kbId)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        $sql = sprintf(self::LOAD_SQL, $this->db->table('publication_snapshots'),
            $this->db->table('snapshot_generations'), $this->db->table('index_generations'));
        $row = $this->db->row($sql, [$scope->tenantId->value, $scope->kbId->value,
            $snapshot->snapshotId->value, $generation->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'sealed_generation_unavailable');
        }
        if ($row['snapshot_digest'] !== $snapshot->manifestDigest->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'snapshot_mismatch');
        }

        return $this->inventory($snapshot, $generation, $row);
    }

    private function inventory(D\SnapshotRef $snapshot, D\GenerationId $generation, array $row): D\SelectionInventory
    {
        $manifest = new D\IndexManifest(StrictJson::decode($row['manifest_json']), $this->validator);
        $sets = [];
        foreach ($manifest->toWire()->get('members') as $member) {
            $sets[] = $this->canonical->get($snapshot->tenantId, new D\ChunkSetId($member->get('chunkSetId')));
        }
        $candidate = new D\ManifestCandidate($manifest, $sets, $manifest->toWire()->get('resources'));
        if ($candidate->digest->value !== $row['manifest_digest']
            || $manifest->toWire()->get('generationId') !== $generation->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'manifest_mismatch');
        }
        $this->assertMemberships($snapshot, $generation, $manifest);

        return $this->inventoryBuilder->build($snapshot, $candidate, $this->documents);
    }

    private function assertMemberships(D\SnapshotRef $snapshot, D\GenerationId $generation, D\IndexManifest $manifest): void
    {
        $rows = $this->db->execute('SELECT source_id,source_version_id,revision_id,chunk_set_id FROM '
            . $this->db->table('snapshot_memberships')
            . ' WHERE tenant_id=? AND kb_id=? AND snapshot_id=? AND generation_id=?',
            [$snapshot->tenantId->value, $snapshot->kbId->value, $snapshot->snapshotId->value, $generation->value])
            ->fetchAll(\PDO::FETCH_ASSOC);
        $expected = [];
        foreach ($manifest->toWire()->get('members') as $member) {
            $expected[] = [$member->get('sourceId'), $member->get('sourceVersionId'),
                $member->get('documentRevisionId'), $member->get('chunkSetId')];
        }
        $actual = array_map(static fn (array $row): array => array_values($row), $rows);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'snapshot_membership_mismatch');
        }
    }
}
