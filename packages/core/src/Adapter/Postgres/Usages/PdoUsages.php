<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Usages;

use Closure;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\Catalog\PdoCatalogTransaction;
use KnowKit\Adapter\Postgres\Dependencies\NodeMetadata;
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\Reading\PdoCitations;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\DisclosureStore;

/** Local COMMIT serializes with lifecycle/policy changes. External handoff remains host-owned. */
final readonly class PdoUsages implements DisclosureStore
{
    private const INSERT_SQL = <<<'SQL'
        INSERT INTO %s (tenant_id,id,disclosure_attempt_id,kb_id,actor_id,purpose,run_ref,selection_id,
            host_scope_hash,policy_binding_digest,prepared_at,evidence_digest)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT (tenant_id,disclosure_attempt_id) DO NOTHING
        SQL;

    private const ANCESTORS_SQL = <<<'SQL'
        WITH RECURSIVE ancestors(id) AS (
            SELECT id::text COLLATE "C" FROM %s WHERE tenant_id=? AND id=?
            UNION SELECT e.parent_id::text FROM %s e JOIN ancestors a ON e.child_id=a.id WHERE e.tenant_id=?
        ) SELECT n.source_id,n.source_version_id FROM %s n
        JOIN ancestors a ON a.id=n.id WHERE n.tenant_id=? ORDER BY n.id LIMIT 1025
        SQL;

    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private PdoDependencyStore $dependencies,
    ) {
        if ($tx->db !== $db) {
            throw new \InvalidArgumentException('Shared transaction required');
        }
    }

    public function replay(D\Scope $scope, D\DisclosureRequest $request): ?D\DisclosureReplay
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('evidence_usages')
            . ' WHERE tenant_id=? AND disclosure_attempt_id=?', [$scope->tenantId->value, $request->attemptId()->value]);
        if ($row === null) {
            return null;
        }
        foreach (['kb_id' => $scope->kbId->value, 'actor_id' => $scope->actorId->value,
            'purpose' => $scope->purpose, 'host_scope_hash' => $scope->hostScopeHash->value,
            'selection_id' => $request->selectionId()->value, 'run_ref' => $request->runRef()] as $key => $expected) {
            if ($row[$key] !== $expected) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }
        }

        return new D\DisclosureReplay(new D\UsageId($row['id']), $request->attemptId(),
            self::instant($row['prepared_at']), new D\Sha256($row['evidence_digest']));
    }

    public function plan(D\Scope $scope, D\SelectionRecord $record, D\DisclosureRequest $request): D\DisclosurePlan
    {
        $record->context->scope->assertTenant($scope->tenantId);
        if (Jcs::canonicalize($record->context->scope->toWire()) !== Jcs::canonicalize($scope->toWire())) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }
        $sources = $resources = [];
        foreach ($record->selection->policyBinding()->get('sources') as $source) {
            $id = new D\SourceId($source->get('sourceId'));
            $sources[$id->value] = $id;
        }
        foreach ($request->refs($record) as $ref) {
            $source = $ref->revisionRef->sourceRef;
            $scope->assertTenant($source->tenantId);
            $sources[$source->sourceId->value] = $source->sourceId;
            $this->resource($resources, $scope, 'source_version', $source->sourceVersionId);
            $this->ancestors($scope, new D\ResourceRef($scope->tenantId, 'chunk', $ref->chunkId), $sources, $resources);
            $this->collections($scope, $source->sourceId, $resources);
        }
        ksort($sources, SORT_STRING);
        $binding = $this->binding($scope, array_values($sources));
        if (Jcs::canonicalize($binding) !== Jcs::canonicalize($record->selection->policyBinding())) {
            throw new D\DomainFailure(D\Outcome::Denied, 'POLICY_CHANGED');
        }
        foreach ($sources as $source) {
            $this->resource($resources, $scope, 'source', $source);
        }
        $this->resource($resources, $scope, 'knowledge_base', $scope->kbId);
        ksort($resources, SORT_STRING);

        return new D\DisclosurePlan(array_values($sources), array_values($resources), $binding);
    }

    /**
     * @param Closure(D\DisclosurePlan, D\UtcInstant): D\PreparedDisclosure $assemble
     */
    public function commit(
        D\Scope $scope,
        D\SelectionRecord $record,
        D\DisclosureRequest $request,
        D\DisclosurePlan $plan,
        Closure $assemble,
    ): D\DisclosureOutcome {
        $result = $this->tx->run(function () use ($scope, $record, $request, $plan, $assemble): D\EvidenceSet|D\DisclosureReplay {
            $this->tx->lockTenant($scope->tenantId, D\LockMode::Share);
            $this->tx->lockKb($scope->kbId, D\LockMode::Share);
            $this->tx->lockSources($plan->sources, D\LockMode::Share);
            $this->tx->lockGeneration($record->selection->generationId(), D\LockMode::Share);
            $replay = $this->replay($scope, $request);
            if ($replay !== null) {
                return $replay;
            }
            $current = $this->plan($scope, $record, $request);
            $plan->requireSame($current);
            $this->assertExact($scope, $record, $request);
            $this->requireUsable($scope, $request->refs($record));

            return $this->persist($scope, $record, $request, $current, $assemble);
        });

        /** @var D\Result<D\EvidenceSet|D\DisclosureReplay|D\Navigation\UnitDisclosure> $outcome */
        $outcome = D\Result::success($result);

        return new D\DisclosureOutcome($outcome);
    }

    /**
     * @param Closure(D\DisclosurePlan, D\UtcInstant): D\PreparedDisclosure $assemble
     */
    private function persist(
        D\Scope $scope,
        D\SelectionRecord $record,
        D\DisclosureRequest $request,
        D\DisclosurePlan $current,
        Closure $assemble,
    ): D\EvidenceSet|D\DisclosureReplay {
        $now = self::instant((string) $this->db->execute('SELECT clock_timestamp()')->fetchColumn());
        $prepared = $assemble($current, $now);
        $prepared->requireRequest($record, $request, $now);
        $deadline = $prepared->deadline ?? throw new D\DomainFailure(D\Outcome::Incompatible, 'decision_receipt_required');
        if (!$this->insert($scope, $prepared)) {
            return $this->replay($scope, $request) ?? throw new D\DomainFailure(D\Outcome::Unknown, 'commit_unknown');
        }
        $this->dependencies->recordUsage($prepared->usage);
        (new PdoCitations($this->db))->save($prepared);
        $this->appendEvent($prepared->event);
        $finalNow = self::instant((string) $this->db->execute('SELECT clock_timestamp()')->fetchColumn());
        if ($record->selection->expiresAt()->compare($finalNow) <= 0) {
            throw new D\DomainFailure(D\Outcome::Denied, 'selection_expired');
        }
        $deadline->requireFresh($finalNow);

        return $prepared->evidence;
    }

    private function appendEvent(D\DomainEvent $event): void
    {
        $wire = $event->toWire();
        $this->tx->requireTenant(new D\TenantId($wire->get('tenantId')));
        $this->db->execute('INSERT INTO ' . $this->db->table('outbox')
            . ' (tenant_id,id,event_json,payload_digest,aggregate_version) VALUES (?,?,?::jsonb,?,?)',
            [$wire->get('tenantId'), $wire->get('eventId'), $event->canonical(), $wire->get('payloadDigest'), '1']);
    }

    /** Historical reads use current policy epochs and exact refs, independently of Selection expiry. */
    public function readingPlan(D\Scope $scope, D\ChunkRef $ref): D\DisclosurePlan
    {
        return $this->resourcePlan($scope, $ref->revisionRef->sourceRef,
            new D\ResourceRef($scope->tenantId, 'chunk', $ref->chunkId));
    }

    public function revisionPlan(D\Scope $scope, D\DocumentRevisionRef $revision): D\DisclosurePlan
    {
        return $this->resourcePlan($scope, $revision->sourceRef,
            new D\ResourceRef($scope->tenantId, 'document_revision', $revision->documentRevisionId));
    }

    private function resourcePlan(D\Scope $scope, D\ExactSourceRef $source, D\ResourceRef $root): D\DisclosurePlan
    {
        $scope->assertTenant($source->tenantId);
        $sources = [$source->sourceId->value => $source->sourceId];
        $resources = [];
        $this->resource($resources, $scope, 'source_version', $source->sourceVersionId);
        $this->ancestors($scope, $root, $sources, $resources);
        $this->collections($scope, $source->sourceId, $resources);
        ksort($sources, SORT_STRING);
        foreach ($sources as $id) {
            $this->resource($resources, $scope, 'source', $id);
        }
        ksort($resources, SORT_STRING);

        return new D\DisclosurePlan(array_values($sources), array_values($resources), $this->binding($scope, array_values($sources)));
    }

    /** SQL-only checks under the same tenant/KB/source guards used by Usage commit. */
    public function requireReadable(D\Scope $scope, D\ChunkRef $ref): void
    {
        $metadata = new NodeMetadata($this->db, $this->tx);
        $actual = $metadata->resolve(new D\ResourceRef($scope->tenantId, 'chunk', $ref->chunkId));
        if (Jcs::canonicalize($actual['identity']) !== Jcs::canonicalize($ref->toWire())) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }
        $this->requireUsable($scope, [$ref]);
    }

    public function requireRevisionReadable(D\Scope $scope, D\DocumentRevisionRef $revision): void
    {
        $scope->assertTenant($revision->sourceRef->tenantId);
        $ref = new D\ResourceRef($scope->tenantId, 'document_revision', $revision->documentRevisionId);
        $metadata = new NodeMetadata($this->db, $this->tx);
        if (Jcs::canonicalize($metadata->resolve($ref)['identity']) !== Jcs::canonicalize($revision->toWire())) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }
        $report = $this->dependencies->checkAncestors(new D\NodeRefs($scope->tenantId, [$ref]));
        if ($report->decision !== D\Decision::Allow) {
            throw new D\DomainFailure($report->decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown,
                $report->decision === D\Decision::Deny ? 'ancestor_unusable' : 'ancestor_unknown');
        }
    }

    private function insert(D\Scope $scope, D\PreparedDisclosure $prepared): bool
    {
        $usage = $prepared->usage;
        $scope->assertTenant($usage->tenantId());
        if (!$usage->actorId()->equals($scope->actorId) || $usage->purpose() !== $scope->purpose
            || !$usage->hostScopeHash()->equals($scope->hostScopeHash)) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
        }

        return $this->db->execute(sprintf(self::INSERT_SQL, $this->db->table('evidence_usages')), [
            $scope->tenantId->value, $usage->id()->value, $usage->attemptId()->value,
            $scope->kbId->value, $scope->actorId->value, $scope->purpose, $usage->runRef(),
            $usage->selectionId()->value, $scope->hostScopeHash->value, $usage->policyBindingDigest()->value,
            $usage->checkedAt()->value, $prepared->evidence->digest()->value,
        ])->rowCount() === 1;
    }

    /** @param array<string,D\SourceId> $sources @param array<string,D\ResourceRef> $resources */
    private function ancestors(D\Scope $scope, D\ResourceRef $ref, array &$sources, array &$resources): void
    {
        $sql = sprintf(self::ANCESTORS_SQL, $this->db->table('dependency_nodes'),
            $this->db->table('dependency_edges'), $this->db->table('dependency_nodes'));
        $rows = $this->db->execute($sql, [$scope->tenantId->value, $ref->id->value,
            $scope->tenantId->value, $scope->tenantId->value])->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) > 1024) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        foreach ($rows as $row) {
            $id = new D\SourceId($row['source_id']);
            $sources[$id->value] = $id;
            if ($row['source_version_id'] !== null) {
                $this->resource($resources, $scope, 'source_version', new D\SourceVersionId($row['source_version_id']));
            }
        }
    }

    /** @param array<string,D\ResourceRef> $resources */
    private function collections(D\Scope $scope, D\SourceId $source, array &$resources): void
    {
        $rows = $this->db->execute('SELECT m.collection_id,c.ancestor_id FROM ' . $this->db->table('source_memberships')
            . ' m LEFT JOIN ' . $this->db->table('collection_closure')
            . ' c ON c.tenant_id=m.tenant_id AND c.kb_id=m.kb_id AND c.descendant_id=m.collection_id'
            . ' WHERE m.tenant_id=? AND m.kb_id=? AND m.source_id=? ORDER BY m.collection_id,c.ancestor_id LIMIT 1025',
            [$scope->tenantId->value, $scope->kbId->value, $source->value])->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new D\DomainFailure(D\Outcome::Denied, 'source_not_in_scope');
        }
        if (count($rows) > 1024) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
        }
        foreach ($rows as $row) {
            foreach (array_filter([$row['collection_id'], $row['ancestor_id']]) as $id) {
                $this->resource($resources, $scope, 'collection', new D\CollectionId($id));
            }
        }
    }

    /** @param list<D\SourceId> $sources */
    private function binding(D\Scope $scope, array $sources): JsonObject
    {
        $tenant = $this->db->row('SELECT tenant_policy_epoch,serving_state FROM ' . $this->db->table('tenants') . ' WHERE id=?', [$scope->tenantId->value]);
        $kb = $this->db->row('SELECT kb_policy_epoch,removed_at FROM ' . $this->db->table('knowledge_bases') . ' WHERE tenant_id=? AND id=?',
            [$scope->tenantId->value, $scope->kbId->value]);
        if ($tenant === null || $tenant['serving_state'] !== 'ready' || $kb === null || $kb['removed_at'] !== null) {
            throw new D\DomainFailure(D\Outcome::Denied, 'scope_unusable');
        }
        $epochs = [];
        $byId = [];
        if ($sources !== []) {
            foreach ($this->db->execute(
                'SELECT id,lifecycle_epoch,source_policy_epoch FROM ' . $this->db->table('sources') .
                ' WHERE tenant_id=? AND id IN (SELECT value FROM jsonb_array_elements_text(CAST(? AS jsonb)))',
                [$scope->tenantId->value, json_encode(array_map(static fn (D\SourceId $id): string => $id->value, $sources), JSON_THROW_ON_ERROR)],
            )->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $byId[$row['id']] = $row;
            }
        }
        foreach ($sources as $source) {
            $row = $byId[$source->value] ?? null;
            if ($row === null) {
                throw new D\DomainFailure(D\Outcome::Unknown, 'source_unknown');
            }
            $epochs[] = new JsonObject(['sourceId' => $source->value, 'sourcePolicyEpoch' => (string) $row['source_policy_epoch'],
                'lifecycleEpoch' => (string) $row['lifecycle_epoch']]);
        }

        return new JsonObject(['tenantPolicyEpoch' => (string) $tenant['tenant_policy_epoch'],
            'kbPolicyEpoch' => (string) $kb['kb_policy_epoch'], 'sources' => $epochs]);
    }

    private function assertExact(D\Scope $scope, D\SelectionRecord $record, D\DisclosureRequest $request): void
    {
        $row = $this->db->row('SELECT s.manifest_digest,g.state FROM ' . $this->db->table('publication_snapshots')
            . ' s JOIN ' . $this->db->table('snapshot_generations') . ' sg ON sg.tenant_id=s.tenant_id AND sg.kb_id=s.kb_id AND sg.snapshot_id=s.id'
            . ' JOIN ' . $this->db->table('index_generations') . ' g ON g.tenant_id=sg.tenant_id AND g.kb_id=sg.kb_id AND g.id=sg.generation_id'
            . ' WHERE s.tenant_id=? AND s.kb_id=? AND s.id=? AND sg.generation_id=? AND s.sealed',
            [$scope->tenantId->value, $scope->kbId->value, $record->snapshot->snapshotId->value, $record->selection->generationId()->value]);
        if ($row === null || $row['manifest_digest'] !== $record->snapshot->manifestDigest->value
            || !in_array($row['state'], ['sealed', 'active', 'retired'], true)) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'snapshot_mismatch');
        }
        $metadata = new NodeMetadata($this->db, $this->tx);
        foreach ($request->refs($record) as $ref) {
            $actual = $metadata->resolve(new D\ResourceRef($scope->tenantId, 'chunk', $ref->chunkId));
            if (Jcs::canonicalize($actual['identity']) !== Jcs::canonicalize($ref->toWire())) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
            }
            $this->assertMember($scope, $record, $ref);
        }
    }

    private function assertMember(D\Scope $scope, D\SelectionRecord $record, D\ChunkRef $ref): void
    {
        $row = $this->db->row('SELECT sm.id FROM ' . $this->db->table('snapshot_memberships') . ' sm'
            . ' JOIN ' . $this->db->table('document_revisions') . ' r ON r.tenant_id=sm.tenant_id AND r.id=sm.revision_id'
            . ' JOIN ' . $this->db->table('resource_ledger') . " b ON b.tenant_id=r.tenant_id AND b.id=r.ir_resource_id AND b.state='accepted'"
            . ' JOIN ' . $this->db->table('chunks') . ' c ON c.tenant_id=sm.tenant_id AND c.chunk_set_id=sm.chunk_set_id AND c.id=?'
            . ' JOIN ' . $this->db->table('resource_ledger') . " cb ON cb.tenant_id=c.tenant_id AND cb.id=c.canonical_payload_ref AND cb.state='accepted'"
            . ' WHERE sm.tenant_id=? AND sm.kb_id=? AND sm.snapshot_id=? AND sm.generation_id=?'
            . ' AND sm.source_id=? AND sm.source_version_id=? AND sm.revision_id=? AND sm.chunk_set_id=?',
            [$ref->chunkId->value, $scope->tenantId->value, $scope->kbId->value, $record->snapshot->snapshotId->value,
                $record->selection->generationId()->value, $ref->revisionRef->sourceRef->sourceId->value,
                $ref->revisionRef->sourceRef->sourceVersionId->value, $ref->revisionRef->documentRevisionId->value, $ref->chunkSetId->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'snapshot_membership_mismatch');
        }
    }

    /** @param list<D\ChunkRef> $refs */
    private function requireUsable(D\Scope $scope, array $refs): void
    {
        $nodes = array_map(static fn (D\ChunkRef $ref): D\ResourceRef => new D\ResourceRef($scope->tenantId, 'chunk', $ref->chunkId), $refs);
        $report = $this->dependencies->checkAncestors(new D\NodeRefs($scope->tenantId, $nodes));
        if ($report->decision !== D\Decision::Allow) {
            throw new D\DomainFailure($report->decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown,
                $report->decision === D\Decision::Deny ? 'ancestor_unusable' : 'ancestor_unknown');
        }
    }

    /** @param array<string,D\ResourceRef> $resources */
    private function resource(array &$resources, D\Scope $scope, string $kind, D\Ulid $id): void
    {
        $resources[$kind . ':' . $id->value] = new D\ResourceRef($scope->tenantId, $kind, $id);
    }

    private static function instant(string $value): D\UtcInstant
    {
        return new D\UtcInstant((new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
    }
}
