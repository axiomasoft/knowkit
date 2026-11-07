<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Deletion;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, HashDomain, Jcs, JsonObject, SemanticRules, StrictJson};
use KnowKit\Port\{Clock, DeletionStore};

/** AgenTools durable-attempt pattern adapted to the KnowKit tenant/source ledger and frozen inventory. */
final readonly class PdoDeletionStore implements DeletionStore
{
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx, private D\TenantId $tenant,
        private Clock $clock, private ContractValidator $validator)
    { if ($db !== $tx->db) { throw new \InvalidArgumentException('Shared transaction required'); } }

    private function scoped(D\TenantId $tenant): void
    { if (!$tenant->equals($this->tenant)) { throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch'); } }

    private static function fail(string $code): never { throw new D\DomainFailure(D\Outcome::Incompatible, $code); }

    private function guarded(D\TenantId $tenant, D\DeletionTaskId $task, \Closure $effect): mixed
    {
        $this->scoped($tenant);
        return $this->tx->run(function () use ($task, $effect) {
            $this->tx->lockTenant($this->tenant, D\LockMode::Update);
            $t = $this->db->row('SELECT * FROM '.$this->db->table('deletion_tasks').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $task->value]);
            if ($t === null) { self::fail('deletion_task_missing'); }
            $this->tx->lockSources([new D\SourceId($t['source_id'])], D\LockMode::Update);
            $source = $this->db->row('SELECT * FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $t['source_id']]);
            if ($source['lifecycle_state'] === 'active' || $this->db->row('SELECT id FROM '.$this->db->table('tombstones').
                ' WHERE tenant_id=? AND source_id=?', [$this->tenant->value, $t['source_id']]) === null) { self::fail('logical_revoke_required'); }
            $frozen = StrictJson::decode($t['required_resources_json']);
            if (HashDomain::ResourceInventory->digest($frozen) !== $t['inventory_digest']) { self::fail('inventory_mismatch'); }
            $members = $this->db->execute('SELECT resource_id FROM '.$this->db->table('deletion_task_resources').
                ' WHERE tenant_id=? AND task_id=? ORDER BY resource_id', [$this->tenant->value, $task->value])->fetchAll(\PDO::FETCH_COLUMN);
            if ($members !== array_map(static fn (JsonObject $r) => $r->get('resourceId'), $frozen)) { self::fail('inventory_mismatch'); }
            return $effect($t, $source, $frozen);
        });
    }

    private function target(array $t, array $r, array $frozen): D\DeletionTarget
    {
        $member = null;
        foreach ($frozen as $ref) { if ($ref->get('resourceId') === $r['id']) { $member = $ref; break; } }
        if ($member === null || $r['source_id'] !== $t['source_id'] || $r['owner_kind'] !== 'owned') { self::fail('inventory_mismatch'); }
        return new D\DeletionTarget($this->tenant, new D\SourceId($r['source_id']), new D\DeletionTaskId($t['id']),
            new D\ResourceId($r['id']), $r['storage_id'], $r['storage_key'], $r['object_version'], $r['staging_id'], $member->get('resourceClass'));
    }

    private function resource(D\ResourceId $id): array
    {
        $r = $this->db->row('SELECT * FROM '.$this->db->table('resource_ledger').' WHERE tenant_id=? AND id=? FOR UPDATE', [$this->tenant->value, $id->value]);
        if ($r === null) { self::fail('resource_missing'); }
        return $r;
    }

    private function exact(array $a, D\DeletionTarget $target): void
    {
        if ($a['task_id'] !== $target->taskId->value || $a['resource_id'] !== $target->resourceId->value
            || $a['storage_key'] !== $target->storageKey || $a['object_version'] !== $target->objectVersion
            || $a['staging_id'] !== $target->stagingId) { self::fail('deletion_attempt_mismatch'); }
    }

    private function receipt(array $a): D\DeletionReceipt
    {
        $provider = $a['provider_receipt_json'] === null ? null : json_decode($a['provider_receipt_json'], true, 8, JSON_THROW_ON_ERROR);
        return new D\DeletionReceipt(new JsonObject(['attemptId'=>$a['id'], 'taskId'=>$a['task_id'], 'resourceId'=>$a['resource_id'],
            'inFlight'=>false, 'result'=>$a['result'] ?? 'unknown', 'providerReceipt'=>$provider,
            'knownExpiry'=>self::instant($a['known_expiry']), 'observedAt'=>self::instant($a['observed_at']) ?? $this->clock->now()->value]), $this->validator);
    }

    private static function instant(?string $value): ?string
    {
        if ($value === null) { return null; }
        $date = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        return $date->format('Y-m-d\TH:i:s').($date->format('u') === '000000' ? '' : '.'.$date->format('u')).'Z';
    }

    public function beginAttempt(D\TenantId $tenant, D\DeletionTaskId $task, D\ResourceId $resource, D\AttemptId $attempt): D\DeletionStart
    {
        return $this->guarded($tenant, $task, function ($t, $s, $frozen) use ($resource, $attempt) {
            $r = $this->resource($resource); $target = $this->target($t, $r, $frozen);
            $a = $this->db->row('SELECT * FROM '.$this->db->table('deletion_attempts').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $attempt->value]);
            if ($a !== null) { $this->exact($a, $target); return new D\DeletionStart($target, $this->receipt($a)); }
            if ($s['retention_hold'] || $r['retention_hold']) { self::fail('retention_hold'); }
            if ($r['retention_until'] !== null && new \DateTimeImmutable($r['retention_until']) > new \DateTimeImmutable($this->clock->now()->value)) { self::fail('backup_retention'); }
            if ($this->db->row('SELECT id FROM '.$this->db->table('resource_writers')." WHERE tenant_id=? AND resource_id=? AND state<>'closed' LIMIT 1",
                [$this->tenant->value, $resource->value]) !== null) { self::fail('writer_uncertain'); }
            $this->db->execute('INSERT INTO '.$this->db->table('deletion_attempts').
                ' (tenant_id,id,task_id,resource_id,in_flight,storage_key,object_version,staging_id) VALUES (?,?,?,?,true,?,?,?)',
                [$this->tenant->value, $attempt->value, $t['id'], $r['id'], $r['storage_key'], $r['object_version'], $r['staging_id']]);
            // A new AttemptId requires fresh transport proof even for a previously deleted resource.
            // Historical success cannot establish the outcome of this attempt or supersede later uncertainty.
            // Revocation made all references to this source unreadable; preserve identity rows, release byte references.
            $this->db->execute('UPDATE '.$this->db->table('resource_ledger').
                " SET ref_count=0,state=CASE WHEN digest IS NULL THEN 'unknown' ELSE 'deleting' END WHERE tenant_id=? AND id=?", [$this->tenant->value, $r['id']]);
            return new D\DeletionStart($target);
        });
    }

    public function finishAttempt(D\DeletionTarget $target, D\AttemptId $attempt, D\DeletionReceipt $receipt, ?string $errorCode = null): D\DeletionReceipt
    {
        if ($errorCode !== null && !in_array($errorCode, ['DELETION_TRANSPORT_EXCEPTION','DELETION_RECEIPT_MISMATCH','DELETION_UNPROVEN','DELETION_INTERRUPTED'], true)) {
            throw new \InvalidArgumentException('Safe deletion error code required');
        }
        return $this->guarded($target->tenantId, $target->taskId, function ($t, $s, $frozen) use ($target, $attempt, $receipt, $errorCode) {
            $r = $this->resource($target->resourceId);
            if ($this->target($t, $r, $frozen) != $target) { self::fail('deletion_attempt_mismatch'); }
            $a = $this->db->row('SELECT * FROM '.$this->db->table('deletion_attempts').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $attempt->value]);
            if ($a === null) { self::fail('deletion_attempt_missing'); } $this->exact($a, $target);
            if ($a['result'] !== null) { return $this->receipt($a); }
            $w = $receipt->toWire();
            if ($w->get('attemptId') !== $attempt->value || $w->get('taskId') !== $target->taskId->value || $w->get('resourceId') !== $r['id']
                || $w->get('inFlight') || $w->get('result') === null) { self::fail('deletion_receipt_mismatch'); }
            $result = $w->get('result');
            $proof = in_array($result, ['deleted','absent'], true) && $errorCode === null && $w->get('providerReceipt') !== null;
            if ($proof && (new \DateTimeImmutable($w->get('observedAt')) > new \DateTimeImmutable($this->clock->now()->value)
                || ($r['digest'] === null && ($result !== 'absent' || $r['staging_id'] === null))
                || ($r['retention_until'] !== null && new \DateTimeImmutable($r['retention_until']) > new \DateTimeImmutable($w->get('observedAt')))
                || ($target->resourceClass === 'backup_obligation' && ($w->get('knownExpiry') === null
                    || new \DateTimeImmutable($w->get('knownExpiry')) > new \DateTimeImmutable($w->get('observedAt')))))) {
                $proof = false; $errorCode = 'DELETION_UNPROVEN';
            }
            if (in_array($result, ['deleted','absent'], true) && !$proof) { $result = 'unknown'; $errorCode ??= 'DELETION_UNPROVEN'; }
            $this->db->execute('UPDATE '.$this->db->table('deletion_attempts').
                ' SET in_flight=false,result=?,provider_receipt_json=?::jsonb,known_expiry=?,observed_at=?,error_code=?,exact_version_proof=? WHERE tenant_id=? AND id=?',
                [$result, $w->get('providerReceipt') === null ? null : Jcs::canonicalize($w->get('providerReceipt')),
                    $w->get('knownExpiry'), $w->get('observedAt'), $errorCode, $proof, $this->tenant->value, $attempt->value]);
            if ($proof) { $this->db->execute('UPDATE '.$this->db->table('resource_ledger')." SET state='deleted' WHERE tenant_id=? AND id=?", [$this->tenant->value, $r['id']]); }
            $a = $this->db->row('SELECT * FROM '.$this->db->table('deletion_attempts').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $attempt->value]);
            return $this->receipt($a);
        });
    }

    /** A trusted runner must prove the old process stopped. A lease timeout alone is insufficient. */
    public function interruptAttempt(D\TenantId $tenant, D\DeletionTaskId $task, D\ResourceId $resource, D\AttemptId $attempt, \Closure $proveStopped): D\DeletionReceipt
    {
        $this->scoped($tenant);
        if ($this->db->pdo->inTransaction()) { throw new \LogicException('Runner proof outside SQL required'); }
        if ($proveStopped() !== true) { self::fail('deletion_runner_uncertain'); }
        $start = $this->guarded($tenant, $task, function ($t, $s, $frozen) use ($resource, $attempt) {
            $target = $this->target($t, $this->resource($resource), $frozen);
            $a = $this->db->row('SELECT * FROM '.$this->db->table('deletion_attempts').' WHERE tenant_id=? AND id=?', [$this->tenant->value, $attempt->value]);
            if ($a === null) { self::fail('deletion_attempt_missing'); }
            $this->exact($a, $target);
            return new D\DeletionStart($target, $this->receipt($a));
        });
        return $this->finishAttempt($start->target, $attempt, $start->replay, 'DELETION_INTERRUPTED');
    }

    public function aggregate(D\TenantId $tenant, D\DeletionTaskId $task): string
    {
        return $this->guarded($tenant, $task, function ($t, $s, $frozen) {
            $states = []; $blocked = false;
            foreach ($frozen as $ref) {
                $r = $this->resource(new D\ResourceId($ref->get('resourceId'))); $this->target($t, $r, $frozen);
                $a = $this->db->row('SELECT * FROM '.$this->db->table('deletion_attempts').
                    ' WHERE tenant_id=? AND task_id=? AND resource_id=? ORDER BY attempt_sequence DESC LIMIT 1', [$this->tenant->value, $t['id'], $r['id']]);
                $state = $a === null ? ($r['state'] === 'unknown' ? 'unknown' : 'pending') : ($a['result'] ?? 'unknown');
                if (in_array($state, ['deleted','absent'], true) && (!$a['exact_version_proof'] || $r['state'] !== 'deleted'
                    || $a['storage_key'] !== $r['storage_key'] || $a['object_version'] !== $r['object_version'])) { $state = 'unknown'; }
                if ($s['retention_hold'] || $r['retention_hold'] || ($r['retention_until'] !== null
                    && new \DateTimeImmutable($r['retention_until']) > new \DateTimeImmutable($this->clock->now()->value))) { $state = 'pending'; $blocked = true; }
                if ($this->db->row('SELECT id FROM '.$this->db->table('resource_writers')." WHERE tenant_id=? AND resource_id=? AND state<>'closed' LIMIT 1",
                    [$this->tenant->value, $r['id']]) !== null) { $state = 'unknown'; }
                $states[] = $state;
            }
            $late = $this->db->row('SELECT r.id FROM '.$this->db->table('resource_ledger').
                " r WHERE r.tenant_id=? AND r.source_id=? AND r.owner_kind='owned' AND NOT EXISTS (SELECT 1 FROM ".$this->db->table('deletion_task_resources').
                ' i WHERE i.tenant_id=r.tenant_id AND i.task_id=? AND i.resource_id=r.id) LIMIT 1', [$this->tenant->value, $t['source_id'], $t['id']]);
            $work = $this->db->row('SELECT id FROM '.$this->db->table('jobs').
                " WHERE tenant_id=? AND source_id=? AND (state IN ('pending','leased') OR uncertain_external_effect) LIMIT 1", [$this->tenant->value, $t['source_id']]);
            $flight = $this->db->row('SELECT a.id FROM '.$this->db->table('deletion_attempts').' a JOIN '.$this->db->table('deletion_tasks').
                ' t ON t.tenant_id=a.tenant_id AND t.id=a.task_id WHERE a.tenant_id=? AND t.source_id=? AND a.in_flight LIMIT 1', [$this->tenant->value, $t['source_id']]);
            if ($late !== null || $work !== null || $flight !== null) { $states[] = 'unknown'; }
            $status = $states === [] ? 'pending' : SemanticRules::deletionStatus($states);
            if ($status === 'done' && $blocked) { $status = 'pending'; }
            $this->db->execute('UPDATE '.$this->db->table('deletion_tasks').' SET status=?,hold_reason=? WHERE tenant_id=? AND id=?',
                [$status, $s['retention_hold'] ? 'legal_hold' : null, $this->tenant->value, $t['id']]);
            return $status;
        });
    }
}
