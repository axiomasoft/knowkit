-- Synthetic metadata only; never claims readiness of real parser/index/blob bytes.
CREATE FUNCTION fixture_id(n integer) RETURNS kk_ulid LANGUAGE sql IMMUTABLE AS $$
  SELECT ('01J'||lpad(n::text,23,'0'))::kk_ulid
$$;
DO $$ DECLARE t kk_ulid; d integer; BEGIN
  FOREACH d IN ARRAY ARRAY[0,1000] LOOP
    t=fixture_id(CASE WHEN d=0 THEN 1 ELSE 9 END);
    INSERT INTO tenants(id,name,serving_state) VALUES(t,'Synthetic','ready');
    INSERT INTO knowledge_bases(tenant_id,id,name,policy_ref,coverage_policy,default_profile)
      VALUES(t,fixture_id(d+100),'KB','owner:policy','{"allowPartial":false,"requiredFeatures":[]}','postgres-fts-simple-v1');
    INSERT INTO collections(tenant_id,id,kb_id,name,metadata) VALUES(t,fixture_id(d+200),fixture_id(d+100),'Collection','{}');
    INSERT INTO sources(tenant_id,id,owner_ref,origin,lifecycle_state) VALUES(t,fixture_id(d+2),'owner:fixture','host:upload','active');
    INSERT INTO source_memberships(tenant_id,id,source_id,kb_id,collection_id,metadata)
      VALUES(t,fixture_id(d+400),fixture_id(d+2),fixture_id(d+100),fixture_id(d+200),'{}');
    INSERT INTO resource_ledger(tenant_id,id,source_id,owner_kind,storage_id,storage_key,object_version,digest,byte_length,state)
      VALUES(t,fixture_id(d+300),fixture_id(d+2),'owned','test','original','v1',repeat('a',64),15,'accepted'),
            (t,fixture_id(d+301),fixture_id(d+2),'owned','test','ir','v1',repeat('b',64),15,'accepted'),
            (t,fixture_id(d+302),fixture_id(d+2),'owned','test','text','v1',repeat('c',64),15,'accepted'),
            (t,fixture_id(d+303),fixture_id(d+2),'owned','test','chunk','v1',repeat('d',64),15,'accepted');
    INSERT INTO source_versions VALUES(t,fixture_id(d+3),fixture_id(d+2),fixture_id(d+300),repeat('a',64),15,'text/plain','owner:rights',repeat('a',64),'2026-11-06T12:00:00Z');
    INSERT INTO source_version_state(tenant_id,id,usability) VALUES(t,fixture_id(d+3),'usable');
    INSERT INTO document_revisions VALUES(t,fixture_id(d+4),fixture_id(d+3),'test','1',repeat('a',64),'ir-1.0',repeat('b',64),fixture_id(d+301),'unknown',
      '{"state":"unknown","counts":{"processed":0,"empty":0,"failed":0,"skipped":0},"missingFeatures":[],"diagnostics":[]}','2026-11-06T12:00:00Z');
    INSERT INTO documents VALUES(t,fixture_id(d+5),fixture_id(d+4),'document');
    INSERT INTO segments VALUES(t,fixture_id(d+7),fixture_id(d+5),'segment-1',fixture_id(d+302),repeat('c',64),'ok',0);
    INSERT INTO pages(tenant_id,id,document_id,local_page_id,physical_no,text_resource_id,text_digest,status)
      VALUES(t,fixture_id(d+6),fixture_id(d+5),'page-1',1,fixture_id(d+302),repeat('c',64),'ok');
    INSERT INTO blocks VALUES(t,fixture_id(d+8),fixture_id(d+4),fixture_id(d+5),'block-1',NULL,'paragraph','source',
      '{"kind":"verbatim"}',0,'[]','{}');
    INSERT INTO assets VALUES(t,fixture_id(d+9),fixture_id(d+4),fixture_id(d+302),'image/png','[]','source',fixture_id(d+8));
    INSERT INTO chunk_sets VALUES(t,fixture_id(d+10),fixture_id(d+4),'{"name":"test","version":"1","profileDigest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}',repeat('e',64));
    INSERT INTO chunks VALUES(t,fixture_id(d+11),fixture_id(d+10),repeat('d',64),fixture_id(d+5),
      '[{"kind":"segment","segmentId":"segment-1","blockId":"block-1","byteStart":0,"byteEnd":4,"textDigest":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"}]',
      '{"kind":"verbatim"}',15,'[]',fixture_id(d+303));
    INSERT INTO index_generations(tenant_id,id,kb_id,profile_id,state,chunk_count)
      VALUES(t,fixture_id(d+14),fixture_id(d+100),'postgres-fts-simple-v1','building',1);
    INSERT INTO index_memberships VALUES(t,fixture_id(d+12),fixture_id(d+14),fixture_id(d+11),repeat('d',64),fixture_id(d+3),fixture_id(d+4),fixture_id(d+10));
    INSERT INTO lexical_documents VALUES(t,fixture_id(d+13),fixture_id(d+14),fixture_id(d+11),fixture_id(d+2),fixture_id(d+3),to_tsvector('simple','synthetic'),ARRAY['synthetic'],repeat('d',64));
    -- Direct SQL fixture for structural seal constraints, not a publication use-case receipt.
    UPDATE index_generations SET state='sealed',manifest_digest=repeat('f',64),manifest_json='{}' WHERE tenant_id=t AND id=fixture_id(d+14);
    INSERT INTO publication_snapshots VALUES(t,fixture_id(d+110),fixture_id(d+100),repeat('a',64),'2026-11-06T12:00:00Z',false);
    INSERT INTO snapshot_generations VALUES(t,fixture_id(d+110),fixture_id(d+100),fixture_id(d+14),'postgres-fts-simple-v1');
    INSERT INTO snapshot_memberships VALUES(t,fixture_id(d+111),fixture_id(d+110),fixture_id(d+100),fixture_id(d+2),fixture_id(d+3),fixture_id(d+4),fixture_id(d+10),fixture_id(d+14),'postgres-fts-simple-v1');
    UPDATE publication_snapshots SET sealed=true WHERE tenant_id=t AND id=fixture_id(d+110);
    UPDATE knowledge_bases SET active_snapshot_id=fixture_id(d+110) WHERE tenant_id=t AND id=fixture_id(d+100);
    INSERT INTO jobs(tenant_id,id,source_id,source_version_id,kb_id,type,stage,state,available_at,source_epoch,idempotency_key,request_fingerprint)
      VALUES(t,fixture_id(d+18),fixture_id(d+2),fixture_id(d+3),fixture_id(d+100),'extract','parse','pending','2026-11-06T12:00:00Z',0,'job-key',repeat('a',64));
    INSERT INTO job_checkpoints VALUES(t,fixture_id(d+19),fixture_id(d+18),'parse',repeat('a',64),repeat('b',64),'[]',0,'2026-11-06T12:00:00Z');
    INSERT INTO resource_writers VALUES(t,fixture_id(d+30),fixture_id(d+300),0,'closed',fixture_id(d+18),'2026-11-06T12:00:00Z','2026-11-06T12:00:01Z');
    INSERT INTO dependency_nodes VALUES(t,fixture_id(d+20),'source',fixture_id(d+2),fixture_id(d+3),repeat('a',64),repeat('b',64),NULL);
    INSERT INTO dependency_nodes VALUES(t,fixture_id(d+21),'chunk',fixture_id(d+2),fixture_id(d+3),repeat('c',64),repeat('d',64),NULL);
    INSERT INTO dependency_edges VALUES(t,fixture_id(d+21),fixture_id(d+20),'derived');
    INSERT INTO selections VALUES(t,fixture_id(d+15),repeat('a',64),repeat('a',64),'{}',repeat('b',64),NULL,repeat('c',64),fixture_id(d+110),fixture_id(d+14),'{}','postgres-fts-simple-v1',NULL,'[{}]','2026-11-06T12:05:00Z');
    INSERT INTO evidence_usages VALUES(t,fixture_id(d+16),fixture_id(d+116),fixture_id(d+100),fixture_id(d+101),'catalog.test',NULL,fixture_id(d+15),repeat('a',64),repeat('b',64),'2026-11-06T12:00:00Z',NULL,repeat('c',64),NULL);
    INSERT INTO usage_refs VALUES(t,fixture_id(d+31),fixture_id(d+16),fixture_id(d+20),'{}');
    INSERT INTO lifecycle_events VALUES(t,fixture_id(d+32),fixture_id(d+2),fixture_id(d+3),'source_version.corrected',1,repeat('b',64));
    INSERT INTO usage_invalidations VALUES(t,fixture_id(d+33),fixture_id(d+16),fixture_id(d+32),fixture_id(d+2),fixture_id(d+3));
    INSERT INTO citations VALUES(t,fixture_id(d+17),fixture_id(d+16),repeat('a',64),fixture_id(d+2),fixture_id(d+3),fixture_id(d+4),fixture_id(d+5),fixture_id(d+10),fixture_id(d+11),repeat('d',64),repeat('a',64),repeat('b',64),
      '[{"kind":"segment","segmentId":"segment-1","blockId":"block-1","byteStart":0,"byteEnd":4,"textDigest":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"}]','{}','present');
    INSERT INTO deletion_tasks VALUES(t,fixture_id(d+22),fixture_id(d+2),'pending',jsonb_build_array(jsonb_build_object('resourceId',fixture_id(d+300))),0,repeat('a',64),NULL);
    INSERT INTO deletion_attempts(tenant_id,id,task_id,resource_id,in_flight,result,observed_at) VALUES(t,fixture_id(d+23),fixture_id(d+22),fixture_id(d+300),false,'unknown','2026-11-06T12:00:00Z');
    INSERT INTO restore_receipts VALUES(t,fixture_id(d+24),0,false,0,repeat('a',64),false,'blocked');
    INSERT INTO inbox VALUES(t,fixture_id(d+26),'fixture-consumer',fixture_id(d+25),repeat('a',64),'2026-11-06T12:00:00Z');
    INSERT INTO idempotency_receipts VALUES(t,fixture_id(d+27),'fixture-key','catalog_create',repeat('a',64),'{}','2026-11-06T12:00:00Z');
  END LOOP;
END $$;
