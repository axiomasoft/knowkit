<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Resources;

use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Domain\{BlobWriteReservation,DomainFailure,JobId,Outcome,UtcInstant};

/** Transactional primitive. The owning ledger supplies tenant/source guards. */
final readonly class WriterReservations
{
    public function __construct(private Connection $db) {}

    public function requireExact(BlobWriteReservation $r): array
    {
        $w=$this->db->row('SELECT * FROM '.$this->db->table('resource_writers').' WHERE tenant_id=? AND id=? FOR UPDATE',
            [$r->resource->tenantId->value,$r->writerId->value]);
        if ($w===null || $w['resource_id']!==$r->resource->id->value || (string)$w['fence']!==$r->fence->value) {
            throw new DomainFailure(Outcome::Denied,'writer_binding_mismatch');
        }
        return $w;
    }

    public function reserve(BlobWriteReservation $r,?JobId $job,UtcInstant $now): void
    {
        $w=$this->db->row('SELECT * FROM '.$this->db->table('resource_writers').' WHERE tenant_id=? AND id=?',
            [$r->resource->tenantId->value,$r->writerId->value]);
        if ($w!==null) {
            $w=$this->requireExact($r);
            if ($w['job_id']!==$job?->value || $w['state']==='uncertain') {
                throw new DomainFailure(Outcome::Unknown,'writer_uncertain');
            }
            return;
        }
        $this->db->execute('INSERT INTO '.$this->db->table('resource_writers').
            " (tenant_id,id,resource_id,fence,state,job_id,started_at) VALUES (?,?,?,?,'active',?,?)",
            [$r->resource->tenantId->value,$r->writerId->value,$r->resource->id->value,$r->fence->value,$job?->value,$now->value]);
    }

    public function close(BlobWriteReservation $r,UtcInstant $now): void
    {
        $this->requireExact($r);
        $this->db->execute('UPDATE '.$this->db->table('resource_writers')." SET state='closed',closed_at=? WHERE tenant_id=? AND id=? AND state<>'closed'",
            [$now->value,$r->resource->tenantId->value,$r->writerId->value]);
    }

    public function markUncertain(BlobWriteReservation $r): bool
    {
        $w=$this->requireExact($r);
        if ($w['state']==='closed') { return false; }
        $this->db->execute('UPDATE '.$this->db->table('resource_writers')." SET state='uncertain' WHERE tenant_id=? AND id=?",
            [$r->resource->tenantId->value,$r->writerId->value]);
        return true;
    }
}
