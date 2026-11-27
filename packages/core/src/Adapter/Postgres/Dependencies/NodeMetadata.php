<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Dependencies;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{DomainFailure, Outcome, ResourceRef};
use KnowKit\Domain\Contract\{JsonObject, StrictJson};

/** Exact catalog identities, without lifecycle filtering or payload I/O. */
final readonly class NodeMetadata
{
    public function __construct(private Connection $db, private PdoCatalogTransaction $tx)
    { if ($db!==$tx->db) { throw new \InvalidArgumentException('Shared transaction required'); } }

    /** @return array{source:string,version:?string,identity:JsonObject} */
    public function resolve(ResourceRef $ref): array
    {
        $this->tx->requireTenant($ref->tenantId);
        $t=$ref->tenantId->value; $id=$ref->id->value;
        if ($ref->kind==='source') {
            $r=$this->db->row('SELECT id FROM '.$this->db->table('sources').' WHERE tenant_id=? AND id=?',[$t,$id]);
            if ($r===null) { self::missing(); }
            return ['source'=>$id,'version'=>null,'identity'=>new JsonObject(['tenantId'=>$t,'sourceId'=>$id])];
        }
        if ($ref->kind==='resource') {
            $r=$this->db->row('SELECT source_id,digest,state FROM '.$this->db->table('resource_ledger').' WHERE tenant_id=? AND id=?',[$t,$id]);
            if ($r===null) { self::missing(); }
            if ($r['state']!=='accepted') { throw new DomainFailure(Outcome::Incompatible,'resource_not_accepted'); }
            return ['source'=>$r['source_id'],'version'=>null,'identity'=>new JsonObject([
                'tenantId'=>$t,'sourceId'=>$r['source_id'],'resourceId'=>$id,'contentDigest'=>$r['digest']])];
        }
        if ($ref->kind==='directive_artifact') {
            $r=$this->db->row('SELECT derived_from,artifact_digest FROM '.$this->db->table('directive_artifacts').' WHERE tenant_id=? AND id=?',[$t,$id]);
            if ($r===null) { self::missing(); }
            $derivedFrom=StrictJson::decode((string)$r['derived_from']);
            if (!is_array($derivedFrom) || $derivedFrom===[] || !$derivedFrom[0] instanceof JsonObject) { self::missing(); }
            return ['source'=>(string)$derivedFrom[0]->get('sourceId'),'version'=>null,'identity'=>new JsonObject([
                'tenantId'=>$t,'directiveId'=>$id,'artifactDigest'=>(string)$r['artifact_digest']])];
        }
        if ($ref->kind==='resolution_manifest') {
            $r=$this->db->row(
                'SELECT sv.source_id, dr.source_version_id, rm.manifest_digest FROM '.$this->db->table('resolution_manifests').
                ' rm JOIN '.$this->db->table('document_revisions').' dr ON dr.tenant_id=rm.tenant_id AND dr.id=rm.document_revision_id JOIN '.
                $this->db->table('source_versions').' sv ON sv.tenant_id=dr.tenant_id AND sv.id=dr.source_version_id WHERE rm.tenant_id=? AND rm.id=?',
                [$t,$id],
            );
            if ($r===null) { self::missing(); }
            return ['source'=>$r['source_id'],'version'=>$r['source_version_id'],'identity'=>new JsonObject([
                'tenantId'=>$t,'manifestId'=>$id,'manifestDigest'=>(string)$r['manifest_digest']])];
        }
        $version=$id; $revision=null; $chunk=null;
        if ($ref->kind==='chunk') {
            $chunk=$this->db->row('SELECT c.*,cs.revision_id FROM '.$this->db->table('chunks').' c JOIN '.$this->db->table('chunk_sets').
                ' cs ON cs.tenant_id=c.tenant_id AND cs.id=c.chunk_set_id WHERE c.tenant_id=? AND c.id=?',[$t,$id]);
            if ($chunk===null) { self::missing(); } $revision=$chunk['revision_id'];
        } elseif ($ref->kind==='document_revision') { $revision=$id; }
        elseif ($ref->kind!=='source_version') { throw new DomainFailure(Outcome::Incompatible,'unsupported_node_kind'); }
        $r=null;
        if ($revision!==null) {
            $r=$this->db->row('SELECT * FROM '.$this->db->table('document_revisions').' WHERE tenant_id=? AND id=?',[$t,$revision]);
            if ($r===null) { self::missing(); } $version=$r['source_version_id'];
        }
        $v=$this->db->row('SELECT source_id,source_digest FROM '.$this->db->table('source_versions').' WHERE tenant_id=? AND id=?',[$t,$version]);
        if ($v===null) { self::missing(); }
        $wire=new JsonObject(['tenantId'=>$t,'sourceId'=>$v['source_id'],'sourceVersionId'=>$version,'sourceDigest'=>$v['source_digest']]);
        if ($r!==null) {
            $wire=$wire->with('documentRevisionId',$revision)->with('irDigest',$r['ir_digest'])->with('parserSignature',new JsonObject([
                'name'=>$r['parser_name'],'version'=>$r['parser_version'],'profileDigest'=>$r['profile_digest']]));
        }
        if ($chunk!==null) { $wire=$wire->with('chunkSetId',$chunk['chunk_set_id'])->with('chunkId',$id)->with('chunkDigest',$chunk['content_digest']); }
        return ['source'=>$v['source_id'],'version'=>$version,'identity'=>$wire];
    }

    private static function missing(): never { throw new DomainFailure(Outcome::Incompatible,'required_parent_missing'); }
}
