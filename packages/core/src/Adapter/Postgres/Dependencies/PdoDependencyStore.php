<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Dependencies;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Adapter\Postgres\PdoOutbox;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject, StrictJson};
use KnowKit\Port\{DependencyStore, IdGenerator};

/** Adapted from AgenTools PostgresGraph at 2054acda51eb69eca1579c78900858c5ff3de01e.
 * Private local reuse: licenses/local-reuse-authorization.md. Caller owns ordered SQL transaction.
 */
final readonly class PdoDependencyStore implements DependencyStore
{
    private NodeMetadata $metadata;
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx, private D\TenantId $tenant, private IdGenerator $ids)
    {
        if ($db!==$tx->db) { throw new \InvalidArgumentException('Shared transaction required'); }
        $this->metadata=new NodeMetadata($db,$tx);
    }

    public function register(D\Node $node, D\Parents $parents): void
    {
        $this->guard($node->ref->tenantId,true); $node->assertParents($parents);
        $seen=[];
        foreach ($parents->refs as $parent) {
            if ($parent->id->equals($node->ref->id)) { self::fail('self_reference'); }
            if (isset($seen[$parent->id->value])) { self::fail('duplicate_identifier'); } $seen[$parent->id->value]=true;
            $this->requireNode($parent);
        }
        $meta=$this->metadata->resolve($node->ref);
        if (HashDomain::DependencyNodeIdentity->digest($meta['identity'])!==$node->identityDigest->value) { self::fail('immutable_conflict'); }
        $existing=$this->row($node->ref->id->value);
        if ($existing!==null && ($existing['kind']!==$node->ref->kind || $existing['identity_digest']!==$node->identityDigest->value
            || $existing['parent_set_digest']!==$parents->digest()->value || $existing['source_id']!==$meta['source']
            || $existing['source_version_id']!==$meta['version'])) { self::fail('immutable_conflict'); }
        if (!in_array($node->ref->kind,['source','source_version'],true) && $parents->refs===[]) { self::fail('required_parent_missing'); }
        $this->requireUsable($this->checkAncestors($parents));
        $this->requireUsable($this->state($node->ref,$meta['source'],$meta['version'],null));
        // Verify an existing set BEFORE any insert: conflicts cannot accumulate edges.
        if ($existing!==null) {
            if (!$this->parents($node->ref->id->value)->digest()->equals($parents->digest())) { self::fail('immutable_conflict'); }
            $this->requireUsable($this->checkAncestors(new D\NodeRefs($this->tenant,[$node->ref]))); return;
        }
        $this->db->execute('INSERT INTO '.$this->db->table('dependency_nodes').
            '(tenant_id,id,kind,source_id,source_version_id,identity_digest,parent_set_digest) VALUES (?,?,?,?,?,?,?)',
            [$this->tenant->value,$node->ref->id->value,$node->ref->kind,$meta['source'],$meta['version'],$node->identityDigest->value,$parents->digest()->value]);
        foreach ($parents->refs as $parent) {
            $this->db->execute('INSERT INTO '.$this->db->table('dependency_edges')." VALUES (?,?,?,'required')",[$this->tenant->value,$node->ref->id->value,$parent->id->value]);
        }
    }

    public function checkAncestors(D\NodeRefs $refs): D\UsabilityReport
    {
        $this->guard($refs->tenantId); $blocked=[]; $decision=D\Decision::Allow;
        foreach ($refs->refs as $ref) {
            $root=$this->row($ref->id->value);
            if ($root===null) {
                $blocked[$ref->id->value]=$ref; $missingDecision=D\Decision::Unknown;
                if ($ref->kind==='source') { $missingDecision=$this->state($ref,$ref->id->value,null,null)->decision===D\Decision::Deny?D\Decision::Deny:D\Decision::Unknown; }
                if ($missingDecision===D\Decision::Deny || $decision!==D\Decision::Deny) { $decision=$missingDecision; } continue;
            }
            if ($root['kind']!==$ref->kind) { self::fail('immutable_conflict'); }
            $rows=$this->db->execute('WITH RECURSIVE ancestors(id) AS (SELECT ?::text COLLATE "C" UNION SELECT e.parent_id::text FROM '.
                $this->db->table('dependency_edges').' e JOIN ancestors a ON e.child_id=a.id WHERE e.tenant_id=?) SELECT n.* FROM '.
                $this->db->table('dependency_nodes').' n JOIN ancestors a ON a.id=n.id WHERE n.tenant_id=? ORDER BY n.id',
                [$ref->id->value,$this->tenant->value,$this->tenant->value])->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $ancestor=$this->ref($row['kind'],$row['id']);
                if ($this->parents($row['id'])->digest()->value!==$row['parent_set_digest']) { self::fail('immutable_conflict'); }
                $report=$this->state($ancestor,$row['source_id'],$row['source_version_id'],$row['invalidated_epoch']===null?null:(string)$row['invalidated_epoch']);
                if ($report->decision===D\Decision::Allow) {
                    $meta=$this->metadata->resolve($ancestor);
                    if ($meta['source']!==$row['source_id'] || $meta['version']!==$row['source_version_id']
                        || HashDomain::DependencyNodeIdentity->digest($meta['identity'])!==$row['identity_digest']) { self::fail('immutable_conflict'); }
                }
                if ($report->decision!==D\Decision::Allow) {
                    $blocked[$row['id']]=$ancestor;
                    if ($report->decision===D\Decision::Deny || $decision!==D\Decision::Deny) { $decision=$report->decision; }
                }
            }
        }
        ksort($blocked,SORT_STRING);
        return new D\UsabilityReport($decision,new D\NodeRefs($this->tenant,array_values($blocked)));
    }

    /** The disclosure writer inserts evidence_usages first; this primitive stores only its immutable lineage. */
    public function recordUsage(D\Usage $usage): void
    {
        // Usage insertion holds tenant/KB/source shared guards; it never mutates the dependency DAG.
        $w=$usage->toWire(); $this->guard(new D\TenantId($w->get('tenantId')));
        $u=$this->db->row('SELECT * FROM '.$this->db->table('evidence_usages').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$w->get('usageId')]);
        if ($u===null) { self::fail('usage_not_prepared'); }
        foreach (['disclosureAttemptId'=>'disclosure_attempt_id','selectionId'=>'selection_id','actorId'=>'actor_id','purpose'=>'purpose',
            'hostScopeHash'=>'host_scope_hash','runRef'=>'run_ref','policyBindingDigest'=>'policy_binding_digest'] as $key=>$column) {
            if ($u[$column]!==$w->get($key)) { self::fail('immutable_conflict'); }
        }
        $preparedAt = (new \DateTimeImmutable((string) $u['prepared_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $checkedAt = (new \DateTimeImmutable((string) $w->get('checkedAt')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        if ($preparedAt !== $checkedAt) { self::fail('immutable_conflict'); }
        $expected=[]; $refs=[];
        foreach ($w->get('fragmentRefs') as $fragment) {
            $chunk=$fragment->get('chunkRef');
            if ($chunk->get('tenantId')!==$this->tenant->value) { self::fail('scope_mismatch',D\Outcome::Denied); }
            $ref=$this->ref('chunk',$chunk->get('chunkId')); $this->requireNode($ref);
            if (Jcs::canonicalize($this->metadata->resolve($ref)['identity'])!==Jcs::canonicalize($chunk)) { self::fail('immutable_conflict'); }
            $expected[$ref->id->value][]=$fragment; $refs[$ref->id->value]=$ref;
        }
        foreach ($expected as $id=>$fragments) {
            usort($fragments,static fn($a,$b)=>strcmp(Jcs::canonicalize($a),Jcs::canonicalize($b)));
            $expected[$id]=Jcs::canonicalize(new JsonObject(['fragmentRefs'=>$fragments]));
        }
        ksort($expected,SORT_STRING); $actual=[];
        $this->tx->requireUsageGuards($this->tenant, new D\KbId($u['kb_id']), $this->usageSources(array_keys($expected)));
        foreach ($this->db->execute('SELECT node_id,exact_version_ref FROM '.$this->db->table('usage_refs').' WHERE tenant_id=? AND usage_id=? ORDER BY node_id',
            [$this->tenant->value,$w->get('usageId')])->fetchAll(\PDO::FETCH_ASSOC) as $r) { $actual[$r['node_id']]=Jcs::canonicalize(StrictJson::decode($r['exact_version_ref'])); }
        if ($actual!==[]) {
            if ($actual!==$expected) { self::fail('immutable_conflict'); }
            return; // Historical replay preserves evidence; it grants no new disclosure permission.
        }
        $this->requireUsable($this->checkAncestors(new D\NodeRefs($this->tenant,array_values($refs))));
        foreach ($expected as $id=>$wire) {
            $this->db->execute('INSERT INTO '.$this->db->table('usage_refs').' VALUES (?,?,?,?,?::jsonb)',
                [$this->tenant->value,$this->ids->next()->value,$w->get('usageId'),$id,$wire]);
        }
    }

    /** @param list<string> $chunks @return list<string> */
    private function usageSources(array $chunks): array
    {
        $rows = $this->db->execute('WITH RECURSIVE ancestors(id) AS (SELECT value::text COLLATE "C" FROM jsonb_array_elements_text(?::jsonb)'
            . ' UNION SELECT e.parent_id::text FROM ' . $this->db->table('dependency_edges')
            . ' e JOIN ancestors a ON a.id=e.child_id WHERE e.tenant_id=?) SELECT DISTINCT n.source_id FROM '
            . $this->db->table('dependency_nodes') . ' n JOIN ancestors a ON a.id=n.id WHERE n.tenant_id=? ORDER BY n.source_id',
            [json_encode($chunks, JSON_THROW_ON_ERROR), $this->tenant->value, $this->tenant->value])->fetchAll(\PDO::FETCH_COLUMN);

        return $rows;
    }

    public function invalidate(D\LifecycleEvent $event): void
    {
        $w=$event->event->toWire(); $this->guard(new D\TenantId($w->get('tenantId')),true);
        $old=$this->db->row('SELECT event_json FROM '.$this->db->table('outbox').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$w->get('eventId')]);
        if ($old!==null && Jcs::canonicalize(StrictJson::decode($old['event_json']))!==$event->event->canonical()) { self::fail('idempotency_conflict'); }
        $p=$w->get('payload'); $type=$w->get('type');
        if ($type==='retention_hold.changed') { return; } // A retention flag changes no read permission.
        $version=$type==='source_version.corrected'?$p->get('sourceVersionId'):null;
        if ($version!==null) {
            $r=$this->db->row('SELECT source_id FROM '.$this->db->table('source_versions').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$version]);
            if ($r===null) { self::fail('required_parent_missing'); } $source=$r['source_id'];
        } else { $source=$p->get('sourceId'); }
        if (!(($w->get('aggregateType')==='source' && $w->get('aggregateId')===$source)
            || ($version!==null && $w->get('aggregateType')==='source_version' && $w->get('aggregateId')===$version))) { self::fail('immutable_conflict'); }
        $sourceRow=$this->db->row('SELECT id FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$source]);
        $epoch=$version===null?$p->get('lifecycleEpoch'):$w->get('aggregateVersion');
        (new PdoOutbox($this->db,$this->tx))->append($event->event);
        if ($version===null) {
            $this->db->execute('INSERT INTO '.$this->db->table('tombstones').
                "(tenant_id,id,source_id,reason,event_id,lifecycle_epoch,applies_to) VALUES (?,?,?,?,?,?,'future_versions') ON CONFLICT (tenant_id,source_id) DO NOTHING",
                [$this->tenant->value,$this->ids->next()->value,$source,$type==='source.revoked'?$p->get('reason'):'source_deleted',$w->get('eventId'),$epoch]);
        }
        // lifecycle_events has a source FK. An unseen-source event is durably retained in outbox+tombstone instead.
        if ($sourceRow===null) { return; }
        $this->db->execute('INSERT INTO '.$this->db->table('lifecycle_events').' VALUES (?,?,?,?,?,?,?) ON CONFLICT (tenant_id,id) DO NOTHING',
            [$this->tenant->value,$w->get('eventId'),$source,$version,$type,$epoch,$w->get('payloadDigest')]);
        $stored=$this->db->row('SELECT * FROM '.$this->db->table('lifecycle_events').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$w->get('eventId')]);
        if ($stored===null || $stored['source_id']!==$source || $stored['source_version_id']!==$version || $stored['type']!==$type
            || (string)$stored['lifecycle_epoch']!==$epoch || $stored['payload_digest']!==$w->get('payloadDigest')) { self::fail('idempotency_conflict'); }
        $args=[$this->tenant->value,$source,$version,$version,$this->tenant->value];
        $affected='WITH RECURSIVE affected(id) AS (SELECT id FROM '.$this->db->table('dependency_nodes').
            ' WHERE tenant_id=? AND source_id=? AND (?::text IS NULL OR source_version_id=?) UNION SELECT e.child_id FROM '.
            $this->db->table('dependency_edges').' e JOIN affected a ON e.parent_id=a.id WHERE e.tenant_id=?) ';
        $usages=$this->db->execute($affected.'SELECT DISTINCT u.usage_id FROM '.$this->db->table('usage_refs').
            ' u JOIN affected a ON a.id=u.node_id WHERE u.tenant_id=? ORDER BY u.usage_id',[...$args,$this->tenant->value])->fetchAll(\PDO::FETCH_COLUMN);
        $this->db->execute($affected.'UPDATE '.$this->db->table('dependency_nodes').
            ' n SET invalidated_epoch=GREATEST(n.invalidated_epoch,?::bigint) FROM affected a WHERE n.tenant_id=? AND n.id=a.id',[...$args,$epoch,$this->tenant->value]);
        foreach ($usages as $usage) {
            $this->db->execute('INSERT INTO '.$this->db->table('usage_invalidations').
                '(tenant_id,id,usage_id,event_id,source_id,source_version_id) VALUES (?,?,?,?,?,?) ON CONFLICT (tenant_id,usage_id,event_id) DO NOTHING',
                [$this->tenant->value,$this->ids->next()->value,$usage,$w->get('eventId'),$source,$version]);
        }
    }

    private function guard(D\TenantId $tenant, bool $write=false): void
    {
        if (!$tenant->equals($this->tenant)) { self::fail('scope_mismatch',D\Outcome::Denied); }
        $this->tx->requireTenant($tenant,$write);
    }
    private function row(string $id): ?array
    { return $this->db->row('SELECT * FROM '.$this->db->table('dependency_nodes').' WHERE tenant_id=? AND id=?',[$this->tenant->value,$id]); }
    private function requireNode(D\ResourceRef $ref): array
    {
        $row=$this->row($ref->id->value);
        if ($row===null) { self::fail('required_parent_missing'); }
        if ($row['kind']!==$ref->kind) { self::fail('immutable_conflict'); } return $row;
    }
    private function parents(string $id): D\Parents
    {
        $refs=[];
        foreach ($this->db->execute('SELECT n.kind,n.id,e.relation FROM '.$this->db->table('dependency_edges').' e JOIN '.
            $this->db->table('dependency_nodes').' n ON n.tenant_id=e.tenant_id AND n.id=e.parent_id WHERE e.tenant_id=? AND e.child_id=? ORDER BY n.id',
            [$this->tenant->value,$id])->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['relation']!=='required') { self::fail('immutable_conflict'); } $refs[]=$this->ref($r['kind'],$r['id']);
        }
        return new D\Parents($this->tenant,$refs);
    }
    private function ref(string $kind,string $id): D\ResourceRef
    {
        return new D\ResourceRef($this->tenant,$kind,match ($kind) {
            'source'=>new D\SourceId($id),'source_version'=>new D\SourceVersionId($id),'document_revision'=>new D\DocumentRevisionId($id),
            'chunk'=>new D\ChunkId($id),'resource'=>new D\ResourceId($id),'directive_artifact'=>new D\Ulid($id),
            default=>self::fail('unsupported_node_kind'),
        });
    }
    private function state(D\ResourceRef $ref,string $source,?string $version,?string $invalidated): D\UsabilityReport
    {
        if ($this->db->row('SELECT id FROM '.$this->db->table('tombstones').' WHERE tenant_id=? AND source_id=?',[$this->tenant->value,$source])!==null) {
            return new D\UsabilityReport(D\Decision::Deny,new D\NodeRefs($this->tenant,[$ref]));
        }
        $row=$this->db->row('SELECT s.lifecycle_state,t.serving_state,v.usability,
            EXISTS (SELECT 1 FROM '.$this->db->table('tombstones').' z WHERE z.tenant_id=s.tenant_id AND z.source_id=s.id) AS tombstoned,
            EXISTS (SELECT 1 FROM '.$this->db->table('lifecycle_events')." e WHERE e.tenant_id=s.tenant_id AND e.source_id=s.id
                AND (e.type IN ('source.revoked','source.deletion_requested') OR (e.type='source_version.corrected' AND e.source_version_id=?))) AS marked
            FROM ".$this->db->table('sources').' s JOIN '.$this->db->table('tenants').' t ON t.id=s.tenant_id LEFT JOIN '.
            $this->db->table('source_version_state').' v ON v.tenant_id=s.tenant_id AND v.id=? WHERE s.tenant_id=? AND s.id=?',
            [$version,$version,$this->tenant->value,$source]);
        $decision=$row===null || ($version!==null && $row['usability']===null)?D\Decision::Unknown:D\Decision::Allow;
        if ($invalidated!==null || ($row!==null && ($row['tombstoned'] || $row['marked'] || $row['lifecycle_state']!=='active'
            || $row['serving_state']!=='ready' || ($row['usability']!==null && $row['usability']!=='usable')))) { $decision=D\Decision::Deny; }
        return new D\UsabilityReport($decision,new D\NodeRefs($this->tenant,$decision===D\Decision::Allow?[]:[$ref]));
    }
    private function requireUsable(D\UsabilityReport $report): void
    {
        if ($report->decision!==D\Decision::Allow) { self::fail($report->decision===D\Decision::Deny?'ancestor_unusable':'ancestor_unknown',
            $report->decision===D\Decision::Deny?D\Outcome::Denied:D\Outcome::Unknown); }
    }
    private static function fail(string $code,D\Outcome $outcome=D\Outcome::Incompatible): never { throw new D\DomainFailure($outcome,$code); }
}
