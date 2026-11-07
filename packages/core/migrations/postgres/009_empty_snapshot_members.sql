-- A confirmed empty source is a real exact member even though it has no index row.
-- Preserve the existing nonempty path; the additional path requires a sealed,
-- catalog-bound manifest and a physically empty immutable chunkset.
CREATE OR REPLACE FUNCTION kk_snapshot_member() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM index_memberships
    WHERE tenant_id=NEW.tenant_id AND generation_id=NEW.generation_id
      AND source_version_id=NEW.source_version_id AND revision_id=NEW.revision_id
      AND chunk_set_id=NEW.chunk_set_id
  ) THEN
    RETURN NEW;
  END IF;

  IF EXISTS (
    SELECT 1 FROM index_generations g
    JOIN chunk_sets cs ON cs.tenant_id=g.tenant_id AND cs.id=NEW.chunk_set_id
    JOIN document_revisions r ON r.tenant_id=cs.tenant_id AND r.id=cs.revision_id
    JOIN source_versions v ON v.tenant_id=r.tenant_id AND v.id=r.source_version_id
    CROSS JOIN LATERAL jsonb_array_elements(g.manifest_json->'members') member
    WHERE g.tenant_id=NEW.tenant_id AND g.kb_id=NEW.kb_id AND g.id=NEW.generation_id
      AND g.profile_id=NEW.profile_id AND g.state IN ('sealed','active','retired')
      AND v.source_id=NEW.source_id AND v.id=NEW.source_version_id AND r.id=NEW.revision_id
      AND member->>'sourceId'=v.source_id::text
      AND member->>'sourceVersionId'=v.id::text
      AND member->>'sourceDigest'=v.source_digest::text
      AND member->>'documentRevisionId'=r.id::text
      AND member->>'irDigest'=r.ir_digest::text
      AND member->>'chunkSetId'=cs.id::text
      AND member->>'chunkSetDigest'=cs.manifest_digest::text
      AND member->>'chunkCount'='0'
      AND NOT EXISTS (SELECT 1 FROM chunks c WHERE c.tenant_id=cs.tenant_id AND c.chunk_set_id=cs.id)
  ) THEN
    RETURN NEW;
  END IF;

  RAISE EXCEPTION 'snapshot exact member mismatch' USING ERRCODE='23514';
END $$;
