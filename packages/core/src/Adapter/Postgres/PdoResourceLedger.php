<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres;

use KnowKit\Adapter\Postgres\Catalog\{Connection,PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Resources\WriterReservations;
use KnowKit\Domain as D;
use KnowKit\Port\Clock;

/** Durable, tenant-bound pre-PUT reservations. No filesystem/provider I/O in transactions.
 * AgenTools explicit rollback/tenant/idempotency patterns privately adapted; see P2.2 reuse receipt.
 */
final readonly class PdoResourceLedger
{
    private WriterReservations $writers;
    public function __construct(private Connection $db,private PdoCatalogTransaction $tx,
        private D\TenantId $tenant,private string $storageId,private Clock $clock)
    {
        if ($storageId==='' || strlen($storageId)>128) { throw new \InvalidArgumentException('Storage identity required'); }
        $this->writers=new WriterReservations($db);
    }

    private function scoped(D\TenantId $tenant): void
    { if (!$this->tenant->equals($tenant)) { throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch'); } }

    private function guarded(D\SourceId $source,callable $call): mixed
    {
        return $this->tx->run(function() use($source,$call) {
            $this->tx->lockTenant($this->tenant,D\LockMode::Update);
            $this->tx->lockSources([$source],D\LockMode::Update);
            return $call();
        });
    }

    private function source(D\BlobWriteReservation $r,?array $writer=null): void
    {
        $s=$this->db->row('SELECT lifecycle_state,lifecycle_epoch FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?',
            [$this->tenant->value,$r->sourceId->value]);
        if ($s===null || $s['lifecycle_state']!=='active' || (string)$s['lifecycle_epoch']!==$r->sourceEpoch->value ||
            $this->db->row('SELECT id FROM '.$this->db->table('tombstones').' WHERE tenant_id=? AND source_id=?',[$this->tenant->value,$r->sourceId->value])!==null) {
            throw new D\DomainFailure(D\Outcome::Denied,'source_changed');
        }
        if (($writer['job_id']??null)!==null) {
            $this->tx->lockJob(new D\JobId($writer['job_id']),D\LockMode::Update);
            $j=$this->db->row('SELECT * FROM '.$this->db->table('jobs').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$writer['job_id']]);
            if ($j===null || $j['source_id']!==$r->sourceId->value || (string)$j['fence']!==$r->fence->value ||
                (string)$j['source_epoch']!==$r->sourceEpoch->value || $j['state']!=='leased' || $j['cancel_requested'] ||
                new \DateTimeImmutable($j['lease_until'])<=new \DateTimeImmutable($this->clock->now()->value) ||
                new \DateTimeImmutable($j['attempt_deadline'])<=new \DateTimeImmutable($this->clock->now()->value)) {
                throw new D\DomainFailure(D\Outcome::Incompatible,'writer_fenced');
            }
        }
    }

    private function resource(D\BlobWriteReservation $r): array
    {
        $this->scoped($r->resource->tenantId);
        $row=$this->db->row('SELECT * FROM '.$this->db->table('resource_ledger').' WHERE tenant_id=? AND id=? FOR UPDATE',
            [$this->tenant->value,$r->resource->id->value]);
        if ($row===null || $row['source_id']!==$r->sourceId->value || $row['storage_id']!==$this->storageId || $row['owner_kind']!=='owned' ||
            $row['staging_id']!==$r->stagingId?->value || ($r->digest!==null && ($row['digest']!==$r->digest->value || (string)$row['byte_length']!==$r->byteLength->value))) {
            throw new D\DomainFailure(D\Outcome::Incompatible,'reservation_mismatch');
        }
        if (in_array($row['state'],['deleting','deleted'],true)) { throw new D\DomainFailure(D\Outcome::Incompatible,'resource_deleting'); }
        return $row;
    }

    public function reserveWriter(D\BlobWriteReservation $r,?D\JobId $job=null): D\BlobWriteReservation
    {
        $this->scoped($r->resource->tenantId);
        return $this->guarded($r->sourceId,function() use($r,$job) {
            $this->source($r,$job===null?null:['job_id'=>$job->value]);
            $this->db->execute('INSERT INTO '.$this->db->table('resource_ledger').
                " (tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,staging_id,digest,byte_length,state) VALUES (?,?,?,'owned',?,?,?,?,?,?,'reserved') ON CONFLICT (tenant_id,id) DO NOTHING",
                [$this->tenant->value,$r->resource->id->value,$r->sourceId->value,$this->storageId,$this->stagingKey($r),$r->resource->id->value,$r->stagingId?->value,$r->digest?->value,$r->byteLength?->value]);
            $row=$this->resource($r);
            if ($row['state']==='unknown') { throw new D\DomainFailure(D\Outcome::Unknown,'writer_uncertain'); }
            $this->writers->reserve($r,$job,$this->clock->now());
            return $r;
        });
    }

    public function stagingKey(D\BlobWriteReservation $r): string
    { $this->scoped($r->resource->tenantId); return $this->tenant->value.'/'.$r->sourceId->value.'/staging/'.($r->stagingId->value??$r->resource->id->value); }

    public function canonicalKey(D\BlobWriteReservation $r,D\Sha256 $digest): string
    { $this->scoped($r->resource->tenantId); return $this->tenant->value.'/'.$r->sourceId->value.'/sha256/'.$digest->value.'/'.$r->resource->id->value; }

    public function inspectWriter(D\BlobWriteReservation $r): array
    {
        $this->scoped($r->resource->tenantId);
        return $this->guarded($r->sourceId,function() use($r) {
            $row=$this->resource($r); $w=$this->writers->requireExact($r); $this->source($r,$w);
            if ($w['state']==='uncertain' || $row['state']==='unknown') { throw new D\DomainFailure(D\Outcome::Unknown,'writer_uncertain'); }
            return ['resource'=>$row,'writer'=>$w];
        });
    }

    /** Digest/length become immutable once, before the promotion I/O. */
    public function finalizeIdentity(D\BlobWriteReservation $r,D\Sha256 $digest,D\Uint64 $length): void
    {
        $this->scoped($r->resource->tenantId);
        $this->guarded($r->sourceId,function() use($r,$digest,$length) {
            $row=$this->resource($r); $w=$this->writers->requireExact($r); $this->source($r,$w);
            if ($w['state']!=='active' || $row['state']==='unknown') { throw new D\DomainFailure(D\Outcome::Unknown,'writer_uncertain'); }
            if ($row['digest']!==null && ($row['digest']!==$digest->value || (string)$row['byte_length']!==$length->value)) {
                throw new D\DomainFailure(D\Outcome::Incompatible,'blob_digest_mismatch');
            }
            $this->db->execute('UPDATE '.$this->db->table('resource_ledger').' SET digest=?,byte_length=?,storage_key=? WHERE tenant_id=? AND id=?',
                [$digest->value,$length->value,$this->canonicalKey($r,$digest),$this->tenant->value,$r->resource->id->value]);
        });
    }

    /** Called only after durable PUT, verified read, and all local handles closed. */
    public function closeWriter(D\BlobWriteReservation $r): ?D\BlobRef
    {
        $this->scoped($r->resource->tenantId);
        return $this->guarded($r->sourceId,function() use($r) {
            $row=$this->resource($r); $w=$this->writers->requireExact($r); $this->source($r,$w);
            if ($w['state']==='uncertain' || $row['state']==='unknown' || $row['digest']===null) {
                throw new D\DomainFailure(D\Outcome::Unknown,'writer_uncertain');
            }
            $this->writers->close($r,$this->clock->now());
            if ($row['state']!=='accepted') {
                $this->db->execute('UPDATE '.$this->db->table('resource_ledger')." SET state='uploaded' WHERE tenant_id=? AND id=?",[$this->tenant->value,$r->resource->id->value]);
                if ($this->db->row('SELECT id FROM '.$this->db->table('resource_writers')." WHERE tenant_id=? AND resource_id=? AND state<>'closed' LIMIT 1",[$this->tenant->value,$r->resource->id->value])!==null) { return null; }
                $this->db->execute('UPDATE '.$this->db->table('resource_ledger')." SET state='accepted' WHERE tenant_id=? AND id=?",[$this->tenant->value,$r->resource->id->value]);
            }
            // The accepted identity retains its acceptance reference across object-before-catalog failure.
            // Lifecycle/deletion owns releasing that reference under the source guard, never age/lease expiry.
            $this->db->execute('UPDATE '.$this->db->table('resource_ledger').' SET ref_count=GREATEST(ref_count,1) WHERE tenant_id=? AND id=?',[$this->tenant->value,$r->resource->id->value]);
            return $this->ref($row);
        });
    }

    public function markUncertain(D\BlobWriteReservation $r): void
    {
        $this->scoped($r->resource->tenantId);
        $this->guarded($r->sourceId,function() use($r) {
            $this->resource($r);
            if ($this->writers->markUncertain($r)) {
                $this->db->execute('UPDATE '.$this->db->table('resource_ledger')." SET state=CASE WHEN state='accepted' THEN state ELSE 'unknown' END WHERE tenant_id=? AND id=?",[$this->tenant->value,$r->resource->id->value]);
            }
        });
    }

    /** Trusted runner proof is evaluated outside SQL; lease expiry is never proof. */
    public function reconcileWriter(D\BlobWriteReservation $r,\Closure $proveQuiescence,\Closure $verifyObject): ?D\BlobRef
    {
        $this->scoped($r->resource->tenantId);
        if ($this->db->pdo->inTransaction()) { throw new \LogicException('Reconciliation I/O outside SQL required'); }
        if ($proveQuiescence($r)!==true || $verifyObject($r)!==true) { throw new D\DomainFailure(D\Outcome::Unknown,'writer_uncertain'); }
        return $this->guarded($r->sourceId,function() use($r) {
            $row=$this->resource($r); $w=$this->writers->requireExact($r); $this->source($r,$w);
            if ($row['digest']===null) { throw new D\DomainFailure(D\Outcome::Unknown,'unfinalized_resource'); }
            $this->writers->close($r,$this->clock->now());
            if ($this->db->row('SELECT id FROM '.$this->db->table('resource_writers')." WHERE tenant_id=? AND resource_id=? AND state<>'closed' LIMIT 1",[$this->tenant->value,$r->resource->id->value])!==null) { return null; }
            $this->db->execute('UPDATE '.$this->db->table('resource_ledger')." SET state='uploaded' WHERE tenant_id=? AND id=? AND state<>'accepted'",[$this->tenant->value,$r->resource->id->value]);
            $this->db->execute('UPDATE '.$this->db->table('resource_ledger')." SET state='accepted' WHERE tenant_id=? AND id=?",[$this->tenant->value,$r->resource->id->value]);
            $this->db->execute('UPDATE '.$this->db->table('resource_ledger').' SET ref_count=GREATEST(ref_count,1) WHERE tenant_id=? AND id=?',[$this->tenant->value,$r->resource->id->value]);
            return $this->ref($row);
        });
    }

    /** Recovery metadata grants no read/disclosure authority. */
    public function recoveryRef(D\BlobWriteReservation $r): D\BlobRef
    {
        $this->scoped($r->resource->tenantId);
        return $this->guarded($r->sourceId,function() use($r) {
            $row=$this->resource($r); $this->writers->requireExact($r);
            if ($row['digest']===null || $row['storage_key']!==$this->canonicalKey($r,new D\Sha256($row['digest']))) {
                throw new D\DomainFailure(D\Outcome::Unknown,'unfinalized_resource');
            }
            return $this->ref($row);
        });
    }

    private function ref(array $row): D\BlobRef
    { return new D\BlobRef($this->tenant,new D\SourceId($row['source_id']),new D\Sha256($row['digest']),new D\Uint64((string)$row['byte_length']),$row['storage_id'],$row['object_version'],$row['owner_kind']); }

    public function readBinding(D\Scope $scope,D\BlobRef $ref): D\Contract\JsonObject
    {
        $this->scoped($scope->tenantId); $this->scoped($ref->tenantId);
        return $this->tx->run(function() use($scope,$ref) {
            $this->tx->lockTenant($this->tenant,D\LockMode::Update);
            $this->tx->lockKb($scope->kbId,D\LockMode::Share);
            $this->tx->lockSources([$ref->sourceId],D\LockMode::Share);
            $r=$this->db->row('SELECT t.tenant_policy_epoch,k.kb_policy_epoch,s.source_policy_epoch,s.lifecycle_epoch FROM '.
                $this->db->table('tenants').' t JOIN '.$this->db->table('knowledge_bases').' k ON k.tenant_id=t.id JOIN '.
                $this->db->table('source_memberships').' m ON m.tenant_id=k.tenant_id AND m.kb_id=k.id JOIN '.
                $this->db->table('sources')." s ON s.tenant_id=m.tenant_id AND s.id=m.source_id WHERE t.id=? AND k.id=? AND s.id=? AND k.removed_at IS NULL AND t.serving_state='ready' AND s.lifecycle_state='active' LIMIT 1",
                [$this->tenant->value,$scope->kbId->value,$ref->sourceId->value]);
            if ($r===null) { throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch'); }
            return new D\Contract\JsonObject(['tenantPolicyEpoch'=>(string)$r['tenant_policy_epoch'],'kbPolicyEpoch'=>(string)$r['kb_policy_epoch'],
                'sources'=>[new D\Contract\JsonObject(['sourceId'=>$ref->sourceId->value,'sourcePolicyEpoch'=>(string)$r['source_policy_epoch'],'lifecycleEpoch'=>(string)$r['lifecycle_epoch']])]]);
        });
    }

    /** Authoritative lifecycle/restore/accepted check; owner policy is checked by the scoped caller. */
    public function requireAccepted(D\BlobRef $ref): D\ResourceRef
    {
        $this->scoped($ref->tenantId);
        if ($ref->storageId!==$this->storageId) { throw new D\DomainFailure(D\Outcome::Incompatible,'blob_store_mismatch'); }
        return $this->guarded($ref->sourceId,function() use($ref) {
            $rows=$this->db->execute('SELECT r.* FROM '.$this->db->table('resource_ledger').' r JOIN '.$this->db->table('sources').
                ' s ON s.tenant_id=r.tenant_id AND s.id=r.source_id JOIN '.$this->db->table('tenants').
                " t ON t.id=r.tenant_id WHERE r.tenant_id=? AND r.source_id=? AND r.storage_id=? AND r.object_version=? AND r.owner_kind=? AND r.digest=? AND r.byte_length=? AND r.state='accepted' AND t.serving_state='ready' AND s.lifecycle_state='active' AND NOT EXISTS (SELECT 1 FROM ".
                $this->db->table('tombstones').' x WHERE x.tenant_id=r.tenant_id AND x.source_id=r.source_id) LIMIT 2',
                [$this->tenant->value,$ref->sourceId->value,$ref->storageId,$ref->objectVersion,$ref->ownerKind,$ref->digest->value,$ref->byteLength->value])->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows)!==1) { throw new D\DomainFailure(D\Outcome::Denied,'blob_not_accepted'); }
            $row=$rows[0];
            return new D\ResourceRef($this->tenant,'resource',new D\ResourceId($row['id']));
        });
    }

    public function listUnreferenced(D\UtcInstant $before,D\Cursor $cursor): D\ResourcePage
    {
        $this->scoped($cursor->tenantId);
        if ($cursor->token!==null) { new D\ResourceId($cursor->token); }
        $rows=$this->db->execute('SELECT r.id FROM '.$this->db->table('resource_ledger')." r WHERE r.tenant_id=? AND r.storage_id=? AND r.ref_count=0 AND r.state<>'deleted' AND (CAST(? AS text) IS NULL OR r.id>?) AND NOT EXISTS (SELECT 1 FROM ".
            $this->db->table('resource_writers').' w WHERE w.tenant_id=r.tenant_id AND w.resource_id=r.id AND w.started_at>=?) ORDER BY r.id LIMIT 101',
            [$this->tenant->value,$this->storageId,$cursor->token,$cursor->token,$before->value])->fetchAll(\PDO::FETCH_ASSOC);
        $more=count($rows)>100; $rows=array_slice($rows,0,100);
        $refs=array_map(fn($r)=>new D\ResourceRef($this->tenant,'resource',new D\ResourceId($r['id'])),$rows);
        return new D\ResourcePage($this->tenant,$refs,$more?new D\Cursor($this->tenant,end($rows)['id']):null);
    }

    /** Frozen task/attempt binding only; P3.6 owns persistence/aggregation of the resulting receipt. */
    public function deletionBinding(D\ResourceRef $resource,D\AttemptId $attempt): array
    {
        $this->scoped($resource->tenantId);
        if ($resource->kind!=='resource') { throw new D\DomainFailure(D\Outcome::Denied,'scope_mismatch'); }
        $r=$this->db->row('SELECT source_id FROM '.$this->db->table('resource_ledger').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$resource->id->value]);
        if ($r===null) { throw new D\DomainFailure(D\Outcome::Denied,'deletion_not_reserved'); }
        return $this->guarded(new D\SourceId($r['source_id']),function() use($resource,$attempt) {
            $r=$this->db->row('SELECT r.*,a.task_id FROM '.$this->db->table('resource_ledger').' r JOIN '.$this->db->table('deletion_attempts').
                ' a ON a.tenant_id=r.tenant_id AND a.resource_id=r.id JOIN '.$this->db->table('deletion_tasks').
                ' t ON t.tenant_id=r.tenant_id AND t.id=a.task_id AND t.source_id=r.source_id WHERE r.tenant_id=? AND r.id=? AND r.storage_id=? AND a.id=? AND a.in_flight AND a.result IS NULL'.
                ' AND a.storage_key IS NOT DISTINCT FROM r.storage_key AND a.object_version IS NOT DISTINCT FROM r.object_version AND a.staging_id IS NOT DISTINCT FROM r.staging_id'.
                ' AND EXISTS (SELECT 1 FROM '.$this->db->table('deletion_task_resources').' i WHERE i.tenant_id=r.tenant_id AND i.task_id=t.id AND i.resource_id=r.id)',
                [$this->tenant->value,$resource->id->value,$this->storageId,$attempt->value]);
            if ($r===null) { throw new D\DomainFailure(D\Outcome::Denied,'deletion_not_reserved'); }
            if ($r['owner_kind']==='owned' && ($r['state']!=='deleting' || $r['retention_hold'] || (string)$r['ref_count']!=='0' ||
                $this->db->row('SELECT id FROM '.$this->db->table('resource_writers')." WHERE tenant_id=? AND resource_id=? AND state<>'closed' LIMIT 1",[$this->tenant->value,$resource->id->value])!==null ||
                $this->db->row('SELECT id FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=? AND retention_hold',[$this->tenant->value,$r['source_id']])!==null)) {
                throw new D\DomainFailure(D\Outcome::Denied,'resource_in_use');
            }
            return ['taskId'=>new D\DeletionTaskId($r['task_id']),'ownerKind'=>$r['owner_kind'],'storageKey'=>$r['storage_key'],'allowed'=>$r['owner_kind']==='owned'];
        });
    }

    /** Listing cannot confer ownership or delete authority. Unknown inventory is held indefinitely. */
    public function quarantine(D\SourceId $source,string $key,D\ResourceId $id,?D\Ulid $staging): void
    {
        if (!str_starts_with($key,$this->tenant->value.'/'.$source->value.'/') || strlen($key)>1024) { throw new \InvalidArgumentException('Scoped inventory required'); }
        $this->guarded($source,function() use($source,$key,$id,$staging) {
            if ($this->db->row('SELECT id FROM '.$this->db->table('resource_ledger').' WHERE tenant_id=? AND storage_id=? AND (storage_key=? OR (source_id=? AND (staging_id=? OR (staging_id IS NULL AND id=?))))',
                [$this->tenant->value,$this->storageId,$key,$source->value,$staging?->value,$staging?->value])!==null) { return; }
            $this->db->execute('INSERT INTO '.$this->db->table('resource_ledger')." (tenant_id,id,source_id,owner_kind,storage_id,storage_key,staging_id,state,retention_hold) VALUES (?,?,?,'owned',?,?,?,'unknown',true)",
                [$this->tenant->value,$id->value,$source->value,$this->storageId,$key,$staging?->value]);
        });
    }
}
