<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Catalog;

use KnowKit\Domain\{Decimal,DomainFailure,GenerationId,JobId,KbId,LockMode,Outcome,SnapshotId,SourceId,TenantId,Ulid};
use KnowKit\Port\CatalogTransaction;

/** AgenTools transaction/rollback pattern adapted; ordered semantic guards are KnowKit-specific. */
final class PdoCatalogTransaction implements CatalogTransaction
{
    private ?TenantId $tenant=null;
    private ?LockMode $tenantMode=null;
    private int $stage=0;
    private array $last=[];
    private array $held=[];
    public function __construct(public readonly Connection $db) {}
    public function run(callable $operation): mixed
    {
        if ($this->db->pdo->inTransaction()) { throw new \LogicException('Caller transaction already active'); }
        $this->tenant=null; $this->tenantMode=null; $this->stage=0; $this->last=[]; $this->held=[];
        $started=hrtime(true);
        $this->db->pdo->beginTransaction();
        try {
            $this->db->execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->db->execute("SET LOCAL lock_timeout='500ms'");
            $this->db->execute("SET LOCAL statement_timeout='2s'");
            $result=$operation();
            if (hrtime(true)-$started>=2_000_000_000) { throw new DomainFailure(Outcome::Unavailable,'transaction_deadline'); }
            $this->db->pdo->commit(); return $result;
        } catch (\Throwable $error) {
            $this->rollbackIfActive();
            if ($error instanceof \PDOException) { throw new DomainFailure(Outcome::Unavailable,'storage_unavailable'); }
            throw $error;
        } finally { $this->tenant=null; $this->tenantMode=null; $this->last=[]; $this->held=[]; }
    }
    private function rollbackIfActive(): void { if ($this->db->pdo->inTransaction()) { $this->db->pdo->rollBack(); } }
    public function requireTenant(TenantId $tenant, bool $write=false): void
    {
        if (!$this->db->pdo->inTransaction() || $this->tenant===null || !$this->tenant->equals($tenant)) {
            throw new DomainFailure(Outcome::Denied,'scope_mismatch');
        }
        if ($write && $this->tenantMode!==LockMode::Update) { throw new \LogicException('Tenant update guard required'); }
    }
    public function lockTenant(TenantId $tenantId, LockMode $mode): void
    {
        if (!$this->db->pdo->inTransaction() || $this->tenant!==null) { throw new \LogicException('Tenant guard must be first'); }
        $this->db->execute('SELECT '.($mode===LockMode::Share?'pg_advisory_xact_lock_shared':'pg_advisory_xact_lock').'(hashtextextended(?,0))',[$this->db->schema.':tenant:'.$tenantId->value]);
        $this->db->execute('SELECT id FROM '.$this->db->table('tenants').' WHERE id=? FOR '.($mode===LockMode::Update?'UPDATE':'SHARE'),[$tenantId->value]);
        $this->tenant=$tenantId; $this->tenantMode=$mode;
    }
    private function lock(string $table, Ulid $id, LockMode $mode, int $stage): void
    {
        if ($this->tenant===null) { throw new \LogicException('Tenant guard required'); }
        $this->requireTenant($this->tenant);
        if ($stage<$this->stage || (isset($this->last[$stage]) && strcmp($id->value,$this->last[$stage])<=0)) {
            throw new \LogicException('Sorted unique lock order required');
        }
        $this->stage=$stage; $this->last[$stage]=$id->value;
        $row=$this->db->row('SELECT id FROM '.$this->db->table($table).' WHERE tenant_id=? AND id=? FOR '.
            ($mode===LockMode::Update?'UPDATE':'SHARE'),[$this->tenant->value,$id->value]);
        if ($row===null) { throw new DomainFailure(Outcome::Incompatible,'resource_missing'); }
        $this->held[$stage][$id->value]=$mode;
    }
    public function lockKb(KbId $kbId,LockMode $mode): void { $this->lock('knowledge_bases',$kbId,$mode,1); }
    public function lockSources(array $sourceIds,LockMode $mode): void
    {
        if ($this->tenant===null) { throw new \LogicException('Tenant guard required'); }
        foreach ($sourceIds as $id) { $this->lock('sources',$this->sourceId($id),$mode,2); }
    }
    private function sourceId(mixed $id): SourceId
    { if (!$id instanceof SourceId) { throw new \InvalidArgumentException('Source IDs required'); } return $id; }
    public function lockGeneration(GenerationId $generationId,LockMode $mode): void { $this->lock('index_generations',$generationId,$mode,3); }
    public function lockJob(JobId $jobId,LockMode $mode): void { $this->lock('jobs',$jobId,$mode,4); }
    public function casPointer(KbId $kbId,SnapshotId $snapshotId,Decimal $expectedPointerVersion): bool
    {
        if ($this->tenant===null) { throw new \LogicException('Tenant guard required'); }
        $this->requireTenant($this->tenant,true);
        if (($this->held[1][$kbId->value]??null)!==LockMode::Update) { throw new \LogicException('KB update guard required'); }
        return $this->db->execute('UPDATE '.$this->db->table('knowledge_bases').' k SET active_snapshot_id=?,pointer_version=pointer_version+1
            WHERE tenant_id=? AND id=? AND pointer_version=? AND EXISTS (SELECT 1 FROM '.$this->db->table('publication_snapshots').
            ' s WHERE s.tenant_id=k.tenant_id AND s.kb_id=k.id AND s.id=? AND s.sealed)',
            [$snapshotId->value,$this->tenant->value,$kbId->value,$expectedPointerVersion->value,$snapshotId->value])->rowCount()===1;
    }
}
