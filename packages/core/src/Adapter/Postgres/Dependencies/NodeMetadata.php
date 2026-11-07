<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Dependencies;

use KnowKit\Adapter\Postgres\Catalog\{Connection, PdoCatalogTransaction};
use KnowKit\Domain\{DomainFailure, Outcome, ResourceRef};
use KnowKit\Domain\Contract\JsonObject;

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
