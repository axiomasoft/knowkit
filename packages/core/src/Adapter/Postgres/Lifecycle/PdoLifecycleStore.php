<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Lifecycle;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, Jcs, JsonObject, SemanticRules, StrictJson};
use KnowKit\Port\{Clock, DependencyStore, IdGenerator, LifecycleCommandStore, Outbox};

/** Adapted from AgenTools PostgresGraph/DeletionWorker at 2054acda51eb69eca1579c78900858c5ff3de01e
 * DeepTutor recovery was evaluated, not imported (see P3.2-reuse.json).
 * Private local reuse: licenses/local-reuse-authorization.md. Caller owns ordered SQL transaction.
 */
final readonly class PdoLifecycleStore implements LifecycleCommandStore
{
    private DependencyStore $dependencies;
    private Outbox $outbox;

    /** @param \Closure(D\TenantId,string,D\Ulid,D\Decimal,string,JsonObject):D\DomainEvent $createEvent
     *  @param null|\Closure(D\Scope,D\ResourceRef,D\Operation,JsonObject):void $checkReceipt */
    public function __construct(
        private Connection $db,
        private PdoCatalogTransaction $tx,
        private D\TenantId $tenant,
        private Clock $clock,
        private IdGenerator $ids,
        private ContractValidator $validator,
        private \Closure $createEvent,
        ?DependencyStore $dependencies = null,
        ?Outbox $outbox = null,
        private ?\Closure $checkReceipt = null,
    ) {
        if ($db !== $tx->db) { throw new \InvalidArgumentException('Shared transaction required'); }
        $this->dependencies = $dependencies ?? new PdoDependencyStore($db, $tx, $tenant, $ids);
        $this->outbox = $outbox ?? new PdoOutbox($db, $tx);
    }

    public function lockWork(D\Scope $scope, D\SourceId $sourceId): void
    {
        $this->guard($scope->tenantId, true);
        $generations = $this->db->execute('SELECT DISTINCT generation_id FROM '.$this->db->table('jobs').
            ' WHERE tenant_id=? AND source_id=? AND generation_id IS NOT NULL ORDER BY generation_id',
            [$this->tenant->value, $sourceId->value])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($generations as $id) { $this->tx->lockGeneration(new D\GenerationId($id), D\LockMode::Update); }
        $jobs = $this->db->execute('SELECT id FROM '.$this->db->table('jobs').
            ' WHERE tenant_id=? AND source_id=? ORDER BY id', [$this->tenant->value, $sourceId->value])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($jobs as $id) { $this->tx->lockJob(new D\JobId($id), D\LockMode::Update); }
    }

    public function getSourceState(D\Scope $scope, D\SourceId $sourceId): ?D\Source
    {
        $this->guard($scope->tenantId, false);
        $r = $this->db->row('SELECT * FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $sourceId->value]);
        return $r === null ? null : new D\Source(
            new D\TenantId($r['tenant_id']),
            new D\SourceId($r['id']),
            D\SourceLifecycleState::from($r['lifecycle_state']),
            new D\Decimal((string)$r['lifecycle_epoch']),
            new D\Decimal((string)$r['source_policy_epoch']),
            (bool)$r['retention_hold'],
            $r['owner_ref'],
            $r['origin'],
        );
    }

    public function getVersionState(D\Scope $scope, D\SourceVersionId $sourceVersionId): ?D\SourceVersionState
    {
        $this->guard($scope->tenantId, false);
        $r = $this->db->row('SELECT st.* FROM '.$this->db->table('source_version_state').' st JOIN '.
            $this->db->table('source_versions').' v ON v.tenant_id=st.tenant_id AND v.id=st.id WHERE st.tenant_id=? AND st.id=?',
            [$this->tenant->value, $sourceVersionId->value]);
        if ($r === null) { return null; }
        $eventId = $r['event_id'];
        if ($eventId === null) {
            $accepted = $this->db->row('SELECT id FROM '.$this->db->table('outbox').
                " WHERE tenant_id=? AND event_json->>'type'='source_version.accepted' AND event_json->>'aggregateId'=? ORDER BY id LIMIT 1",
                [$this->tenant->value, $sourceVersionId->value]);
            $eventId = $accepted['id'] ?? $r['id'];
        }
        return new D\SourceVersionState(
            new D\TenantId($r['tenant_id']),
            new D\SourceVersionId($r['id']),
            D\Usability::from($r['usability']),
            $r['corrected_by_version_id'] === null ? null : new D\SourceVersionId($r['corrected_by_version_id']),
            new D\EventId($eventId),
        );
    }

    public function getTombstone(D\Scope $scope, D\SourceId $sourceId): ?D\Tombstone
    {
        $this->guard($scope->tenantId, false);
        $r = $this->db->row('SELECT * FROM '.$this->db->table('tombstones').' WHERE tenant_id=? AND source_id=?', [$this->tenant->value, $sourceId->value]);
        return $r === null ? null : new D\Tombstone(
            new D\TenantId($r['tenant_id']),
            new D\SourceId($r['source_id']),
            $r['reason'],
            new D\EventId($r['event_id']),
            new D\Decimal((string)$r['lifecycle_epoch']),
        );
    }

    public function correctVersion(D\Scope $scope, D\Correction $correction): bool
    {
        $this->guard($scope->tenantId, true);
        if (!$correction->oldVersion->tenantId->equals($this->tenant)) { self::fail('scope_mismatch', D\Outcome::Denied); }
        $old = $this->db->row('SELECT v.*, st.usability, st.corrected_by_version_id FROM '.$this->db->table('source_versions').' v JOIN '.
            $this->db->table('source_version_state').' st ON st.tenant_id=v.tenant_id AND st.id=v.id WHERE v.tenant_id=? AND v.id=?',
            [$this->tenant->value, $correction->oldVersion->sourceVersionId->value]);
        if ($old === null) { self::fail('resource_missing'); }
        if ($old['source_id'] !== $correction->oldVersion->sourceId->value || $old['source_digest'] !== $correction->oldVersion->sourceDigest->value) {
            self::fail('immutable_conflict');
        }
        $sourceId = $correction->oldVersion->sourceId;
        $source = $this->db->row('SELECT * FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $sourceId->value]);
        $tombstone = $this->db->row('SELECT id FROM '.$this->db->table('tombstones').' WHERE tenant_id=? AND source_id=?', [$this->tenant->value, $sourceId->value]);
        if ($source === null || $tombstone !== null || $source['lifecycle_state'] !== 'active'
            || (string)$source['lifecycle_epoch'] !== $correction->expectedLifecycleEpoch->value || $old['usability'] !== 'usable') {
            return false;
        }
        if ($correction->replacement !== null) {
            $this->assertReplacementReady($correction->oldVersion, $correction->replacement);
        }
        $nextEpoch = $correction->expectedLifecycleEpoch->next();
        $updated = $this->db->execute('UPDATE '.$this->db->table('sources').
            " SET lifecycle_epoch=?, revision=revision+1 WHERE tenant_id=? AND id=? AND lifecycle_epoch=? AND lifecycle_state='active'",
            [$nextEpoch->value, $this->tenant->value, $sourceId->value, $correction->expectedLifecycleEpoch->value])->rowCount() === 1;
        if (!$updated) { return false; }
        $event = $this->event(
            'source_version',
            $correction->oldVersion->sourceVersionId,
            $nextEpoch,
            'source_version.corrected',
            new JsonObject([
                'sourceVersionId' => $correction->oldVersion->sourceVersionId->value,
                'usability' => 'corrected',
                'correctedByVersionId' => $correction->replacement?->sourceVersionId->value,
            ]),
        );
        $this->dependencies->invalidate(new D\LifecycleEvent($event));
        $eventId = $event->toWire()->get('eventId');
        $this->db->execute('UPDATE '.$this->db->table('source_version_state').
            " SET usability='corrected', corrected_by_version_id=?, event_id=? WHERE tenant_id=? AND id=?",
            [$correction->replacement?->sourceVersionId->value, $eventId, $this->tenant->value, $correction->oldVersion->sourceVersionId->value]);
        $this->fenceJobsForVersion($sourceId, $correction->oldVersion->sourceVersionId);
        $memberships = $this->db->execute('SELECT DISTINCT kb_id FROM '.$this->db->table('source_memberships').
            ' WHERE tenant_id=? AND source_id=? ORDER BY kb_id', [$this->tenant->value, $sourceId->value])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($memberships as $kbId) {
            $this->switchAffectedSnapshot(new D\KbId($kbId), $correction->oldVersion->sourceVersionId, $correction->replacement?->sourceVersionId);
        }
        return true;
    }

    public function revokeIdentity(D\Scope $scope, D\Revocation $revocation): bool
    {
        $this->guard($scope->tenantId, true);
        $sourceId = $revocation->sourceId;
        $source = $this->db->row('SELECT * FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $sourceId->value]);
        $tombstone = $this->db->row('SELECT * FROM '.$this->db->table('tombstones').' WHERE tenant_id=? AND source_id=?', [$this->tenant->value, $sourceId->value]);
        if ($source === null) {
            if ($tombstone !== null || $revocation->expectedLifecycleEpoch->value !== '0') { return false; }
            $nextEpoch = new D\Decimal('1');
            $event = $this->event('source', $sourceId, $nextEpoch, 'source.revoked', new JsonObject([
                'sourceId' => $sourceId->value,
                'reason' => $revocation->reason,
                'lifecycleEpoch' => $nextEpoch->value,
                'appliesTo' => 'future_versions',
            ]));
            $this->dependencies->invalidate(new D\LifecycleEvent($event));
            return true;
        }
        if ((string)$source['lifecycle_epoch'] !== $revocation->expectedLifecycleEpoch->value
            || $source['lifecycle_state'] !== 'active' || $tombstone !== null) {
            return false;
        }
        $nextEpoch = $revocation->expectedLifecycleEpoch->next();
        $nextPolicy = $revocation->reason === 'consent_revoked'
            ? (new D\Decimal((string)$source['source_policy_epoch']))->next()
            : new D\Decimal((string)$source['source_policy_epoch']);
        $updated = $this->db->execute('UPDATE '.$this->db->table('sources').
            " SET lifecycle_state='revoked', lifecycle_epoch=?, source_policy_epoch=?, revision=revision+1 WHERE tenant_id=? AND id=? AND lifecycle_epoch=? AND lifecycle_state='active'",
            [$nextEpoch->value, $nextPolicy->value, $this->tenant->value, $sourceId->value, $revocation->expectedLifecycleEpoch->value])->rowCount() === 1;
        if (!$updated) { return false; }
        $event = $this->event('source', $sourceId, $nextEpoch, 'source.revoked', new JsonObject([
            'sourceId' => $sourceId->value,
            'reason' => $revocation->reason,
            'lifecycleEpoch' => $nextEpoch->value,
            'appliesTo' => 'future_versions',
        ]));
        $this->dependencies->invalidate(new D\LifecycleEvent($event));
        $eventId = $event->toWire()->get('eventId');
        $this->db->execute('UPDATE '.$this->db->table('source_version_state').
            " SET usability='revoked', event_id=? WHERE tenant_id=? AND usability='usable' AND id IN (SELECT id FROM ".$this->db->table('source_versions').' WHERE tenant_id=? AND source_id=?)',
            [$eventId, $this->tenant->value, $this->tenant->value, $sourceId->value]);
        $this->fenceJobsForSource($sourceId);
        return true;
    }

    public function setRetentionHold(D\Scope $scope, D\LegalHold $hold): bool
    {
        $this->guard($scope->tenantId, true);
        $sourceId = $hold->sourceId;
        $source = $this->db->row('SELECT * FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $sourceId->value]);
        if ($source === null) { self::fail('resource_missing'); }
        if ((string)$source['lifecycle_epoch'] !== $hold->expectedLifecycleEpoch->value) { return false; }
        if ($hold->enabled && $this->isDeletionAlreadyExecutedOrInFlight($sourceId)) {
            self::fail('HOLD_TOO_LATE');
        }
        $nextEpoch = $hold->expectedLifecycleEpoch->next();
        $updated = $this->db->execute('UPDATE '.$this->db->table('sources').
            ' SET retention_hold=?, lifecycle_epoch=?, revision=revision+1 WHERE tenant_id=? AND id=? AND lifecycle_epoch=?',
            [$hold->enabled, $nextEpoch->value, $this->tenant->value, $sourceId->value, $hold->expectedLifecycleEpoch->value])->rowCount() === 1;
        if (!$updated) { return false; }
        $this->db->execute('UPDATE '.$this->db->table('resource_ledger').
            " SET retention_hold=? WHERE tenant_id=? AND source_id=? AND owner_kind='owned' AND state NOT IN ('deleting','deleted')",
            [$hold->enabled, $this->tenant->value, $sourceId->value]);
        $this->db->execute('UPDATE '.$this->db->table('deletion_tasks').
            " SET hold_reason=? WHERE tenant_id=? AND source_id=? AND status IN ('pending','unknown','unsupported')",
            [$hold->enabled ? 'legal_hold' : null, $this->tenant->value, $sourceId->value]);
        $event = $this->event('source', $sourceId, $nextEpoch, 'retention_hold.changed', new JsonObject([
            'sourceId' => $sourceId->value,
            'retentionHold' => $hold->enabled,
        ]));
        $this->outbox->append($event);
        $w = $event->toWire();
        $this->db->execute('INSERT INTO '.$this->db->table('lifecycle_events').
            ' (tenant_id,id,source_id,source_version_id,type,lifecycle_epoch,payload_digest) VALUES (?,?,?,NULL,?,?,?) ON CONFLICT (tenant_id,id) DO NOTHING',
            [$this->tenant->value, $w->get('eventId'), $sourceId->value, 'retention_hold.changed', $nextEpoch->value, $w->get('payloadDigest')]);
        return true;
    }

    public function purgeSource(D\Scope $scope, D\PurgeRequest $request): ?D\DeletionTaskId
    {
        $this->guard($scope->tenantId, true);
        $sourceId = $request->sourceId;
        $source = $this->db->row('SELECT * FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $sourceId->value]);
        if ($source === null) { self::fail('resource_missing'); }
        if ((string)$source['lifecycle_epoch'] !== $request->expectedLifecycleEpoch->value) { return null; }
        $nextEpoch = $request->expectedLifecycleEpoch->next();
        $updated = $this->db->execute('UPDATE '.$this->db->table('sources').
            " SET lifecycle_state='deletion_requested', lifecycle_epoch=?, revision=revision+1 WHERE tenant_id=? AND id=? AND lifecycle_epoch=?",
            [$nextEpoch->value, $this->tenant->value, $sourceId->value, $request->expectedLifecycleEpoch->value])->rowCount() === 1;
        if (!$updated) { return null; }
        $event = $this->event('source', $sourceId, $nextEpoch, 'source.deletion_requested', new JsonObject([
            'sourceId' => $sourceId->value,
            'lifecycleState' => 'deletion_requested',
            'lifecycleEpoch' => $nextEpoch->value,
        ]));
        $this->dependencies->invalidate(new D\LifecycleEvent($event));
        $eventId = $event->toWire()->get('eventId');
        $this->db->execute('UPDATE '.$this->db->table('source_version_state').
            " SET usability='revoked', event_id=? WHERE tenant_id=? AND usability='usable' AND id IN (SELECT id FROM ".$this->db->table('source_versions').' WHERE tenant_id=? AND source_id=?)',
            [$eventId, $this->tenant->value, $this->tenant->value, $sourceId->value]);
        $this->fenceJobsForSource($sourceId);
        $resources = $this->db->execute('SELECT * FROM '.$this->db->table('resource_ledger').
            " WHERE tenant_id=? AND source_id=? AND owner_kind='owned' AND state<>'deleted' ORDER BY id",
            [$this->tenant->value, $sourceId->value])->fetchAll(\PDO::FETCH_ASSOC);
        $required = array_map(function (array $r): JsonObject {
            $class = 'provider_resource';
            foreach (['source_versions'=>['blob_resource_id','original'], 'document_revisions'=>['ir_resource_id','ir'],
                'segments'=>['text_resource_id','canonical_slice']] as $table => [$column, $resourceClass]) {
                if ($this->db->row('SELECT id FROM '.$this->db->table($table).' WHERE tenant_id=? AND '.$column.'=? LIMIT 1',
                    [$this->tenant->value, $r['id']]) !== null) { $class = $resourceClass; break; }
            }
            if ($r['staging_id'] !== null) { $class = 'upload_reservation'; }
            return new JsonObject(['resourceId'=>$r['id'], 'resourceClass'=>$class, 'ownerKind'=>'owned',
                'state'=>$r['state'] === 'unknown' ? 'unknown' : 'pending']);
        }, $resources);
        $taskId = new D\DeletionTaskId($this->ids->next()->value);
        $inventoryDigest = HashDomain::ResourceInventory->digest($required);
        $holdReason = $source['retention_hold'] ? 'legal_hold' : null;
        $status = $required === [] ? 'pending' : SemanticRules::deletionStatus(array_map(static fn (JsonObject $r) => $r->get('state'), $required));
        $taskWire = new JsonObject([
            'taskId' => $taskId->value,
            'tenantId' => $this->tenant->value,
            'sourceId' => $sourceId->value,
            'status' => $status,
            'requiredResources' => $required,
            'inventoryVersion' => $nextEpoch->value,
            'inventoryDigest' => $inventoryDigest,
            'holdReason' => $holdReason,
        ]);
        // An empty owned inventory is still pending, never proof of physical erasure.
        if ($required !== []) { $this->validator->decodeValid(Jcs::canonicalize($taskWire), 'urn:knowkit:schema:deletion-task:1.0'); }
        $this->db->execute('INSERT INTO '.$this->db->table('deletion_tasks').
            " (tenant_id,id,source_id,status,required_resources_json,inventory_epoch,inventory_digest,hold_reason) VALUES (?,?,?,?,?::jsonb,?,?,?)",
            [$this->tenant->value, $taskId->value, $sourceId->value, $status, Jcs::canonicalize($required), $nextEpoch->value, $inventoryDigest, $holdReason]);
        // The existing inventory_resources trigger binds every frozen member to this task/source.
        return $taskId;
    }

    /**
     * @param \Closure():JsonObject $mutate
     */
    public function executeIdempotent(
        D\Scope $scope,
        D\SourceId $sourceId,
        D\Operation $operation,
        D\IdempotencyKey $key,
        JsonObject $businessFields,
        ?D\Decimal $expectedEpoch,
        \Closure $mutate,
    ): JsonObject {
        $this->guard($scope->tenantId, true);
        $this->authorize($scope, new D\ResourceRef($scope->tenantId, 'source', $sourceId), $operation);
        $fingerprint = new D\Fingerprint(HashDomain::IdempotencyRequest->digest(new JsonObject([
            'operation' => $operation->value,
            'businessFields' => $businessFields,
            'expectedRevision' => null,
            'expectedEpoch' => $expectedEpoch?->value,
        ])));
        $stored = $this->db->row('SELECT * FROM '.$this->db->table('idempotency_receipts').' WHERE tenant_id=? AND key=?',
            [$this->tenant->value, $key->value]);
        if ($stored !== null) {
            if ($stored['operation'] !== $operation->value || !hash_equals($fingerprint->value, $stored['request_fingerprint'])) {
                self::fail('idempotency_conflict');
            }
            return StrictJson::decode($stored['response_blob']);
        }
        $response = $mutate();
        $this->db->execute('INSERT INTO '.$this->db->table('idempotency_receipts').
            ' (tenant_id,id,key,operation,request_fingerprint,response_blob,accepted_at) VALUES (?,?,?,?,?,?::jsonb,?)',
            [$this->tenant->value, $this->ids->next()->value, $key->value, $operation->value, $fingerprint->value, Jcs::canonicalize($response), $this->clock->now()->value]);
        return $response;
    }

    public function activateSnapshot(D\Scope $scope, D\SourceVersionRef $version, D\SnapshotId $snapshot,
        D\Decimal $expectedLifecycleEpoch, D\Decimal $expectedPointerVersion): void
    {
        $this->guard($scope->tenantId, true);
        $scope->assertTenant($version->tenantId);
        $source = $this->getSourceState($scope, $version->sourceId);
        if ($source === null || $source->lifecycleEpoch->value !== $expectedLifecycleEpoch->value) { self::fail('POLICY_CHANGED', D\Outcome::Denied); }
        $row = $this->db->row('SELECT source_id, source_digest FROM '.$this->db->table('source_versions').
            ' WHERE tenant_id=? AND id=?', [$this->tenant->value, $version->sourceVersionId->value]);
        if ($row === null || $row['source_id'] !== $version->sourceId->value || $row['source_digest'] !== $version->sourceDigest->value) {
            self::fail('immutable_conflict');
        }
        $candidate = $this->db->row('SELECT manifest_digest FROM '.$this->db->table('publication_snapshots').
            ' WHERE tenant_id=? AND kb_id=? AND id=? AND sealed', [$this->tenant->value, $scope->kbId->value, $snapshot->value]);
        $containsVersion = $this->db->row('SELECT id FROM '.$this->db->table('snapshot_memberships').
            ' WHERE tenant_id=? AND kb_id=? AND snapshot_id=? AND source_version_id=? LIMIT 1',
            [$this->tenant->value, $scope->kbId->value, $snapshot->value, $version->sourceVersionId->value]);
        if ($candidate === null || $containsVersion === null) { self::fail('replacement_not_ready'); }
        $invalid = $this->db->row('SELECT m.id FROM '.$this->db->table('snapshot_memberships').' m JOIN '.
            $this->db->table('sources').' s ON s.tenant_id=m.tenant_id AND s.id=m.source_id JOIN '.
            $this->db->table('source_version_state').' st ON st.tenant_id=m.tenant_id AND st.id=m.source_version_id'.
            " WHERE m.tenant_id=? AND m.snapshot_id=? AND (s.lifecycle_state<>'active' OR st.usability<>'usable' OR EXISTS (SELECT 1 FROM ".
            $this->db->table('tombstones').' t WHERE t.tenant_id=m.tenant_id AND t.source_id=m.source_id)) LIMIT 1',
            [$this->tenant->value, $snapshot->value]);
        if ($invalid !== null) { self::fail('source_unusable', D\Outcome::Denied); }
        $this->requireSnapshotAncestors($snapshot);
        if (!$this->tx->casPointer($scope->kbId, $snapshot, $expectedPointerVersion)) { self::fail('POLICY_CHANGED', D\Outcome::Denied); }
        $this->outbox->append($this->event('knowledge_base', $scope->kbId, $expectedPointerVersion->next(), 'snapshot.activated', new JsonObject([
            'kbId'=>$scope->kbId->value, 'snapshotId'=>$snapshot->value, 'manifestDigest'=>$candidate['manifest_digest'],
            'pointerVersion'=>$expectedPointerVersion->next()->value,
        ])));
    }

    private function authorize(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): void
    {
        $tenant = $this->db->row('SELECT tenant_policy_epoch, serving_state FROM '.$this->db->table('tenants').' WHERE id=?', [$this->tenant->value]);
        if ($tenant === null || $tenant['serving_state'] !== 'ready') { self::fail('tenant_blocked', D\Outcome::Unavailable); }
        $kb = $this->db->row('SELECT kb_policy_epoch, removed_at FROM '.$this->db->table('knowledge_bases').' WHERE tenant_id=? AND id=?',
            [$this->tenant->value, $scope->kbId->value]);
        if (($kb['removed_at'] ?? null) !== null && $operation !== D\Operation::LifecycleDelete) {
            self::fail('knowledge_base_removed', D\Outcome::Denied);
        }
        if ($this->checkReceipt === null) { self::fail('policy_unknown', D\Outcome::Unknown); }
        $sources = [];
        if ($resource->kind === 'source') {
            $src = $this->db->row('SELECT lifecycle_epoch, source_policy_epoch FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?',
                [$this->tenant->value, $resource->id->value]);
            if ($src !== null) {
                $sources[] = new JsonObject([
                    'sourceId' => $resource->id->value,
                    'lifecycleEpoch' => (string)$src['lifecycle_epoch'],
                    'sourcePolicyEpoch' => (string)$src['source_policy_epoch'],
                ]);
            }
        }
        $binding = new JsonObject([
            'tenantPolicyEpoch' => (string)$tenant['tenant_policy_epoch'],
            'kbPolicyEpoch' => (string)($kb['kb_policy_epoch'] ?? '0'),
            'sources' => $sources,
        ]);
        ($this->checkReceipt)($scope, $resource, $operation, $binding);
    }

    private function assertReplacementReady(D\SourceVersionRef $oldVersion, D\SourceVersionRef $replacement): void
    {
        if (!$replacement->tenantId->equals($this->tenant)) { self::fail('scope_mismatch', D\Outcome::Denied); }
        if (!$replacement->sourceId->equals($oldVersion->sourceId) || $replacement->sourceVersionId->equals($oldVersion->sourceVersionId)) {
            self::fail('immutable_conflict');
        }
        $rep = $this->db->row('SELECT v.*, st.usability, r.state AS blob_state FROM '.$this->db->table('source_versions').' v JOIN '.
            $this->db->table('source_version_state').' st ON st.tenant_id=v.tenant_id AND st.id=v.id JOIN '.
            $this->db->table('resource_ledger').' r ON r.tenant_id=v.tenant_id AND r.id=v.blob_resource_id WHERE v.tenant_id=? AND v.id=?',
            [$this->tenant->value, $replacement->sourceVersionId->value]);
        if ($rep === null) { self::fail('replacement_not_ready'); }
        if ($rep['source_id'] !== $replacement->sourceId->value || $rep['source_digest'] !== $replacement->sourceDigest->value) {
            self::fail('immutable_conflict');
        }
        if ($rep['usability'] !== 'usable' || $rep['blob_state'] !== 'accepted') { self::fail('replacement_not_ready'); }
        $pendingJob = $this->db->row('SELECT id FROM '.$this->db->table('jobs').
            " WHERE tenant_id=? AND source_version_id=? AND state IN ('pending','leased','failed') LIMIT 1",
            [$this->tenant->value, $replacement->sourceVersionId->value]);
        if ($pendingJob !== null) { self::fail('replacement_not_ready'); }
        $unknownCoverage = $this->db->row('SELECT id FROM '.$this->db->table('document_revisions').
            " WHERE tenant_id=? AND source_version_id=? AND coverage_state='unknown' LIMIT 1",
            [$this->tenant->value, $replacement->sourceVersionId->value]);
        if ($unknownCoverage !== null) { self::fail('replacement_not_ready'); }
        $oldHasRevisions = $this->db->row('SELECT id FROM '.$this->db->table('document_revisions').
            ' WHERE tenant_id=? AND source_version_id=? LIMIT 1',
            [$this->tenant->value, $oldVersion->sourceVersionId->value]) !== null;
        if ($oldHasRevisions) {
            $repHasExtracted = $this->db->row('SELECT c.id FROM '.$this->db->table('chunks').' c JOIN '.
                $this->db->table('chunk_sets').' cs ON cs.tenant_id=c.tenant_id AND cs.id=c.chunk_set_id JOIN '.
                $this->db->table('document_revisions').' dr ON dr.tenant_id=cs.tenant_id AND dr.id=cs.revision_id WHERE dr.tenant_id=? AND dr.source_version_id=? LIMIT 1',
                [$this->tenant->value, $replacement->sourceVersionId->value]) !== null;
            if (!$repHasExtracted) { self::fail('replacement_not_ready'); }
        }
    }

    private function isDeletionAlreadyExecutedOrInFlight(D\SourceId $sourceId): bool
    {
        if ($this->db->row('SELECT id FROM '.$this->db->table('resource_ledger').
            " WHERE tenant_id=? AND source_id=? AND owner_kind='owned' AND state IN ('deleting','deleted') LIMIT 1",
            [$this->tenant->value, $sourceId->value]) !== null) { return true; }
        if ($this->db->row('SELECT a.id FROM '.$this->db->table('deletion_attempts').' a JOIN '.
            $this->db->table('resource_ledger').' r ON r.tenant_id=a.tenant_id AND r.id=a.resource_id'.
            " WHERE a.tenant_id=? AND r.source_id=? AND (a.in_flight OR a.result IN ('deleted','absent')) LIMIT 1",
            [$this->tenant->value, $sourceId->value]) !== null) { return true; }
        return $this->db->row('SELECT id FROM '.$this->db->table('deletion_tasks').
            " WHERE tenant_id=? AND source_id=? AND status IN ('done','partial') LIMIT 1",
            [$this->tenant->value, $sourceId->value]) !== null;
    }

    private function fenceJobsForVersion(D\SourceId $sourceId, D\SourceVersionId $versionId): void
    {
        $jobs = $this->db->execute('SELECT id, fence FROM '.$this->db->table('jobs').
            " WHERE tenant_id=? AND source_id=? AND source_version_id=? AND state IN ('pending','leased') ORDER BY id FOR UPDATE",
            [$this->tenant->value, $sourceId->value, $versionId->value])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($jobs as $job) { $this->cancelJobRow($job); }
    }

    private function fenceJobsForSource(D\SourceId $sourceId): void
    {
        $jobs = $this->db->execute('SELECT id, fence FROM '.$this->db->table('jobs').
            " WHERE tenant_id=? AND source_id=? AND state IN ('pending','leased') ORDER BY id FOR UPDATE",
            [$this->tenant->value, $sourceId->value])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($jobs as $job) { $this->cancelJobRow($job); }
        $writers = $this->db->execute('SELECT w.id, w.resource_id FROM '.$this->db->table('resource_writers').' w JOIN '.
            $this->db->table('resource_ledger').' r ON r.tenant_id=w.tenant_id AND r.id=w.resource_id'.
            " WHERE w.tenant_id=? AND r.source_id=? AND w.state='active' ORDER BY w.id FOR UPDATE",
            [$this->tenant->value, $sourceId->value])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($writers as $w) {
            $this->db->execute('UPDATE '.$this->db->table('resource_writers')." SET state='uncertain' WHERE tenant_id=? AND id=? AND state='active'",
                [$this->tenant->value, $w['id']]);
            $this->db->execute('UPDATE '.$this->db->table('resource_ledger').
                " SET state=CASE WHEN state IN ('accepted','deleting','deleted') THEN state ELSE 'unknown' END WHERE tenant_id=? AND id=?",
                [$this->tenant->value, $w['resource_id']]);
        }
    }

    private function cancelJobRow(array $job): void
    {
        $nextFence = (new D\Decimal((string)$job['fence']))->next();
        $writers = $this->db->execute('SELECT id, resource_id FROM '.$this->db->table('resource_writers').
            " WHERE tenant_id=? AND job_id=? AND state='active' ORDER BY id FOR UPDATE",
            [$this->tenant->value, $job['id']])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($writers as $w) {
            $this->db->execute('UPDATE '.$this->db->table('resource_writers')." SET state='uncertain' WHERE tenant_id=? AND id=? AND state='active'",
                [$this->tenant->value, $w['id']]);
            $this->db->execute('UPDATE '.$this->db->table('resource_ledger').
                " SET state=CASE WHEN state IN ('accepted','deleting','deleted') THEN state ELSE 'unknown' END WHERE tenant_id=? AND id=?",
                [$this->tenant->value, $w['resource_id']]);
        }
        $this->db->execute('UPDATE '.$this->db->table('jobs').
            " SET state='cancelled', cancel_requested=true, fence=?, uncertain_external_effect=uncertain_external_effect OR state='leased' OR ? WHERE tenant_id=? AND id=? AND state IN ('pending','leased')",
            [$nextFence->value, $writers !== [], $this->tenant->value, $job['id']]);
    }

    private function switchAffectedSnapshot(D\KbId $kbId, D\SourceVersionId $oldVersionId, ?D\SourceVersionId $replacementId): void
    {
        $kb = $this->db->row('SELECT active_snapshot_id, pointer_version FROM '.$this->db->table('knowledge_bases').
            ' WHERE tenant_id=? AND id=? AND removed_at IS NULL', [$this->tenant->value, $kbId->value]);
        if ($kb === null || $kb['active_snapshot_id'] === null) { return; }
        $containsOld = $this->db->row('SELECT id FROM '.$this->db->table('snapshot_memberships').
            ' WHERE tenant_id=? AND kb_id=? AND snapshot_id=? AND source_version_id=? LIMIT 1',
            [$this->tenant->value, $kbId->value, $kb['active_snapshot_id'], $oldVersionId->value]) !== null;
        if (!$containsOld) { return; }
        $candidate = $this->findReplacementSnapshot($kbId, new D\SnapshotId($kb['active_snapshot_id']), $oldVersionId, $replacementId);
        if ($candidate === null) { return; }
        // An unusable derivative is not a ready replacement, even before propagation catches up.
        $this->requireSnapshotAncestors(new D\SnapshotId($candidate['id']));
        $expectedPointer = new D\Decimal((string)$kb['pointer_version']);
        if (!$this->tx->casPointer($kbId, new D\SnapshotId($candidate['id']), $expectedPointer)) { return; }
        $nextPointer = $expectedPointer->next();
        $this->outbox->append($this->event('knowledge_base', $kbId, $nextPointer, 'snapshot.activated', new JsonObject([
            'kbId' => $kbId->value,
            'snapshotId' => $candidate['id'],
            'manifestDigest' => $candidate['manifest_digest'],
            'pointerVersion' => $nextPointer->value,
        ])));
    }

    private function requireSnapshotAncestors(D\SnapshotId $snapshot): void
    {
        $rows = $this->db->execute('SELECT DISTINCT source_version_id FROM '.$this->db->table('snapshot_memberships').
            ' WHERE tenant_id=? AND snapshot_id=? ORDER BY source_version_id', [$this->tenant->value, $snapshot->value])->fetchAll(\PDO::FETCH_COLUMN);
        $refs = array_map(fn ($id) => new D\ResourceRef($this->tenant, 'source_version', new D\SourceVersionId($id)), $rows);
        $report = $this->dependencies->checkAncestors(new D\NodeRefs($this->tenant, $refs));
        if ($report->decision !== D\Decision::Allow) {
            self::fail($report->decision === D\Decision::Deny ? 'source_unusable' : 'ancestor_unknown',
                $report->decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown);
        }
    }

    private function findReplacementSnapshot(D\KbId $kbId, D\SnapshotId $activeId, D\SourceVersionId $oldVersionId, ?D\SourceVersionId $replacementId): ?array
    {
        if ($replacementId === null) { return null; } // Urgent invalidation never guesses a new default.
        $params = [$this->tenant->value, $kbId->value, $activeId->value, $oldVersionId->value,
            $replacementId->value, $activeId->value, $oldVersionId->value,
            $activeId->value, $oldVersionId->value, $replacementId->value];
        $requireReplacement = ' AND EXISTS (SELECT 1 FROM '.$this->db->table('snapshot_memberships').
            ' mr WHERE mr.tenant_id=ps.tenant_id AND mr.snapshot_id=ps.id AND mr.source_version_id=?)';
        $params[] = $replacementId->value;
        return $this->db->row('SELECT ps.id, ps.manifest_digest FROM '.$this->db->table('publication_snapshots').' ps'.
            ' WHERE ps.tenant_id=? AND ps.kb_id=? AND ps.sealed AND ps.id<>?'.
            ' AND NOT EXISTS (SELECT 1 FROM '.$this->db->table('snapshot_memberships').' m WHERE m.tenant_id=ps.tenant_id AND m.snapshot_id=ps.id AND m.source_version_id=?)'.
            ' AND NOT EXISTS (SELECT 1 FROM '.$this->db->table('snapshot_memberships').' m JOIN '.$this->db->table('source_version_state').
            " st ON st.tenant_id=m.tenant_id AND st.id=m.source_version_id JOIN ".$this->db->table('sources').
            " s ON s.tenant_id=m.tenant_id AND s.id=m.source_id WHERE m.tenant_id=ps.tenant_id AND m.snapshot_id=ps.id AND (st.usability<>'usable' OR s.lifecycle_state<>'active'))".
            ' AND NOT EXISTS (SELECT 1 FROM '.$this->db->table('snapshot_memberships').' m JOIN '.$this->db->table('tombstones').
            ' t ON t.tenant_id=m.tenant_id AND t.source_id=m.source_id WHERE m.tenant_id=ps.tenant_id AND m.snapshot_id=ps.id)'.
            // Preserve every unaffected immutable content member in both directions.
            ' AND NOT EXISTS ((SELECT source_id,source_version_id,revision_id,chunk_set_id,profile_id FROM '.$this->db->table('snapshot_memberships').
            ' WHERE tenant_id=ps.tenant_id AND snapshot_id=ps.id AND source_version_id<>?) EXCEPT (SELECT source_id,source_version_id,revision_id,chunk_set_id,profile_id FROM '.
            $this->db->table('snapshot_memberships').' WHERE tenant_id=ps.tenant_id AND snapshot_id=? AND source_version_id<>?))'.
            ' AND NOT EXISTS ((SELECT source_id,source_version_id,revision_id,chunk_set_id,profile_id FROM '.$this->db->table('snapshot_memberships').
            ' WHERE tenant_id=ps.tenant_id AND snapshot_id=? AND source_version_id<>?) EXCEPT (SELECT source_id,source_version_id,revision_id,chunk_set_id,profile_id FROM '.
            $this->db->table('snapshot_memberships').' WHERE tenant_id=ps.tenant_id AND snapshot_id=ps.id AND source_version_id<>?))'.
            $requireReplacement.' ORDER BY ps.created_at DESC, ps.id DESC LIMIT 1', $params);
    }

    private function event(string $aggregateType, D\Ulid $aggregateId, D\Decimal $aggregateVersion, string $type, JsonObject $payload): D\DomainEvent
    {
        return ($this->createEvent)($this->tenant, $aggregateType, $aggregateId, $aggregateVersion, $type, $payload);
    }

    private function guard(D\TenantId $tenant, bool $write): void
    {
        if (!$tenant->equals($this->tenant)) { self::fail('scope_mismatch', D\Outcome::Denied); }
        $this->tx->requireTenant($tenant, $write);
    }

    private static function fail(string $code, D\Outcome $outcome = D\Outcome::Incompatible): never
    {
        throw new D\DomainFailure($outcome, $code);
    }
}
