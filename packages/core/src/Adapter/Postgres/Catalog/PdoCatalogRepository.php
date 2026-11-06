<?php
declare(strict_types=1);

namespace KnowKit\Adapter\Postgres\Catalog;

use KnowKit\Domain\{AddMembership,BlobRef,CatalogMetadata,ChunkSet,Collection,CollectionFilter,CollectionId,CoveragePolicy,CreateCollection,CreateKB,Decimal,DocumentRevisionRef,DomainFailure,Fingerprint,Generation,GenerationId,KbId,KnowledgeBase,Membership,MutationContext,Operation,Outcome,PageCursor,ProfileId,PublicationSnapshot,RegisterSource,ResourceRef,Scope,SnapshotId,SnapshotRef,SnapshotSelector,Source,SourceDigest,SourceId,SourceLifecycleState,SourceMembership,SourcePage,SourceVersion,SourceVersionId,SourceVersionRef,Tenant,TenantId,Uint64,UtcInstant};
use KnowKit\Domain\Contract\{HashDomain,Jcs,JsonObject,StrictJson};
use KnowKit\Domain\{DomainEvent,Ulid};
use KnowKit\Port\{CatalogRepository,Clock,IdGenerator,Outbox};

/** Tenant-qualified SQL; callers use CatalogCommands or explicit guarded internal primitives. */
final readonly class PdoCatalogRepository implements CatalogRepository
{
    /**
     * @param \Closure(Scope,ResourceRef,Operation,JsonObject):void $checkReceipt local, preloaded receipt validation only
     * @param \Closure(TenantId,string,Ulid,Decimal,string,JsonObject):DomainEvent $createEvent local, offline event assembly only
     */
    public function __construct(private Connection $db,private PdoCatalogTransaction $transaction,
        private \Closure $checkReceipt,private Clock $clock,private IdGenerator $ids,
        private \Closure $createEvent,private Outbox $outbox,private string $cursorSecret)
    {
        if ($db!==$transaction->db || strlen($cursorSecret)<32) { throw new \InvalidArgumentException('Shared transaction and cursor authentication required'); }
    }
    private function table(string $name): string { return $this->db->table($name); }
    private function object(string $json): JsonObject { return StrictJson::decode($json,4194304); }
    private function fail(string $code='immutable_conflict',Outcome $outcome=Outcome::Incompatible): never { throw new DomainFailure($outcome,$code); }
    private function scope(TenantId $expected,TenantId $actual): void
    { if (!$expected->equals($actual)) { $this->fail('scope_mismatch',Outcome::Denied); } }
    /** An identity insert is either a byte-equivalent repeat or a typed conflict, never an overwrite. */
    private function insert(string $table,array $values,array $identity): bool
    {
        $columns=array_keys($values);
        $result=$this->db->execute('INSERT INTO '.$this->table($table).'('.implode(',',$columns).') VALUES ('.
            implode(',',array_fill(0,count($columns),'?')).') ON CONFLICT DO NOTHING',array_values($values));
        if ($result->rowCount()===1) { return true; }
        $where=implode(' AND ',array_map(static fn($k)=>$k.' IS NOT DISTINCT FROM ?',array_keys($identity)));
        $row=$this->db->row('SELECT * FROM '.$this->table($table).' WHERE '.$where,array_values($identity));
        if ($row===null) { $this->fail(); }
        foreach ($values as $k=>$v) {
            if (is_string($v) && ($v[0]??'')==='{') {
                if (Jcs::canonicalize($this->object($row[$k]))!==Jcs::canonicalize($this->object($v))) { $this->fail(); }
            } elseif (str_ends_with($k,'_at') && is_string($v)) {
                if ((new \DateTimeImmutable($row[$k])) != new \DateTimeImmutable($v)) { $this->fail(); }
            } elseif (is_string($v) && ($v[0]??'')==='[') {
                if (Jcs::canonicalize(StrictJson::decode($row[$k]))!==Jcs::canonicalize(StrictJson::decode($v))) { $this->fail(); }
            } elseif (is_bool($v)) { if ($row[$k]!==$v) { $this->fail(); } }
            elseif ($v===null ? $row[$k]!==null : (string)$row[$k]!== (string)$v) { $this->fail(); }
        }
        return false;
    }
    private function kb(array $r): KnowledgeBase
    {
        $c=$this->object($r['coverage_policy']);
        return new KnowledgeBase(new TenantId($r['tenant_id']),new KbId($r['id']),new Decimal((string)$r['kb_policy_epoch']),
            $r['active_snapshot_id']===null?null:new SnapshotId($r['active_snapshot_id']),new Decimal((string)$r['pointer_version']),
            $r['name'],new Decimal((string)$r['revision']),$r['policy_ref'],new ProfileId($r['default_profile']),
            new CoveragePolicy($c->get('allowPartial'),$c->get('requiredFeatures')));
    }
    private function source(array $r): Source
    {
        return new Source(new TenantId($r['tenant_id']),new SourceId($r['id']),SourceLifecycleState::from($r['lifecycle_state']),
            new Decimal((string)$r['lifecycle_epoch']),new Decimal((string)$r['source_policy_epoch']),$r['retention_hold'],$r['owner_ref'],$r['origin']);
    }
    private function collection(array $r): Collection
    {
        return new Collection(new TenantId($r['tenant_id']),new KbId($r['kb_id']),new CollectionId($r['id']),
            $r['parent_id']===null?null:new CollectionId($r['parent_id']),new Decimal((string)$r['revision']),new CatalogMetadata($this->object($r['metadata'])));
    }
    private function membership(array $r): SourceMembership
    {
        return new SourceMembership(new TenantId($r['tenant_id']),new KbId($r['kb_id']),
            $r['collection_id']===null?null:new CollectionId($r['collection_id']),new SourceId($r['source_id']),
            new Decimal((string)$r['revision']),new CatalogMetadata($this->object($r['metadata'])));
    }
    public function getTenant(TenantId $tenantId): ?Tenant
    {
        $r=$this->db->row('SELECT * FROM '.$this->table('tenants').' WHERE id=?',[$tenantId->value]);
        return $r===null?null:new Tenant(new TenantId($r['id']),$r['serving_state']);
    }
    public function getKb(TenantId $tenantId,KbId $kbId): ?KnowledgeBase
    {
        $r=$this->db->row('SELECT * FROM '.$this->table('knowledge_bases').' WHERE tenant_id=? AND id=?',[$tenantId->value,$kbId->value]);
        return $r===null?null:$this->kb($r);
    }
    public function findKnowledgeBase(TenantId $tenantId,KbId $kbId): ?KnowledgeBase { return $this->getKb($tenantId,$kbId); }
    public function getCollection(TenantId $tenantId,KbId $kbId,CollectionId $collectionId): ?Collection
    {
        $r=$this->db->row('SELECT * FROM '.$this->table('collections').' WHERE tenant_id=? AND kb_id=? AND id=?',[$tenantId->value,$kbId->value,$collectionId->value]);
        return $r===null?null:$this->collection($r);
    }
    public function getSource(TenantId $tenantId,SourceId $sourceId): ?Source
    {
        $r=$this->db->row('SELECT * FROM '.$this->table('sources').' WHERE tenant_id=? AND id=?',[$tenantId->value,$sourceId->value]);
        return $r===null?null:$this->source($r);
    }
    public function getSourceVersion(TenantId $tenantId,SourceVersionId $sourceVersionId): ?SourceVersion
    {
        $r=$this->db->row('SELECT v.*,r.storage_id,r.object_version,r.owner_kind FROM '.$this->table('source_versions').' v JOIN '.
            $this->table('resource_ledger').' r ON r.tenant_id=v.tenant_id AND r.source_id=v.source_id AND r.id=v.blob_resource_id
            WHERE v.tenant_id=? AND v.id=?',[$tenantId->value,$sourceVersionId->value]);
        if ($r===null) { return null; }
        $t=new TenantId($r['tenant_id']); $s=new SourceId($r['source_id']); $digest=new SourceDigest($r['source_digest']);
        return new SourceVersion(new SourceVersionRef($t,$s,new SourceVersionId($r['id']),$digest),
            new BlobRef($t,$s,$digest,new Uint64((string)$r['byte_length']),$r['storage_id'],$r['object_version'],$r['owner_kind']),
            $r['media_type'],$r['rights_ref'],new UtcInstant((new \DateTimeImmutable($r['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')));
    }
    public function getVersion(TenantId $tenantId,SourceVersionRef $ref): ?SourceVersion
    {
        $this->scope($tenantId,$ref->tenantId); $version=$this->getSourceVersion($tenantId,$ref->sourceVersionId);
        if ($version!==null && $version->ref->toWire()!=$ref->toWire()) { $this->fail(); } return $version;
    }
    public function listMemberships(TenantId $tenantId,SourceId $sourceId): array
    {
        $rows=$this->db->execute('SELECT * FROM '.$this->table('source_memberships').' WHERE tenant_id=? AND source_id=? ORDER BY kb_id,collection_id NULLS FIRST',[$tenantId->value,$sourceId->value])->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($r)=>$this->membership($r),$rows);
    }
    private function pageBinding(PageCursor $c): string
    { return Jcs::canonicalize(new JsonObject(['tenant'=>$c->tenantId->value,'kb'=>$c->kbId->value,'collection'=>$c->filter->collectionId?->value,'security'=>$c->securityScopeDigest->value,'limit'=>$c->limit])); }
    public function listSources(TenantId $tenantId,KbId $kbId,CollectionFilter $filter,PageCursor $cursor): SourcePage
    {
        $cursor->assertBinding($tenantId,$kbId,$filter,$cursor->securityScopeDigest); $after='';
        if ($cursor->token!==null) {
            $parts=explode('.',$cursor->token);
            if (count($parts)!==2 || !hash_equals(hash_hmac('sha256',$this->pageBinding($cursor).'\0'.$parts[0],$this->cursorSecret),$parts[1])) { $this->fail('scope_mismatch',Outcome::Denied); }
            $after=(new SourceId($parts[0]))->value;
        }
        $params=[$tenantId->value,$kbId->value]; $where='';
        if ($filter->collectionId!==null) { $where=' AND m.collection_id=?'; $params[]=$filter->collectionId->value; }
        $params[]=$after;
        $rows=$this->db->execute('SELECT s.* FROM '.$this->table('sources').' s WHERE s.tenant_id=? AND EXISTS (SELECT 1 FROM '.
            $this->table('source_memberships').' m WHERE m.tenant_id=s.tenant_id AND m.source_id=s.id AND m.kb_id=?'.$where.') AND s.id::text>?
            ORDER BY s.id LIMIT '.($cursor->limit+1),$params)->fetchAll(\PDO::FETCH_ASSOC);
        $more=count($rows)>$cursor->limit; if ($more) { array_pop($rows); }
        $next=null;
        if ($more) {
            $last=$rows[array_key_last($rows)]['id']; $token=$last.'.'.hash_hmac('sha256',$this->pageBinding($cursor).'\0'.$last,$this->cursorSecret);
            $next=new PageCursor($tenantId,$kbId,$filter,$cursor->securityScopeDigest,$token,$cursor->limit);
        }
        return new SourcePage($cursor,array_map(fn($r)=>$this->source($r),$rows),$next);
    }
    public function resolveSnapshot(TenantId $tenantId,KbId $kbId,SnapshotSelector $selector,ProfileId $profileId): ?PublicationSnapshot
    {
        $selector->assertScope($tenantId,$kbId);
        $id=$selector->ref===null ? $this->getKb($tenantId,$kbId)?->activeSnapshotId : $selector->ref->snapshotId;
        if ($id===null) { return null; }
        $r=$this->db->row('SELECT * FROM '.$this->table('publication_snapshots').' WHERE tenant_id=? AND kb_id=? AND id=? AND sealed',[$tenantId->value,$kbId->value,$id->value]);
        if ($r===null) { return null; }
        $rows=$this->db->execute('SELECT generation_id,profile_id FROM '.$this->table('snapshot_generations').' WHERE tenant_id=? AND snapshot_id=? ORDER BY generation_id',[$tenantId->value,$id->value])->fetchAll(\PDO::FETCH_ASSOC);
        if (!in_array($profileId->value,array_column($rows,'profile_id'),true)) { $this->fail('profile_incompatible'); }
        $snapshot=new PublicationSnapshot(new SnapshotRef($tenantId,$kbId,$id,new Fingerprint($r['manifest_digest'])),
            array_map(static fn($g)=>new GenerationId($g),array_values(array_unique(array_column($rows,'generation_id')))));
        $selector->assertResolved($snapshot); return $snapshot;
    }
    public function insertTenant(TenantId $tenantId,Tenant $tenant): void
    {
        $this->scope($tenantId,$tenant->tenantId); $this->transaction->requireTenant($tenantId,true);
        $this->insert('tenants',['id'=>$tenantId->value,'name'=>$tenantId->value,'serving_state'=>$tenant->servingState],['id'=>$tenantId->value]);
    }
    public function insertKb(TenantId $tenantId,KnowledgeBase $kb): void
    {
        $this->scope($tenantId,$kb->tenantId); $this->transaction->requireTenant($tenantId,true);
        $this->insert('knowledge_bases',['tenant_id'=>$tenantId->value,'id'=>$kb->kbId->value,'name'=>$kb->name,'revision'=>$kb->revision->value,
            'policy_ref'=>$kb->policyRef,'active_snapshot_id'=>$kb->activeSnapshotId?->value,'pointer_version'=>$kb->pointerVersion->value,
            'kb_policy_epoch'=>$kb->policyEpoch->value,'coverage_policy'=>Jcs::canonicalize(new JsonObject(['allowPartial'=>$kb->coveragePolicy->allowPartial,
                'requiredFeatures'=>$kb->coveragePolicy->requiredFeatures])),'default_profile'=>$kb->defaultRetrievalProfile->value],['tenant_id'=>$tenantId->value,'id'=>$kb->kbId->value]);
    }
    public function insertCollection(TenantId $tenantId,Collection $collection): void
    {
        $this->scope($tenantId,$collection->tenantId); $this->transaction->requireTenant($tenantId,true);
        $ancestors=[]; $parent=$collection->parentId; $seen=[];
        while ($parent!==null) {
            if (isset($seen[$parent->value])) { $this->fail('collection_cycle'); } $seen[$parent->value]=true;
            $next=$this->getCollection($tenantId,$collection->kbId,$parent);
            if ($next===null) { $this->fail('collection_parent_missing'); } $ancestors[]=$next; $parent=$next->parentId;
        }
        $collection->assertAncestry($ancestors);
        $this->insert('collections',['tenant_id'=>$tenantId->value,'id'=>$collection->collectionId->value,'kb_id'=>$collection->kbId->value,
            'parent_id'=>$collection->parentId?->value,'name'=>$collection->collectionId->value,'revision'=>$collection->revision->value,
            'metadata'=>Jcs::canonicalize($collection->metadata->toWire())],['tenant_id'=>$tenantId->value,'id'=>$collection->collectionId->value]);
    }
    public function insertSource(TenantId $tenantId,Source $source): void
    {
        $this->scope($tenantId,$source->tenantId); $this->transaction->requireTenant($tenantId,true);
        if ($source->lifecycleState!==SourceLifecycleState::Active) { $this->fail('registration_requires_active'); }
        $inserted=$this->insert('sources',['tenant_id'=>$tenantId->value,'id'=>$source->sourceId->value,'owner_ref'=>$source->ownerRef,'origin'=>$source->origin,
            'lifecycle_state'=>$source->lifecycleState->value,'lifecycle_epoch'=>$source->lifecycleEpoch->value,
            'source_policy_epoch'=>$source->sourcePolicyEpoch->value,'retention_hold'=>$source->retentionHold],['tenant_id'=>$tenantId->value,'id'=>$source->sourceId->value]);
        if ($inserted) {
            $this->outbox->append(($this->createEvent)($tenantId,'source',$source->sourceId,new Decimal('0'),'source.registered',new JsonObject([
                'sourceId'=>$source->sourceId->value,'lifecycleState'=>$source->lifecycleState->value,'lifecycleEpoch'=>$source->lifecycleEpoch->value,
                'sourcePolicyEpoch'=>$source->sourcePolicyEpoch->value,'retentionHold'=>$source->retentionHold])));
        }
    }
    public function insertMembership(TenantId $tenantId,Membership $membership): void
    {
        $this->scope($tenantId,$membership->tenantId); $this->transaction->requireTenant($tenantId,true);
        $identity=['tenant_id'=>$tenantId->value,'source_id'=>$membership->sourceId->value,'kb_id'=>$membership->kbId->value,'collection_id'=>$membership->collectionId?->value];
        $existing=$this->db->row('SELECT id FROM '.$this->table('source_memberships').' WHERE tenant_id=? AND source_id=? AND kb_id=? AND collection_id IS NOT DISTINCT FROM ?',array_values($identity));
        $this->insert('source_memberships',['id'=>$existing['id']??$this->ids->next()->value,...$identity,'revision'=>$membership->revision->value,
            'metadata'=>Jcs::canonicalize($membership->metadata->toWire())],$identity);
    }
    private function blobResource(BlobRef $blob): string
    {
        $rows=$this->db->execute('SELECT id FROM '.$this->table('resource_ledger').' WHERE tenant_id=? AND source_id=? AND digest=? AND byte_length=?
            AND storage_id=? AND object_version=? AND owner_kind=? AND state=\'accepted\' ORDER BY id LIMIT 2',
            [$blob->tenantId->value,$blob->sourceId->value,$blob->digest->value,$blob->byteLength->value,$blob->storageId,$blob->objectVersion,$blob->ownerKind])->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows)!==1) { $this->fail('blob_reservation_required'); } return $rows[0]['id'];
    }
    public function insertSourceVersion(TenantId $tenantId,SourceVersion $version,?Fingerprint $requestFingerprint=null): void
    {
        $this->scope($tenantId,$version->ref->tenantId); $this->transaction->requireTenant($tenantId,true);
        $w=$version->ref->toWire();
        $requestFingerprint??=new Fingerprint(HashDomain::IdempotencyRequest->digest(new JsonObject(['operation'=>Operation::SourceVersionAdd->value,
            'businessFields'=>$w->with('mime',$version->mime)->with('rightsRef',$version->rightsRef),'expectedRevision'=>null,'expectedEpoch'=>null])));
        $values=['tenant_id'=>$tenantId->value,'id'=>$version->ref->sourceVersionId->value,'source_id'=>$version->ref->sourceId->value,
            'blob_resource_id'=>$this->blobResource($version->blob),'source_digest'=>$version->ref->sourceDigest->value,
            'byte_length'=>$version->blob->byteLength->value,'media_type'=>$version->mime,'rights_ref'=>$version->rightsRef,
            'idempotency_fingerprint'=>$requestFingerprint->value,
            'created_at'=>$version->createdAt->value];
        // Compare instants canonically before the generic insert comparison (PostgreSQL renders UTC differently).
        $old=$this->getSourceVersion($tenantId,$version->ref->sourceVersionId);
        if ($old!==null) {
            if ($old->ref->toWire()!=$w || $old->blob!=$version->blob || $old->mime!==$version->mime || $old->rightsRef!==$version->rightsRef
                || $old->createdAt->compare($version->createdAt)!==0) { $this->fail(); } return;
        }
        $this->insert('source_versions',$values,['tenant_id'=>$tenantId->value,'id'=>$version->ref->sourceVersionId->value]);
        $this->db->execute('INSERT INTO '.$this->table('source_version_state').'(tenant_id,id,usability) VALUES (?,?,\'usable\')',[$tenantId->value,$version->ref->sourceVersionId->value]);
        $this->outbox->append(($this->createEvent)($tenantId,'source_version',$version->ref->sourceVersionId,new Decimal('0'),
            'source_version.accepted',$w->without('tenantId')));
    }
    /** Ingestion supplies validated metadata; an exact ref alone cannot invent IR resources/coverage. */
    public function insertDocumentRevision(TenantId $tenantId,DocumentRevisionRef $revision,?RevisionMetadata $metadata=null): void
    {
        $this->scope($tenantId,$revision->sourceRef->tenantId); $this->transaction->requireTenant($tenantId,true);
        if ($metadata===null) { $this->fail('revision_metadata_required'); }
        $source=$this->getSourceVersion($tenantId,$revision->sourceRef->sourceVersionId);
        if ($source===null || $source->ref->toWire()!=$revision->sourceRef->toWire()) { $this->fail(); }
        $resource=$this->db->row('SELECT id FROM '.$this->table('resource_ledger').' WHERE tenant_id=? AND source_id=? AND id=? AND digest=? AND state=\'accepted\'',
            [$tenantId->value,$revision->sourceRef->sourceId->value,$metadata->irResourceId->value,$revision->irDigest->value]);
        if ($resource===null) { $this->fail('ir_resource_required'); }
        $inserted=$this->insert('document_revisions',['tenant_id'=>$tenantId->value,'id'=>$revision->documentRevisionId->value,
            'source_version_id'=>$revision->sourceRef->sourceVersionId->value,'parser_name'=>$revision->parserSignature->name,
            'parser_version'=>$revision->parserSignature->version,'profile_digest'=>$revision->parserSignature->profileDigest->value,
            'ir_schema'=>$metadata->irSchema,'ir_digest'=>$revision->irDigest->value,'ir_resource_id'=>$metadata->irResourceId->value,
            'coverage_state'=>$metadata->coverage->toWire()->get('state'),'coverage_json'=>Jcs::canonicalize($metadata->coverage->toWire()),
            'created_at'=>$metadata->createdAt->value],['tenant_id'=>$tenantId->value,'id'=>$revision->documentRevisionId->value]);
        if ($inserted) { $this->outbox->append(($this->createEvent)($tenantId,'document_revision',$revision->documentRevisionId,new Decimal('0'),
            'document_revision.accepted',$revision->toWire()->without('tenantId'))); }
    }
    public function insertChunkSet(TenantId $tenantId,ChunkSet $chunkSet,?ChunkSetMetadata $metadata=null): void
    {
        $this->scope($tenantId,$chunkSet->revisionRef->sourceRef->tenantId); $this->transaction->requireTenant($tenantId,true);
        if ($metadata===null || count($metadata->chunks)!==count($chunkSet->chunks)) { $this->fail('chunk_metadata_required'); }
        $revision=$chunkSet->revisionRef;
        if ($this->db->row('SELECT r.id FROM '.$this->table('document_revisions').' r JOIN '.$this->table('source_versions').
            ' v ON v.tenant_id=r.tenant_id AND v.id=r.source_version_id WHERE r.tenant_id=? AND r.id=? AND r.ir_digest=? AND r.parser_name=? AND r.parser_version=?
            AND r.profile_digest=? AND v.id=? AND v.source_id=? AND v.source_digest=?',[$tenantId->value,$revision->documentRevisionId->value,$revision->irDigest->value,
                $revision->parserSignature->name,$revision->parserSignature->version,$revision->parserSignature->profileDigest->value,
                $revision->sourceRef->sourceVersionId->value,$revision->sourceRef->sourceId->value,$revision->sourceRef->sourceDigest->value])===null) { $this->fail(); }
        $this->insert('chunk_sets',['tenant_id'=>$tenantId->value,'id'=>$chunkSet->chunkSetId->value,'revision_id'=>$chunkSet->revisionRef->documentRevisionId->value,
            'chunker_signature'=>Jcs::canonicalize($chunkSet->chunkerSignature->toWire()),'manifest_digest'=>$metadata->manifestDigest->value],
            ['tenant_id'=>$tenantId->value,'id'=>$chunkSet->chunkSetId->value]);
        foreach ($chunkSet->chunks as $chunk) {
            $details=$metadata->chunks[$chunk->ref->chunkId->value]??null;
            if (!$details instanceof ChunkMetadata) { $this->fail('chunk_metadata_required'); }
            $r=$this->db->row('SELECT id FROM '.$this->table('resource_ledger').' WHERE tenant_id=? AND source_id=? AND id=? AND digest=? AND byte_length=? AND state=\'accepted\'',
                [$tenantId->value,$chunkSet->revisionRef->sourceRef->sourceId->value,$details->canonicalResourceId->value,$chunk->ref->chunkDigest->value,strlen($chunk->text())]);
            if ($r===null) { $this->fail('chunk_resource_required'); }
            $this->insert('chunks',['tenant_id'=>$tenantId->value,'id'=>$chunk->ref->chunkId->value,'chunk_set_id'=>$chunkSet->chunkSetId->value,
                'content_digest'=>$chunk->ref->chunkDigest->value,'document_id'=>$chunk->documentId->value,
                'spans_json'=>Jcs::canonicalize(array_map(static fn($s)=>$s->locator->toWire()->with('textDigest',$s->textDigest->value),$chunk->spans)),
                'provenance_json'=>Jcs::canonicalize($details->provenance),'byte_length'=>(string)strlen($chunk->text()),
                'structure_path'=>Jcs::canonicalize($details->structurePath),'canonical_payload_ref'=>$r['id']],
                ['tenant_id'=>$tenantId->value,'id'=>$chunk->ref->chunkId->value]);
        }
    }
    public function insertGeneration(TenantId $tenantId,Generation $generation,?ProfileId $buildingProfile=null): void
    {
        $this->scope($tenantId,$generation->tenantId); $this->transaction->requireTenant($tenantId,true);
        if ($generation->state!=='building') { $this->fail('generation_requires_seal_workflow'); }
        $m=$generation->manifest?->toWire(); $profile=$m?->get('profile')?->get('id')??$buildingProfile?->value;
        if ($profile===null) { $this->fail('generation_profile_required'); }
        $this->insert('index_generations',['tenant_id'=>$tenantId->value,'id'=>$generation->generationId->value,'kb_id'=>$generation->kbId->value,
            'profile_id'=>$profile,'state'=>$generation->state,'manifest_digest'=>$m===null?null:HashDomain::IndexManifest->digest($m),'manifest_json'=>$generation->manifest?->canonical(),
            'embedding_space_digest'=>$m?->get('embeddingSpaceDigest'),'chunk_count'=>(string)($m?->get('counts')?->get('chunks')??0)],
            ['tenant_id'=>$tenantId->value,'id'=>$generation->generationId->value]);
    }
    public function insertSnapshot(TenantId $tenantId,PublicationSnapshot $snapshot): void
    {
        $this->scope($tenantId,$snapshot->ref->tenantId); $this->transaction->requireTenant($tenantId,true);
        $old=$this->db->row('SELECT * FROM '.$this->table('publication_snapshots').' WHERE tenant_id=? AND id=?',[$tenantId->value,$snapshot->ref->snapshotId->value]);
        if ($old!==null) {
            $stored=$this->db->execute('SELECT generation_id FROM '.$this->table('snapshot_generations').' WHERE tenant_id=? AND snapshot_id=? ORDER BY generation_id',
                [$tenantId->value,$snapshot->ref->snapshotId->value])->fetchAll(\PDO::FETCH_COLUMN);
            $given=array_map(static fn($g)=>$g->value,$snapshot->generationIds); sort($given,SORT_STRING);
            if ($old['kb_id']!==$snapshot->ref->kbId->value || !hash_equals($old['manifest_digest'],$snapshot->ref->manifestDigest->value) || $stored!==$given) { $this->fail(); }
            return;
        }
        // Exact membership manifests are assembled by publication; this primitive creates an unsealed staging snapshot.
        $this->insert('publication_snapshots',['tenant_id'=>$tenantId->value,'id'=>$snapshot->ref->snapshotId->value,'kb_id'=>$snapshot->ref->kbId->value,
            'manifest_digest'=>$snapshot->ref->manifestDigest->value,'created_at'=>$this->clock->now()->value],['tenant_id'=>$tenantId->value,'id'=>$snapshot->ref->snapshotId->value]);
        foreach ($snapshot->generationIds as $id) {
            $g=$this->db->row('SELECT id,profile_id FROM '.$this->table('index_generations').' WHERE tenant_id=? AND kb_id=? AND id=? AND state IN (\'sealed\',\'active\',\'retired\')',
                [$tenantId->value,$snapshot->ref->kbId->value,$id->value]);
            if ($g===null) { $this->fail('generation_unsealed'); }
            $this->insert('snapshot_generations',['tenant_id'=>$tenantId->value,'snapshot_id'=>$snapshot->ref->snapshotId->value,
                'kb_id'=>$snapshot->ref->kbId->value,'generation_id'=>$id->value,'profile_id'=>$g['profile_id']],
                ['tenant_id'=>$tenantId->value,'snapshot_id'=>$snapshot->ref->snapshotId->value,'generation_id'=>$id->value]);
        }
    }
    private function authorize(Scope $access,ResourceRef $resource,Operation $operation,array $sources=[]): array
    {
        $this->transaction->requireTenant($access->tenantId,true);
        $tenant=$this->db->row('SELECT * FROM '.$this->table('tenants').' WHERE id=?',[$access->tenantId->value]);
        if ($tenant===null || $tenant['serving_state']!=='ready') { $this->fail('tenant_blocked',Outcome::Unavailable); }
        $kb=$this->getKb($access->tenantId,$access->kbId);
        $removed=$this->db->row('SELECT removed_at FROM '.$this->table('knowledge_bases').' WHERE tenant_id=? AND id=?',[$access->tenantId->value,$access->kbId->value]);
        if (($removed['removed_at']??null)!==null && $operation!==Operation::LifecycleDelete) { $this->fail('knowledge_base_removed',Outcome::Denied); }
        $binding=new JsonObject(['tenantPolicyEpoch'=>(string)$tenant['tenant_policy_epoch'],
            'kbPolicyEpoch'=>$kb?->policyEpoch->value??'0','sources'=>$sources]);
        ($this->checkReceipt)($access,$resource,$operation,$binding); return [$tenant,$kb];
    }
    private function replay(Scope $s,MutationContext $c,Operation $operation,Fingerprint $digest): ?array
    {
        $r=$this->db->row('SELECT * FROM '.$this->table('idempotency_receipts').' WHERE tenant_id=? AND key=?',[$s->tenantId->value,$c->idempotencyKey()->value]);
        if ($r===null) { return null; }
        if ($r['operation']!==$operation->value || !hash_equals($digest->value,$r['request_fingerprint'])) { $this->fail('idempotency_conflict'); }
        return json_decode($r['response_blob'],true,512,JSON_THROW_ON_ERROR);
    }
    private function accept(Scope $s,MutationContext $c,Operation $operation,Fingerprint $digest,array $response): void
    {
        $this->db->execute('INSERT INTO '.$this->table('idempotency_receipts').'(tenant_id,id,key,operation,request_fingerprint,response_blob,accepted_at) VALUES (?,?,?,?,?,?::jsonb,?)',
            [$s->tenantId->value,$this->ids->next()->value,$c->idempotencyKey()->value,$operation->value,$digest->value,json_encode($response,JSON_THROW_ON_ERROR),$this->clock->now()->value]);
    }
    private function bump(Scope $access,KnowledgeBase $kb): void
    {
        DomainFailure::requireWrite($this->db->execute('UPDATE '.$this->table('knowledge_bases').' SET revision=revision+1 WHERE tenant_id=? AND id=? AND revision=?',
            [$access->tenantId->value,$kb->kbId->value,$kb->revision->value])->rowCount()===1);
    }
    public function createKnowledgeBase(Scope $access,CreateKB $command,MutationContext $context): KnowledgeBase
    {
        $digest=$command->requestDigest($access,$context); [$tenant,$existing]=$this->authorize($access,new ResourceRef($access->tenantId,'knowledge_base',$command->kbId),Operation::CatalogCreate);
        $replay=$this->replay($access,$context,Operation::CatalogCreate,$digest);
        if ($replay!==null) { return $this->kb($replay); }
        $context->assertExpected(new Decimal('0'),new Decimal('0')); if ($existing!==null) { $this->fail('identity_exists'); }
        $kb=new KnowledgeBase($access->tenantId,$command->kbId,new Decimal('0'),null,new Decimal('0'),$command->name,
            new Decimal('0'),$command->policyRef,$command->defaultRetrievalProfile,$command->coveragePolicy);
        $this->insertKb($access->tenantId,$kb);
        $row=$this->db->row('SELECT * FROM '.$this->table('knowledge_bases').' WHERE tenant_id=? AND id=?',[$access->tenantId->value,$command->kbId->value]);
        $this->accept($access,$context,Operation::CatalogCreate,$digest,$row); return $kb;
    }
    public function createCollection(Scope $access,CreateCollection $command,MutationContext $context): Collection
    {
        $digest=$command->requestDigest($access,$context); [, $kb]=$this->authorize($access,new ResourceRef($access->tenantId,'knowledge_base',$access->kbId),Operation::CollectionCreate);
        if ($command->parentId!==null) { $this->authorize($access,new ResourceRef($access->tenantId,'collection',$command->parentId),Operation::CollectionCreate); }
        $replay=$this->replay($access,$context,Operation::CollectionCreate,$digest); if ($replay!==null) { return $this->collection($replay); }
        if ($kb===null) { $this->fail('resource_missing'); } $context->assertExpected($kb->revision,$kb->policyEpoch);
        if ($this->getCollection($access->tenantId,$access->kbId,$command->collectionId)!==null) { $this->fail('identity_exists'); }
        $collection=new Collection($access->tenantId,$access->kbId,$command->collectionId,$command->parentId,new Decimal('0'),$command->metadata);
        $this->insertCollection($access->tenantId,$collection); $this->bump($access,$kb);
        $row=$this->db->row('SELECT * FROM '.$this->table('collections').' WHERE tenant_id=? AND id=?',[$access->tenantId->value,$command->collectionId->value]);
        $this->accept($access,$context,Operation::CollectionCreate,$digest,$row); return $collection;
    }
    public function addMembership(Scope $access,AddMembership $command,MutationContext $context): SourceMembership
    {
        $digest=$command->requestDigest($access,$context); $source=$this->getSource($access->tenantId,$command->sourceId);
        if ($source===null) { $this->fail('resource_missing'); }
        $this->authorize($access,new ResourceRef($access->tenantId,'knowledge_base',$access->kbId),Operation::MembershipMutate);
        if ($command->collectionId!==null) {
            $this->authorize($access,new ResourceRef($access->tenantId,'collection',$command->collectionId),Operation::MembershipMutate);
            if ($this->getCollection($access->tenantId,$access->kbId,$command->collectionId)===null) { $this->fail('collection_parent_missing'); }
        }
        [, $kb]=$this->authorize($access,new ResourceRef($access->tenantId,'source',$command->sourceId),Operation::MembershipMutate,[new JsonObject([
            'sourceId'=>$source->sourceId->value,'sourcePolicyEpoch'=>$source->sourcePolicyEpoch->value,'lifecycleEpoch'=>$source->lifecycleEpoch->value])]);
        if ($source->lifecycleState!==SourceLifecycleState::Active || $this->db->row('SELECT id FROM '.$this->table('tombstones').' WHERE tenant_id=? AND source_id=?',
            [$access->tenantId->value,$command->sourceId->value])!==null) { $this->fail('source_revoked',Outcome::Denied); }
        $replay=$this->replay($access,$context,Operation::MembershipMutate,$digest); if ($replay!==null) { return $this->membership($replay); }
        if ($kb===null) { $this->fail('resource_missing'); } $context->assertExpected($kb->revision,$kb->policyEpoch);
        $membership=new SourceMembership($access->tenantId,$access->kbId,$command->collectionId,$command->sourceId,new Decimal('0'),$command->metadata);
        $this->insertMembership($access->tenantId,$membership); $this->bump($access,$kb);
        $row=$this->db->row('SELECT * FROM '.$this->table('source_memberships').' WHERE tenant_id=? AND source_id=? AND kb_id=? AND collection_id IS NOT DISTINCT FROM ?',
            [$access->tenantId->value,$command->sourceId->value,$access->kbId->value,$command->collectionId?->value]);
        $this->accept($access,$context,Operation::MembershipMutate,$digest,$row); return $membership;
    }
    public function removeKnowledgeBase(Scope $scope,MutationContext $context): array
    {
        $operation=Operation::LifecycleDelete;
        $digest=$context->requestDigest($operation,new JsonObject(['tenantId'=>$scope->tenantId->value,'kbId'=>$scope->kbId->value,'kind'=>'knowledge_base']));
        [, $kb]=$this->authorize($scope,new ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId),$operation);
        $replay=$this->replay($scope,$context,$operation,$digest); if ($replay!==null) { return $replay['tasks']; }
        if ($kb===null) { $this->fail('resource_missing'); } $context->assertExpected($kb->revision,$kb->policyEpoch);
        $rows=$this->db->execute('SELECT DISTINCT source_id FROM '.$this->table('source_memberships').' WHERE tenant_id=? AND kb_id=? ORDER BY source_id',
            [$scope->tenantId->value,$scope->kbId->value])->fetchAll(\PDO::FETCH_COLUMN);
        $tasks=[];
        foreach ($rows as $id) {
            $source=$this->getSource($scope->tenantId,new SourceId($id));
            $this->authorize($scope,new ResourceRef($scope->tenantId,'source',new SourceId($id)),$operation,[new JsonObject([
                'sourceId'=>$id,'sourcePolicyEpoch'=>$source->sourcePolicyEpoch->value,'lifecycleEpoch'=>$source->lifecycleEpoch->value])]);
            $other=$this->db->row('SELECT id FROM '.$this->table('source_memberships').' WHERE tenant_id=? AND source_id=? AND kb_id<>? LIMIT 1',
                [$scope->tenantId->value,$id,$scope->kbId->value]);
            if ($other!==null) { continue; } // Source bytes may still serve another KB.
            $resources=$this->db->execute('SELECT * FROM '.$this->table('resource_ledger').' WHERE tenant_id=? AND source_id=? AND owner_kind=\'owned\' AND state<>\'deleted\' ORDER BY id',
                [$scope->tenantId->value,$id])->fetchAll(\PDO::FETCH_ASSOC);
            if ($resources===[]) { continue; } // Externally owned objects are detached, never deleted implicitly.
            $required=array_map(static fn($r)=>new JsonObject(['resourceId'=>$r['id'],'digest'=>$r['digest'],'objectVersion'=>$r['object_version']]),$resources);
            $task=$this->ids->next()->value; $inventory=HashDomain::ResourceInventory->digest($required);
            $this->db->execute('INSERT INTO '.$this->table('deletion_tasks').'(tenant_id,id,source_id,status,required_resources_json,inventory_epoch,inventory_digest,hold_reason) VALUES (?,?,?,\'pending\',?::jsonb,?,?,?)',
                [$scope->tenantId->value,$task,$id,Jcs::canonicalize($required),'0',$inventory,$source->retentionHold?'retention_hold':null]);
            $tasks[]=$task;
        }
        $this->db->execute('DELETE FROM '.$this->table('source_memberships').' WHERE tenant_id=? AND kb_id=?',[$scope->tenantId->value,$scope->kbId->value]);
        DomainFailure::requireWrite($this->db->execute('UPDATE '.$this->table('knowledge_bases').' SET removed_at=?,revision=revision+1,active_snapshot_id=NULL,pointer_version=pointer_version+1 WHERE tenant_id=? AND id=? AND revision=? AND removed_at IS NULL',
            [$this->clock->now()->value,$scope->tenantId->value,$scope->kbId->value,$kb->revision->value])->rowCount()===1);
        $this->accept($scope,$context,$operation,$digest,['tasks'=>$tasks]); return $tasks;
    }
    public function registerSourceMetadata(Scope $scope,RegisterSource $command,MutationContext $context): Source
    {
        $op=Operation::SourceRegister; $resource=new ResourceRef($scope->tenantId,'source',$command->sourceId);
        $current=$this->getSource($scope->tenantId,$command->sourceId); $sources=$current===null?[]:[new JsonObject([
            'sourceId'=>$current->sourceId->value,'lifecycleEpoch'=>$current->lifecycleEpoch->value,'sourcePolicyEpoch'=>$current->sourcePolicyEpoch->value])];
        $this->authorize($scope,$resource,$op,$sources);
        $digest=$context->requestDigest($op,new JsonObject(['tenantId'=>$scope->tenantId->value,'sourceId'=>$command->sourceId->value,
            'ownerRef'=>$command->ownerRef,'origin'=>$command->origin]));
        $replay=$this->replay($scope,$context,$op,$digest); if ($replay!==null) { return $this->source($replay); }
        $context->assertExpected(new Decimal('0'),new Decimal('0'));
        if ($current!==null) { $this->fail('identity_exists'); }
        $source=new Source($scope->tenantId,$command->sourceId,SourceLifecycleState::Active,new Decimal('0'),new Decimal('0'),false,$command->ownerRef,$command->origin);
        $this->insertSource($scope->tenantId,$source);
        $row=$this->db->row('SELECT * FROM '.$this->table('sources').' WHERE tenant_id=? AND id=?',[$scope->tenantId->value,$command->sourceId->value]);
        $this->accept($scope,$context,$op,$digest,$row); return $source;
    }
    public function acceptSourceVersion(Scope $scope,SourceVersion $version,MutationContext $context): SourceVersion
    {
        $scope->assertTenant($version->ref->tenantId); $source=$this->getSource($scope->tenantId,$version->ref->sourceId);
        if ($source===null) { $this->fail('resource_missing'); }
        $op=Operation::SourceVersionAdd;
        $this->authorize($scope,new ResourceRef($scope->tenantId,'source',$source->sourceId),$op,[new JsonObject([
            'sourceId'=>$source->sourceId->value,'lifecycleEpoch'=>$source->lifecycleEpoch->value,'sourcePolicyEpoch'=>$source->sourcePolicyEpoch->value])]);
        if ($source->lifecycleState!==SourceLifecycleState::Active || $this->db->row('SELECT id FROM '.$this->table('tombstones').' WHERE tenant_id=? AND source_id=?',
            [$scope->tenantId->value,$source->sourceId->value])!==null) { $this->fail('source_revoked',Outcome::Denied); }
        $digest=$context->requestDigest($op,$version->ref->toWire()->with('mime',$version->mime)->with('rightsRef',$version->rightsRef)
            ->with('blob',new JsonObject(['storageId'=>$version->blob->storageId,'objectVersion'=>$version->blob->objectVersion,
                'ownerKind'=>$version->blob->ownerKind,'byteLength'=>$version->blob->byteLength->value])));
        $replay=$this->replay($scope,$context,$op,$digest);
        if ($replay!==null) {
            $r=$replay; $ref=new SourceVersionRef($scope->tenantId,new SourceId($r['sourceId']),new SourceVersionId($r['sourceVersionId']),new SourceDigest($r['sourceDigest']));
            return new SourceVersion($ref,new BlobRef($scope->tenantId,$ref->sourceId,$ref->sourceDigest,new Uint64($r['byteLength']),
                $r['storageId'],$r['objectVersion'],$r['ownerKind']),$r['mime'],$r['rightsRef'],new UtcInstant($r['createdAt']));
        }
        $r=$this->db->row('SELECT revision FROM '.$this->table('sources').' WHERE tenant_id=? AND id=?',[$scope->tenantId->value,$source->sourceId->value]);
        $context->assertExpected(new Decimal((string)$r['revision']),$source->lifecycleEpoch);
        $this->insertSourceVersion($scope->tenantId,$version,$digest);
        DomainFailure::requireWrite($this->db->execute('UPDATE '.$this->table('sources').' SET revision=revision+1 WHERE tenant_id=? AND id=? AND revision=? AND lifecycle_epoch=?',
            [$scope->tenantId->value,$source->sourceId->value,$r['revision'],$source->lifecycleEpoch->value])->rowCount()===1);
        $response=['sourceId'=>$version->ref->sourceId->value,'sourceVersionId'=>$version->ref->sourceVersionId->value,'sourceDigest'=>$version->ref->sourceDigest->value,
            'byteLength'=>$version->blob->byteLength->value,'storageId'=>$version->blob->storageId,'objectVersion'=>$version->blob->objectVersion,'ownerKind'=>$version->blob->ownerKind,
            'mime'=>$version->mime,'rightsRef'=>$version->rightsRef,'createdAt'=>$version->createdAt->value];
        $this->accept($scope,$context,$op,$digest,$response); return $version;
    }
    public function __debugInfo(): array { return ['adapter'=>'postgres-catalog']; }
}
