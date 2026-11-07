<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Publication;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\Dependencies\PdoDependencyStore;
use KnowKit\Adapter\Postgres\IndexGenerations\PdoStagingGenerations;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject, StrictJson};
use KnowKit\Port\{Clock, IdGenerator, Outbox, SnapshotActivation};

/** Donor transaction/replay/staging patterns adapted to the existing catalog owner; P3.4-reuse.json. */
final readonly class PdoActivation implements SnapshotActivation
{
    /** @param \Closure(D\TenantId,string,D\Ulid,D\Decimal,string,JsonObject):D\DomainEvent $createEvent */
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx,
        private PdoStagingGenerations $staging, private Clock $clock, private IdGenerator $ids,
        private ContractValidator $validator, private \Closure $createEvent, private Outbox $outbox)
    { if ($db !== $tx->db) { throw new \InvalidArgumentException('Shared catalog transaction required'); } }

    public function replay(D\ActivationRequest $request): ?D\PublishReceipt
    {
        return $this->tx->run(function() use ($request): ?D\PublishReceipt {
            $this->tx->lockTenant($request->lease->scope->tenantId, D\LockMode::Update);
            return $this->receipt($request);
        });
    }

    private function receipt(D\ActivationRequest $r): ?D\PublishReceipt
    {
        $s = $r->lease->scope; $this->tx->requireTenant($s->tenantId, true);
        $row = $this->db->row('SELECT operation,request_fingerprint,response_blob FROM '.$this->db->table('idempotency_receipts').
            ' WHERE tenant_id=? AND key=?', [$s->tenantId->value,$r->key->value]);
        if ($row === null) { return null; }
        if ($row['operation'] !== D\Operation::Publish->value || $row['request_fingerprint'] !== $r->fingerprint()->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'idempotency_conflict');
        }
        return new D\PublishReceipt(StrictJson::decode($row['response_blob']), $this->validator);
    }

    public function activate(D\ActivationRequest $request): D\PublishReceipt
    {
        return $this->tx->run(function() use ($request): D\PublishReceipt {
            $s = $request->lease->scope; $w = $request->candidate->manifest->toWire();
            $this->tx->lockTenant($s->tenantId, D\LockMode::Update);
            $replay = $this->receipt($request); if ($replay !== null) { return $replay; }
            $affectedGenerations = $this->staging->checkPublication($request,$this->tx);
            $refs = [];
            foreach ($w->get('members') as $m) { $refs[$m->get('documentRevisionId')] = new D\ResourceRef($s->tenantId, 'document_revision', new D\DocumentRevisionId($m->get('documentRevisionId'))); }
            $report = (new PdoDependencyStore($this->db,$this->tx,$s->tenantId,$this->ids))->checkAncestors(new D\NodeRefs($s->tenantId,array_values($refs)));
            if ($report->decision !== D\Decision::Allow) {
                throw new D\DomainFailure($report->decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown,
                    $report->decision === D\Decision::Deny ? 'source_unusable' : 'ancestor_unknown');
            }
            $snapshot = new D\SnapshotId($this->ids->next()->value); $digest = $request->snapshotDigest();
            $version = $request->expectedPointerVersion->next();
            $event = ($this->createEvent)($s->tenantId,'knowledge_base',$s->kbId,$version,'snapshot.activated',
                new JsonObject(['kbId'=>$s->kbId->value,'snapshotId'=>$snapshot->value,'manifestDigest'=>$digest->value,'pointerVersion'=>$version->value]));
            $this->db->execute('INSERT INTO '.$this->db->table('publication_snapshots').
                ' (tenant_id,id,kb_id,manifest_digest,created_at) VALUES (?,?,?,?,?)',
                [$s->tenantId->value,$snapshot->value,$s->kbId->value,$digest->value,$this->clock->now()->value]);
            $this->db->execute('INSERT INTO '.$this->db->table('snapshot_generations').' (tenant_id,snapshot_id,kb_id,generation_id,profile_id) VALUES (?,?,?,?,?)',
                [$s->tenantId->value,$snapshot->value,$s->kbId->value,$w->get('generationId'),$w->get('profile')->get('id')]);
            foreach ($w->get('members') as $m) {
                $this->db->execute('INSERT INTO '.$this->db->table('snapshot_memberships').
                    ' (tenant_id,id,snapshot_id,kb_id,source_id,source_version_id,revision_id,chunk_set_id,generation_id,profile_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$s->tenantId->value,$this->ids->next()->value,$snapshot->value,$s->kbId->value,$m->get('sourceId'),$m->get('sourceVersionId'),
                        $m->get('documentRevisionId'),$m->get('chunkSetId'),$w->get('generationId'),$w->get('profile')->get('id')]);
            }
            $this->db->execute('UPDATE '.$this->db->table('publication_snapshots').' SET sealed=true WHERE tenant_id=? AND id=?', [$s->tenantId->value,$snapshot->value]);
            if (!$this->tx->casPointer($s->kbId,$snapshot,$request->expectedPointerVersion)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'POINTER_CONFLICT');
            }
            // Retired means absent from the pointer, while authorized pinned history stays available.
            $this->db->execute('UPDATE '.$this->db->table('index_generations')." SET state=CASE WHEN id=? THEN 'active' ELSE 'retired' END
                WHERE tenant_id=? AND kb_id=? AND id IN (".implode(',',array_fill(0,count($affectedGenerations),'?')).')',
                [$w->get('generationId'),$s->tenantId->value,$s->kbId->value,...$affectedGenerations]);
            $this->outbox->append($event);
            $receipt = new D\PublishReceipt(new JsonObject(['snapshotId'=>$snapshot->value,'manifestDigest'=>$digest->value,
                'pointerVersion'=>$version->value,'eventId'=>$event->toWire()->get('eventId')]), $this->validator);
            $this->db->execute('INSERT INTO '.$this->db->table('idempotency_receipts').
                ' (tenant_id,id,key,operation,request_fingerprint,response_blob,accepted_at) VALUES (?,?,?,?,?,?,?)',
                [$s->tenantId->value,$this->ids->next()->value,$request->key->value,D\Operation::Publish->value,$request->fingerprint()->value,$receipt->canonical(),$this->clock->now()->value]);
            return $receipt;
        });
    }
}
