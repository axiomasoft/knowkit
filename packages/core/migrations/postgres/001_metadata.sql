-- KnowKit core baseline, PostgreSQL 16+. Apply explicitly through Catalog\Migrate.
-- No extension, provider, vector or host application installation side effects.
-- AgenTools immutable-trigger pattern adapted (2054acda51eb69eca1579c78900858c5ff3de01e),
-- (c) 2026 Dmitry Vostrikov, private local reuse; see P2.1-reuse.json.
CREATE DOMAIN kk_ulid AS char(26) COLLATE "C" CHECK (VALUE ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$');
CREATE DOMAIN kk_digest AS char(64) COLLATE "C" CHECK (VALUE ~ '^[0-9a-f]{64}$');
CREATE DOMAIN kk_counter AS bigint CHECK (VALUE >= 0);
CREATE FUNCTION kk_metadata(value jsonb) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE k text; v jsonb;
BEGIN
  IF jsonb_typeof(value) <> 'object' OR octet_length(value::text)>32768 THEN RETURN false; END IF;
  IF (SELECT count(*) FROM jsonb_object_keys(value)) > 32 THEN RETURN false; END IF;
  FOR k,v IN SELECT * FROM jsonb_each(value) LOOP
    IF length(k)>128 OR k !~ '^[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){1,3}$'
       OR jsonb_typeof(v) NOT IN ('string','number','boolean')
       OR (jsonb_typeof(v)='string' AND length(v#>>'{}')>256)
       OR (jsonb_typeof(v)='number' AND v::text !~ '^-?[0-9]+$') THEN RETURN false; END IF;
  END LOOP;
  RETURN true;
END $$;
CREATE TABLE tenants (
  id kk_ulid PRIMARY KEY, name varchar(128) NOT NULL,
  tenant_policy_epoch kk_counter NOT NULL DEFAULT 0,
  serving_state text NOT NULL DEFAULT 'blocked' CHECK (serving_state IN ('blocked','ready')),
  restore_receipt_id kk_ulid
);
CREATE TABLE knowledge_bases (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL,
  name varchar(128) NOT NULL CHECK (length(btrim(name))>0), revision kk_counter NOT NULL DEFAULT 0,
  policy_ref varchar(256) NOT NULL, removed_at timestamptz, active_snapshot_id kk_ulid, pointer_version kk_counter NOT NULL DEFAULT 0,
  kb_policy_epoch kk_counter NOT NULL DEFAULT 0, coverage_policy jsonb NOT NULL,
  default_profile varchar(64) NOT NULL CHECK (default_profile ~ '^[a-z][a-z0-9-]{1,63}$'),
  PRIMARY KEY (tenant_id,id), CHECK (jsonb_typeof(coverage_policy)='object' AND octet_length(coverage_policy::text)<=32768)
);
CREATE TABLE collections (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL,
  kb_id kk_ulid NOT NULL, parent_id kk_ulid, name varchar(128) NOT NULL,
  revision kk_counter NOT NULL DEFAULT 0, metadata jsonb NOT NULL CHECK (kk_metadata(metadata)),
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,kb_id,id),
  FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,kb_id,parent_id) REFERENCES collections(tenant_id,kb_id,id) ON DELETE RESTRICT, CHECK (id<>parent_id)
);
CREATE TABLE collection_closure (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, kb_id kk_ulid NOT NULL,
  ancestor_id kk_ulid NOT NULL, descendant_id kk_ulid NOT NULL, depth integer NOT NULL CHECK (depth>=0),
  PRIMARY KEY (tenant_id,kb_id,ancestor_id,descendant_id),
  FOREIGN KEY (tenant_id,kb_id,ancestor_id) REFERENCES collections(tenant_id,kb_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,kb_id,descendant_id) REFERENCES collections(tenant_id,kb_id,id) ON DELETE RESTRICT,
  CHECK ((ancestor_id=descendant_id)=(depth=0))
);
CREATE TABLE sources (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL,
  owner_ref varchar(256) NOT NULL CHECK (length(btrim(owner_ref))>0), origin varchar(256) NOT NULL CHECK (length(btrim(origin))>0),
  lifecycle_state text NOT NULL CHECK (lifecycle_state IN ('active','revoked','deletion_requested')),
  lifecycle_epoch kk_counter NOT NULL DEFAULT 0, source_policy_epoch kk_counter NOT NULL DEFAULT 0,
  retention_hold boolean NOT NULL DEFAULT false, revision kk_counter NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id,id)
);
CREATE TABLE source_memberships (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_id kk_ulid NOT NULL,
  kb_id kk_ulid NOT NULL, collection_id kk_ulid, revision kk_counter NOT NULL DEFAULT 0,
  metadata jsonb NOT NULL CHECK (kk_metadata(metadata)), PRIMARY KEY (tenant_id,id),
  UNIQUE NULLS NOT DISTINCT (tenant_id,source_id,kb_id,collection_id),
  FOREIGN KEY (tenant_id,source_id) REFERENCES sources(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,kb_id,collection_id) REFERENCES collections(tenant_id,kb_id,id) ON DELETE RESTRICT
);
CREATE INDEX source_memberships_lookup ON source_memberships(tenant_id,kb_id,collection_id,source_id);
CREATE TABLE resource_ledger (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_id kk_ulid NOT NULL,
  owner_kind text NOT NULL CHECK (owner_kind IN ('owned','external')), storage_id varchar(128) NOT NULL,
  storage_key varchar(1024), object_version varchar(256), staging_id kk_ulid,
  digest kk_digest, byte_length kk_counter, state text NOT NULL CHECK (state IN ('reserved','uploaded','accepted','deleting','deleted','unknown')),
  ref_count kk_counter NOT NULL DEFAULT 0, inventory_epoch kk_counter NOT NULL DEFAULT 0, retention_hold boolean NOT NULL DEFAULT false,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,source_id,id),
  FOREIGN KEY (tenant_id,source_id) REFERENCES sources(tenant_id,id) ON DELETE RESTRICT,
  CHECK ((digest IS NULL)=(byte_length IS NULL)),
  CHECK ((state IN ('uploaded','accepted','deleting') AND digest IS NOT NULL) OR
         (state='reserved' AND (digest IS NOT NULL OR staging_id IS NOT NULL)) OR
         (state='deleted' AND (digest IS NOT NULL OR staging_id IS NOT NULL)) OR state='unknown')
);
CREATE UNIQUE INDEX resource_staging_identity ON resource_ledger(tenant_id,source_id,staging_id) WHERE staging_id IS NOT NULL;
CREATE INDEX resource_inventory ON resource_ledger(tenant_id,source_id,state,id);
CREATE TABLE source_versions (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_id kk_ulid NOT NULL,
  blob_resource_id kk_ulid NOT NULL, source_digest kk_digest NOT NULL, byte_length kk_counter NOT NULL,
  media_type varchar(128) NOT NULL, rights_ref varchar(256) NOT NULL,
  idempotency_fingerprint kk_digest NOT NULL, created_at timestamptz NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,source_id,id), UNIQUE (tenant_id,id,source_digest),
  FOREIGN KEY (tenant_id,source_id) REFERENCES sources(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id,blob_resource_id) REFERENCES resource_ledger(tenant_id,source_id,id) ON DELETE RESTRICT
);
CREATE TABLE source_version_state (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, usability text NOT NULL CHECK (usability IN ('usable','corrected','revoked')),
  corrected_by_version_id kk_ulid, event_id kk_ulid, PRIMARY KEY (tenant_id,id),
  FOREIGN KEY (tenant_id,id) REFERENCES source_versions(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,corrected_by_version_id) REFERENCES source_versions(tenant_id,id) ON DELETE RESTRICT, CHECK (id<>corrected_by_version_id),
  CHECK (usability='corrected' OR corrected_by_version_id IS NULL)
);
CREATE TABLE tombstones (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_id kk_ulid NOT NULL,
  reason text NOT NULL CHECK (reason IN ('source_revoked','source_deleted','consent_revoked')), event_id kk_ulid NOT NULL,
  lifecycle_epoch kk_counter NOT NULL, applies_to text NOT NULL CHECK (applies_to='future_versions'),
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,source_id)
  -- Deliberately NO source FK: an unseen source identity can be tombstoned.
);
CREATE TABLE idempotency_receipts (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, key varchar(128) NOT NULL,
  operation varchar(64) NOT NULL, request_fingerprint kk_digest NOT NULL,
  response_blob jsonb NOT NULL CHECK (octet_length(response_blob::text)<=65536), accepted_at timestamptz NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,key)
);
CREATE TABLE document_revisions (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_version_id kk_ulid NOT NULL,
  parser_name varchar(128) NOT NULL, parser_version varchar(64) NOT NULL, profile_digest kk_digest NOT NULL,
  ir_schema varchar(128) NOT NULL, ir_digest kk_digest NOT NULL, ir_resource_id kk_ulid NOT NULL,
  coverage_state text NOT NULL CHECK (coverage_state IN ('complete','partial','unknown')), coverage_json jsonb NOT NULL,
  created_at timestamptz NOT NULL, PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,source_version_id,id),
  UNIQUE (tenant_id,id,ir_digest), FOREIGN KEY (tenant_id,source_version_id) REFERENCES source_versions(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,ir_resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT,
  CHECK (jsonb_typeof(coverage_json)='object' AND octet_length(coverage_json::text)<=262144)
);
CREATE TABLE documents (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, revision_id kk_ulid NOT NULL,
  logical_path varchar(512) NOT NULL, PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,revision_id,id),
  UNIQUE (tenant_id,revision_id,logical_path), FOREIGN KEY (tenant_id,revision_id) REFERENCES document_revisions(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE segments (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, document_id kk_ulid NOT NULL,
  local_segment_id varchar(128) NOT NULL, text_resource_id kk_ulid NOT NULL, text_digest kk_digest NOT NULL,
  status text NOT NULL CHECK (status IN ('ok','empty','failed','skipped')), logical_order integer NOT NULL CHECK (logical_order>=0),
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,document_id,local_segment_id), UNIQUE (tenant_id,document_id,id),
  FOREIGN KEY (tenant_id,document_id) REFERENCES documents(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,text_resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE pages (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, document_id kk_ulid NOT NULL,
  local_page_id varchar(128) NOT NULL, physical_no integer NOT NULL CHECK (physical_no>=1), printed_label varchar(128),
  text_resource_id kk_ulid NOT NULL, text_digest kk_digest NOT NULL, status text NOT NULL CHECK (status IN ('ok','empty','failed','skipped')),
  width_millionths integer CHECK (width_millionths>0), height_millionths integer CHECK (height_millionths>0),
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,document_id,local_page_id), UNIQUE (tenant_id,document_id,physical_no),
  UNIQUE (tenant_id,document_id,id), FOREIGN KEY (tenant_id,document_id) REFERENCES documents(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,text_resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE blocks (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, revision_id kk_ulid NOT NULL, document_id kk_ulid NOT NULL,
  local_block_id varchar(128) NOT NULL, parent_block_id kk_ulid, kind varchar(64) NOT NULL,
  origin text NOT NULL CHECK (origin IN ('source','ocr','generated')), provenance_json jsonb NOT NULL,
  reading_order integer NOT NULL CHECK (reading_order>=0), spans_json jsonb NOT NULL, structure_json jsonb NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,revision_id,document_id,id), UNIQUE (tenant_id,revision_id,document_id,local_block_id),
  FOREIGN KEY (tenant_id,revision_id,document_id) REFERENCES documents(tenant_id,revision_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,revision_id,document_id,parent_block_id) REFERENCES blocks(tenant_id,revision_id,document_id,id) ON DELETE RESTRICT,
  CHECK (id<>parent_block_id), CHECK (jsonb_typeof(spans_json)='array' AND octet_length(spans_json::text)<=1048576)
);
CREATE TABLE assets (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, revision_id kk_ulid NOT NULL,
  resource_id kk_ulid NOT NULL, media_type varchar(128) NOT NULL, source_locators_json jsonb NOT NULL,
  origin text NOT NULL CHECK (origin IN ('source','ocr','generated')), parent_block_id kk_ulid,
  PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,revision_id) REFERENCES document_revisions(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,parent_block_id) REFERENCES blocks(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE chunk_sets (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, revision_id kk_ulid NOT NULL,
  chunker_signature jsonb NOT NULL, manifest_digest kk_digest NOT NULL, PRIMARY KEY (tenant_id,id),
  UNIQUE (tenant_id,revision_id,id), FOREIGN KEY (tenant_id,revision_id) REFERENCES document_revisions(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE chunks (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, chunk_set_id kk_ulid NOT NULL,
  content_digest kk_digest NOT NULL, document_id kk_ulid NOT NULL, spans_json jsonb NOT NULL, provenance_json jsonb NOT NULL,
  byte_length kk_counter NOT NULL, structure_path jsonb NOT NULL, canonical_payload_ref kk_ulid NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,chunk_set_id,id,content_digest),
  FOREIGN KEY (tenant_id,chunk_set_id) REFERENCES chunk_sets(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,document_id) REFERENCES documents(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,canonical_payload_ref) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE index_generations (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, kb_id kk_ulid NOT NULL,
  profile_id varchar(64) NOT NULL, state text NOT NULL CHECK (state IN ('building','sealed','active','retired','failed')),
  manifest_digest kk_digest, manifest_json jsonb, embedding_space_digest kk_digest, chunk_count kk_counter NOT NULL DEFAULT 0,
  revision kk_counter NOT NULL DEFAULT 0, PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,kb_id,id,profile_id),
  FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT,
  CHECK (state IN ('building','failed') OR (manifest_digest IS NOT NULL AND manifest_json IS NOT NULL)),
  CHECK (manifest_json IS NULL OR octet_length(manifest_json::text)<=4194304)
);
CREATE TABLE index_memberships (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, generation_id kk_ulid NOT NULL,
  chunk_id kk_ulid NOT NULL, chunk_digest kk_digest NOT NULL, source_version_id kk_ulid NOT NULL, revision_id kk_ulid NOT NULL, chunk_set_id kk_ulid NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,generation_id,chunk_id),
  FOREIGN KEY (tenant_id,generation_id) REFERENCES index_generations(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,chunk_set_id,chunk_id,chunk_digest) REFERENCES chunks(tenant_id,chunk_set_id,id,content_digest) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_version_id,revision_id) REFERENCES document_revisions(tenant_id,source_version_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,revision_id,chunk_set_id) REFERENCES chunk_sets(tenant_id,revision_id,id) ON DELETE RESTRICT
);
CREATE TABLE lexical_documents (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, generation_id kk_ulid NOT NULL,
  chunk_id kk_ulid NOT NULL, source_id kk_ulid NOT NULL, source_version_id kk_ulid NOT NULL,
  simple_tsvector tsvector NOT NULL, exact_tokens text[] NOT NULL, text_digest kk_digest NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,generation_id,chunk_id),
  FOREIGN KEY (tenant_id,generation_id,chunk_id) REFERENCES index_memberships(tenant_id,generation_id,chunk_id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id,source_version_id) REFERENCES source_versions(tenant_id,source_id,id) ON DELETE RESTRICT
);
CREATE INDEX lexical_scope ON lexical_documents(tenant_id,generation_id,source_version_id,chunk_id);
CREATE INDEX lexical_full_text ON lexical_documents USING gin(simple_tsvector);
CREATE INDEX lexical_exact_tokens ON lexical_documents USING gin(exact_tokens);
CREATE TABLE publication_snapshots (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, kb_id kk_ulid NOT NULL,
  manifest_digest kk_digest NOT NULL, created_at timestamptz NOT NULL, sealed boolean NOT NULL DEFAULT false,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,kb_id,id), FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT
);
ALTER TABLE knowledge_bases ADD FOREIGN KEY (tenant_id,id,active_snapshot_id) REFERENCES publication_snapshots(tenant_id,kb_id,id) ON DELETE RESTRICT;
CREATE TABLE snapshot_memberships (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, snapshot_id kk_ulid NOT NULL, kb_id kk_ulid NOT NULL,
  source_id kk_ulid NOT NULL, source_version_id kk_ulid NOT NULL, revision_id kk_ulid NOT NULL,
  chunk_set_id kk_ulid NOT NULL, generation_id kk_ulid NOT NULL, profile_id varchar(64) NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,snapshot_id,source_version_id,revision_id,chunk_set_id,generation_id,profile_id),
  FOREIGN KEY (tenant_id,kb_id,snapshot_id) REFERENCES publication_snapshots(tenant_id,kb_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id,source_version_id) REFERENCES source_versions(tenant_id,source_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_version_id,revision_id) REFERENCES document_revisions(tenant_id,source_version_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,revision_id,chunk_set_id) REFERENCES chunk_sets(tenant_id,revision_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,kb_id,generation_id,profile_id) REFERENCES index_generations(tenant_id,kb_id,id,profile_id) ON DELETE RESTRICT
);
CREATE INDEX snapshot_profiles ON snapshot_memberships(tenant_id,snapshot_id,profile_id,generation_id);
CREATE TABLE snapshot_generations (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, snapshot_id kk_ulid NOT NULL, kb_id kk_ulid NOT NULL,
  generation_id kk_ulid NOT NULL, profile_id varchar(64) NOT NULL,
  PRIMARY KEY (tenant_id,snapshot_id,generation_id),
  FOREIGN KEY (tenant_id,kb_id,snapshot_id) REFERENCES publication_snapshots(tenant_id,kb_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,kb_id,generation_id,profile_id) REFERENCES index_generations(tenant_id,kb_id,id,profile_id) ON DELETE RESTRICT
);
CREATE TABLE jobs (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_id kk_ulid NOT NULL,
  source_version_id kk_ulid NOT NULL, kb_id kk_ulid NOT NULL, generation_id kk_ulid,
  type varchar(64) NOT NULL CHECK (type ~ '^[a-z][a-z0-9_]{0,63}$'), stage varchar(64) NOT NULL,
  state text NOT NULL CHECK (state IN ('pending','leased','succeeded','failed','cancelled')),
  attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 5), available_at timestamptz NOT NULL,
  lease_owner varchar(128), lease_token_hash kk_digest, lease_until timestamptz, attempt_deadline timestamptz,
  fence kk_counter NOT NULL DEFAULT 0, source_epoch kk_counter NOT NULL, cancel_requested boolean NOT NULL DEFAULT false,
  last_error_code varchar(64), checkpoint_digest kk_digest, idempotency_key varchar(128) NOT NULL,
  request_fingerprint kk_digest NOT NULL, PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,idempotency_key),
  FOREIGN KEY (tenant_id,source_id,source_version_id) REFERENCES source_versions(tenant_id,source_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,generation_id) REFERENCES index_generations(tenant_id,id) ON DELETE RESTRICT,
  CHECK (state<>'leased' OR (attempt_count>=1 AND lease_owner IS NOT NULL AND lease_token_hash IS NOT NULL AND
         lease_until IS NOT NULL AND attempt_deadline IS NOT NULL AND lease_until<=attempt_deadline))
);
CREATE INDEX jobs_available ON jobs(tenant_id,type,available_at,id) WHERE state='pending' AND NOT cancel_requested;
CREATE INDEX jobs_expired ON jobs(tenant_id,lease_until,id) WHERE state='leased';
CREATE TABLE job_checkpoints (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, job_id kk_ulid NOT NULL, stage varchar(64) NOT NULL,
  input_digest kk_digest NOT NULL, output_digest kk_digest NOT NULL, resource_refs_json jsonb NOT NULL, fence kk_counter NOT NULL,
  created_at timestamptz NOT NULL, PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,job_id,stage,input_digest),
  FOREIGN KEY (tenant_id,job_id) REFERENCES jobs(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE resource_writers (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, resource_id kk_ulid NOT NULL, fence kk_counter NOT NULL,
  state text NOT NULL CHECK (state IN ('active','closed','uncertain')), job_id kk_ulid, started_at timestamptz NOT NULL, closed_at timestamptz,
  PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,job_id) REFERENCES jobs(tenant_id,id) ON DELETE RESTRICT, CHECK ((state='closed')=(closed_at IS NOT NULL))
);
CREATE INDEX resource_open_writers ON resource_writers(tenant_id,resource_id) WHERE state<>'closed';
CREATE TABLE dependency_nodes (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, kind varchar(64) NOT NULL,
  source_id kk_ulid NOT NULL, source_version_id kk_ulid, identity_digest kk_digest NOT NULL,
  parent_set_digest kk_digest NOT NULL, invalidated_epoch kk_counter, PRIMARY KEY (tenant_id,id),
  FOREIGN KEY (tenant_id,source_id) REFERENCES sources(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id,source_version_id) REFERENCES source_versions(tenant_id,source_id,id) ON DELETE RESTRICT
);
CREATE TABLE dependency_edges (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, child_id kk_ulid NOT NULL, parent_id kk_ulid NOT NULL, relation varchar(64) NOT NULL,
  PRIMARY KEY (tenant_id,child_id,parent_id,relation), CHECK (child_id<>parent_id),
  FOREIGN KEY (tenant_id,child_id) REFERENCES dependency_nodes(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,parent_id) REFERENCES dependency_nodes(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX dependency_reverse ON dependency_edges(tenant_id,parent_id,child_id);
CREATE TABLE selections (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, token_hash kk_digest NOT NULL,
  host_scope_hash kk_digest NOT NULL, scope_binding_json jsonb NOT NULL, scope_binding_digest kk_digest NOT NULL,
  run_ref varchar(256), query_hash kk_digest NOT NULL, snapshot_id kk_ulid NOT NULL, generation_id kk_ulid NOT NULL,
  policy_binding_json jsonb NOT NULL, profile_id varchar(64) NOT NULL, space_digest kk_digest,
  candidate_refs_json jsonb NOT NULL, expires_at timestamptz NOT NULL, PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,token_hash),
  UNIQUE (tenant_id,id,host_scope_hash), FOREIGN KEY (tenant_id,snapshot_id) REFERENCES publication_snapshots(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,generation_id) REFERENCES index_generations(tenant_id,id) ON DELETE RESTRICT,
  CHECK (jsonb_typeof(candidate_refs_json)='array' AND jsonb_array_length(candidate_refs_json) BETWEEN 1 AND 256)
);
CREATE INDEX selections_expiry ON selections(tenant_id,expires_at,id);
CREATE TABLE evidence_usages (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, disclosure_attempt_id kk_ulid NOT NULL,
  kb_id kk_ulid NOT NULL, actor_id kk_ulid NOT NULL, purpose varchar(128) NOT NULL, run_ref varchar(256), selection_id kk_ulid NOT NULL,
  host_scope_hash kk_digest NOT NULL, policy_binding_digest kk_digest NOT NULL, prepared_at timestamptz NOT NULL,
  provider_accepted_at timestamptz, evidence_digest kk_digest NOT NULL, payload_resource_id kk_ulid,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,disclosure_attempt_id), UNIQUE (tenant_id,id,host_scope_hash),
  FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,selection_id,host_scope_hash) REFERENCES selections(tenant_id,id,host_scope_hash) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,payload_resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT,
  CHECK (provider_accepted_at IS NULL OR provider_accepted_at>=prepared_at)
);
CREATE TABLE usage_refs (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, usage_id kk_ulid NOT NULL, node_id kk_ulid NOT NULL,
  exact_version_ref jsonb NOT NULL, PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,usage_id,node_id),
  FOREIGN KEY (tenant_id,usage_id) REFERENCES evidence_usages(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,node_id) REFERENCES dependency_nodes(tenant_id,id) ON DELETE RESTRICT
);
CREATE TABLE lifecycle_events (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_id kk_ulid NOT NULL, source_version_id kk_ulid,
  type varchar(64) NOT NULL, lifecycle_epoch kk_counter NOT NULL, payload_digest kk_digest NOT NULL, PRIMARY KEY (tenant_id,id),
  FOREIGN KEY (tenant_id,source_id) REFERENCES sources(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id,source_version_id) REFERENCES source_versions(tenant_id,source_id,id) ON DELETE RESTRICT
);
ALTER TABLE source_version_state ADD FOREIGN KEY (tenant_id,event_id) REFERENCES lifecycle_events(tenant_id,id) ON DELETE RESTRICT;
CREATE TABLE usage_invalidations (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, usage_id kk_ulid NOT NULL,
  event_id kk_ulid NOT NULL, source_id kk_ulid NOT NULL, source_version_id kk_ulid, PRIMARY KEY (tenant_id,id),
  UNIQUE (tenant_id,usage_id,event_id), FOREIGN KEY (tenant_id,usage_id) REFERENCES evidence_usages(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,event_id) REFERENCES lifecycle_events(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id) REFERENCES sources(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id,source_version_id) REFERENCES source_versions(tenant_id,source_id,id) ON DELETE RESTRICT
);
CREATE TABLE citations (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, usage_id kk_ulid NOT NULL, host_scope_hash kk_digest NOT NULL,
  source_id kk_ulid NOT NULL, source_version_id kk_ulid NOT NULL, revision_id kk_ulid NOT NULL, document_id kk_ulid NOT NULL,
  chunk_set_id kk_ulid NOT NULL, chunk_id kk_ulid NOT NULL, content_digest kk_digest NOT NULL, source_digest kk_digest NOT NULL, ir_digest kk_digest NOT NULL,
  locator_json jsonb NOT NULL, digests_json jsonb NOT NULL, payload_state text NOT NULL CHECK (payload_state IN ('present','removed')),
  PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,usage_id,host_scope_hash) REFERENCES evidence_usages(tenant_id,id,host_scope_hash) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id,source_version_id) REFERENCES source_versions(tenant_id,source_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_version_id,source_digest) REFERENCES source_versions(tenant_id,id,source_digest) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_version_id,revision_id) REFERENCES document_revisions(tenant_id,source_version_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,revision_id,ir_digest) REFERENCES document_revisions(tenant_id,id,ir_digest) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,revision_id,document_id) REFERENCES documents(tenant_id,revision_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,revision_id,chunk_set_id) REFERENCES chunk_sets(tenant_id,revision_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,chunk_set_id,chunk_id,content_digest) REFERENCES chunks(tenant_id,chunk_set_id,id,content_digest) ON DELETE RESTRICT
);
CREATE TABLE deletion_tasks (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_id kk_ulid NOT NULL,
  status text NOT NULL CHECK (status IN ('pending','done','partial','unknown','unsupported')),
  required_resources_json jsonb NOT NULL, inventory_epoch kk_counter NOT NULL, inventory_digest kk_digest NOT NULL, hold_reason varchar(128),
  PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,source_id) REFERENCES sources(tenant_id,id) ON DELETE RESTRICT,
  CHECK (jsonb_typeof(required_resources_json)='array' AND octet_length(required_resources_json::text)<=4194304)
);
CREATE TABLE deletion_attempts (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, task_id kk_ulid NOT NULL, resource_id kk_ulid NOT NULL,
  in_flight boolean NOT NULL, result text CHECK (result IN ('deleted','absent','unknown','unsupported')),
  provider_receipt_json jsonb, known_expiry timestamptz, observed_at timestamptz,
  storage_key varchar(1024), object_version varchar(256), staging_id kk_ulid,
  PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,task_id) REFERENCES deletion_tasks(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT,
  CHECK (NOT in_flight OR result IS NULL), CHECK (result IS NULL OR observed_at IS NOT NULL)
);
CREATE INDEX deletion_resource_receipts ON deletion_attempts(tenant_id,resource_id,result);
CREATE TABLE outbox (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, event_json jsonb NOT NULL,
  payload_digest kk_digest NOT NULL, aggregate_version kk_counter NOT NULL,
  delivery_state text NOT NULL DEFAULT 'pending' CHECK (delivery_state IN ('pending','leased','delivered','quarantined')),
  delivery_fence kk_counter NOT NULL DEFAULT 0, lease_until timestamptz, attempts kk_counter NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id,id), CHECK (octet_length(event_json::text)<=65536),
  CHECK (event_json->>'eventId'=id::text AND event_json->>'tenantId'=tenant_id::text AND
         event_json->>'payloadDigest'=payload_digest::text AND event_json->>'aggregateVersion'=aggregate_version::text)
);
CREATE INDEX outbox_pending ON outbox(tenant_id,delivery_state,id) WHERE delivery_state IN ('pending','leased');
CREATE TABLE inbox (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, consumer_id varchar(128) NOT NULL,
  event_id kk_ulid NOT NULL, payload_digest kk_digest NOT NULL, applied_at timestamptz NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,consumer_id,event_id)
  -- event_id identifies an external event; it is not an FK into the local outbox.
);
CREATE TABLE restore_receipts (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL, source_cutoff kk_counter NOT NULL,
  proven_through_cutoff boolean NOT NULL, last_replayed_recovery_seq kk_counter NOT NULL, tombstone_digest kk_digest NOT NULL,
  inventory_verified boolean NOT NULL, serving_state text NOT NULL CHECK (serving_state IN ('blocked','ready')),
  PRIMARY KEY (tenant_id,id), CHECK (serving_state<>'ready' OR (proven_through_cutoff AND inventory_verified))
);
ALTER TABLE tenants ADD FOREIGN KEY (id,restore_receipt_id) REFERENCES restore_receipts(tenant_id,id) ON DELETE RESTRICT;

CREATE FUNCTION kk_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'immutable metadata identity' USING ERRCODE='23514'; END $$;
DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['source_versions','document_revisions','documents','segments','pages','blocks','assets',
    'chunk_sets','chunks','tombstones','idempotency_receipts','job_checkpoints','dependency_edges','usage_refs',
    'usage_invalidations','lifecycle_events','selections','inbox','restore_receipts'] LOOP
    EXECUTE format('CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION kk_immutable()',t);
  END LOOP;
END $$;
CREATE FUNCTION kk_source_barrier() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  IF EXISTS (SELECT 1 FROM tombstones WHERE tenant_id=NEW.tenant_id AND source_id=NEW.id) THEN
    RAISE EXCEPTION 'source tombstoned' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER source_barrier BEFORE INSERT ON sources FOR EACH ROW EXECUTE FUNCTION kk_source_barrier();
CREATE FUNCTION kk_source_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  IF EXISTS (SELECT 1 FROM tombstones WHERE tenant_id=NEW.tenant_id AND source_id=NEW.source_id)
    OR NOT EXISTS (SELECT 1 FROM sources WHERE tenant_id=NEW.tenant_id AND id=NEW.source_id AND lifecycle_state='active') THEN
    RAISE EXCEPTION 'source unavailable' USING ERRCODE='23514';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM resource_ledger WHERE tenant_id=NEW.tenant_id AND id=NEW.blob_resource_id AND
     source_id=NEW.source_id AND digest=NEW.source_digest AND byte_length=NEW.byte_length AND state='accepted') THEN
    RAISE EXCEPTION 'original blob mismatch' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER source_version_guard BEFORE INSERT ON source_versions FOR EACH ROW EXECUTE FUNCTION kk_source_version_guard();
CREATE FUNCTION kk_collection_tree() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND (OLD.tenant_id,OLD.kb_id,OLD.id) IS DISTINCT FROM (NEW.tenant_id,NEW.kb_id,NEW.id) THEN
    RAISE EXCEPTION 'collection identity immutable' USING ERRCODE='23514';
  END IF;
  PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  PERFORM 1 FROM knowledge_bases WHERE tenant_id=NEW.tenant_id AND id=NEW.kb_id FOR UPDATE;
  IF EXISTS (WITH RECURSIVE chain(id,parent_id,path,cycle) AS (
    SELECT id,parent_id,ARRAY[id::text],false FROM collections WHERE tenant_id=NEW.tenant_id AND kb_id=NEW.kb_id AND id=NEW.parent_id
    UNION ALL SELECT c.id,c.parent_id,ch.path||c.id::text,c.id::text=ANY(ch.path) FROM collections c JOIN chain ch ON c.id=ch.parent_id
      WHERE c.tenant_id=NEW.tenant_id AND c.kb_id=NEW.kb_id AND NOT ch.cycle
  ) SELECT 1 FROM chain WHERE id=NEW.id OR cycle) OR NEW.parent_id=NEW.id THEN
    RAISE EXCEPTION 'collection cycle' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER collection_tree BEFORE INSERT OR UPDATE ON collections FOR EACH ROW EXECUTE FUNCTION kk_collection_tree();
CREATE FUNCTION kk_collection_closure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  DELETE FROM collection_closure WHERE tenant_id=NEW.tenant_id AND kb_id=NEW.kb_id;
  INSERT INTO collection_closure(tenant_id,kb_id,ancestor_id,descendant_id,depth)
    WITH RECURSIVE paths(ancestor,descendant,depth) AS (
      SELECT id,id,0 FROM collections WHERE tenant_id=NEW.tenant_id AND kb_id=NEW.kb_id
      UNION ALL SELECT p.ancestor,c.id,p.depth+1 FROM paths p JOIN collections c ON c.parent_id=p.descendant
        WHERE c.tenant_id=NEW.tenant_id AND c.kb_id=NEW.kb_id
    ) SELECT NEW.tenant_id,NEW.kb_id,ancestor,descendant,depth FROM paths;
  RETURN NEW;
END $$;
CREATE TRIGGER collection_closure_refresh AFTER INSERT OR UPDATE OF parent_id ON collections FOR EACH ROW EXECUTE FUNCTION kk_collection_closure();
CREATE FUNCTION kk_membership_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  IF EXISTS (SELECT 1 FROM tombstones WHERE tenant_id=NEW.tenant_id AND source_id=NEW.source_id) OR
     NOT EXISTS (SELECT 1 FROM sources WHERE tenant_id=NEW.tenant_id AND id=NEW.source_id AND lifecycle_state='active') THEN
    RAISE EXCEPTION 'source unavailable' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER membership_guard BEFORE INSERT OR UPDATE ON source_memberships FOR EACH ROW EXECUTE FUNCTION kk_membership_guard();
CREATE FUNCTION kk_dependency_cycle() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
  IF EXISTS (WITH RECURSIVE parents(id) AS (
    SELECT NEW.parent_id UNION SELECT e.parent_id FROM dependency_edges e JOIN parents p ON e.child_id=p.id WHERE e.tenant_id=NEW.tenant_id
  ) SELECT 1 FROM parents WHERE id=NEW.child_id) THEN RAISE EXCEPTION 'dependency cycle' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER dependency_cycle BEFORE INSERT ON dependency_edges FOR EACH ROW EXECUTE FUNCTION kk_dependency_cycle();
CREATE FUNCTION kk_sealed_membership() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t kk_ulid; g kk_ulid; s text;
BEGIN
  t=CASE WHEN TG_OP='DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
  IF TG_TABLE_NAME='index_memberships' OR TG_TABLE_NAME='lexical_documents' THEN
    g=CASE WHEN TG_OP='DELETE' THEN OLD.generation_id ELSE NEW.generation_id END;
    SELECT state INTO s FROM index_generations WHERE tenant_id=t AND id=g FOR UPDATE;
    IF s<>'building' THEN RAISE EXCEPTION 'generation sealed' USING ERRCODE='23514'; END IF;
    IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'membership immutable' USING ERRCODE='23514'; END IF;
  ELSE
    g=CASE WHEN TG_OP='DELETE' THEN OLD.snapshot_id ELSE NEW.snapshot_id END;
    IF EXISTS (SELECT 1 FROM publication_snapshots WHERE tenant_id=t AND id=g AND sealed FOR UPDATE) THEN
      RAISE EXCEPTION 'snapshot sealed' USING ERRCODE='23514'; END IF;
    IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'membership immutable' USING ERRCODE='23514'; END IF;
  END IF;
  IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER index_seal BEFORE INSERT OR UPDATE OR DELETE ON index_memberships FOR EACH ROW EXECUTE FUNCTION kk_sealed_membership();
CREATE TRIGGER lexical_seal BEFORE INSERT OR UPDATE OR DELETE ON lexical_documents FOR EACH ROW EXECUTE FUNCTION kk_sealed_membership();
CREATE TRIGGER snapshot_seal BEFORE INSERT OR UPDATE OR DELETE ON snapshot_memberships FOR EACH ROW EXECUTE FUNCTION kk_sealed_membership();
CREATE TRIGGER snapshot_generations_seal BEFORE INSERT OR UPDATE OR DELETE ON snapshot_generations FOR EACH ROW EXECUTE FUNCTION kk_sealed_membership();
CREATE FUNCTION kk_snapshot_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' OR OLD.sealed OR (to_jsonb(OLD)-'sealed') IS DISTINCT FROM (to_jsonb(NEW)-'sealed') OR NOT NEW.sealed THEN
    RAISE EXCEPTION 'snapshot immutable' USING ERRCODE='23514'; END IF;
  IF NOT EXISTS (SELECT 1 FROM snapshot_generations WHERE tenant_id=NEW.tenant_id AND snapshot_id=NEW.id) OR
     EXISTS (SELECT 1 FROM snapshot_generations sg JOIN index_generations g ON g.tenant_id=sg.tenant_id AND g.id=sg.generation_id
       WHERE sg.tenant_id=NEW.tenant_id AND sg.snapshot_id=NEW.id AND g.state NOT IN ('sealed','active','retired')) THEN
    RAISE EXCEPTION 'snapshot generations unsealed' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER snapshot_immutable BEFORE UPDATE OR DELETE ON publication_snapshots FOR EACH ROW EXECUTE FUNCTION kk_snapshot_immutable();
CREATE FUNCTION kk_outbox_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' OR (OLD.tenant_id,OLD.id,OLD.event_json,OLD.payload_digest,OLD.aggregate_version) IS DISTINCT FROM
                      (NEW.tenant_id,NEW.id,NEW.event_json,NEW.payload_digest,NEW.aggregate_version) THEN
    RAISE EXCEPTION 'event identity immutable' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER outbox_immutable BEFORE UPDATE OR DELETE ON outbox FOR EACH ROW EXECUTE FUNCTION kk_outbox_immutable();
CREATE FUNCTION kk_resource_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND ((OLD.tenant_id,OLD.id,OLD.source_id,OLD.owner_kind,OLD.storage_id,OLD.staging_id) IS DISTINCT FROM
      (NEW.tenant_id,NEW.id,NEW.source_id,NEW.owner_kind,NEW.storage_id,NEW.staging_id) OR
      (OLD.digest IS NOT NULL AND (OLD.digest,OLD.byte_length) IS DISTINCT FROM (NEW.digest,NEW.byte_length)) OR
      (OLD.state IN ('uploaded','accepted','deleting','deleted') AND (OLD.storage_key,OLD.object_version) IS DISTINCT FROM (NEW.storage_key,NEW.object_version))) THEN
    RAISE EXCEPTION 'resource identity immutable' USING ERRCODE='23514'; END IF;
  IF NEW.state='accepted' AND (TG_OP='INSERT' OR OLD.state<>'accepted') AND (EXISTS (
      SELECT 1 FROM tombstones WHERE tenant_id=NEW.tenant_id AND source_id=NEW.source_id) OR NOT EXISTS (
      SELECT 1 FROM sources WHERE tenant_id=NEW.tenant_id AND id=NEW.source_id AND lifecycle_state='active') OR EXISTS (
      SELECT 1 FROM resource_writers WHERE tenant_id=NEW.tenant_id AND resource_id=NEW.id AND state<>'closed')) THEN
    RAISE EXCEPTION 'source not accepting resources' USING ERRCODE='23514'; END IF;
  IF NEW.state='deleting' AND (NEW.ref_count<>0 OR NEW.retention_hold OR EXISTS (
      SELECT 1 FROM sources WHERE tenant_id=NEW.tenant_id AND id=NEW.source_id AND retention_hold) OR EXISTS (
      SELECT 1 FROM resource_writers WHERE tenant_id=NEW.tenant_id AND resource_id=NEW.id AND state<>'closed')) THEN
    RAISE EXCEPTION 'resource still in use' USING ERRCODE='23514'; END IF;
  IF NEW.state='deleted' THEN
    IF EXISTS (SELECT 1 FROM resource_writers WHERE tenant_id=NEW.tenant_id AND resource_id=NEW.id AND state<>'closed') OR
       NOT EXISTS (SELECT 1 FROM deletion_attempts WHERE tenant_id=NEW.tenant_id AND resource_id=NEW.id AND NOT in_flight
         AND storage_key IS NOT DISTINCT FROM NEW.storage_key AND object_version IS NOT DISTINCT FROM NEW.object_version
         AND ((NEW.digest IS NOT NULL AND result IN ('deleted','absent')) OR
           (NEW.digest IS NULL AND result='absent' AND staging_id=NEW.staging_id AND provider_receipt_json IS NOT NULL))) THEN
      RAISE EXCEPTION 'absent reservation not proven' USING ERRCODE='23514'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER resource_guard BEFORE INSERT OR UPDATE ON resource_ledger FOR EACH ROW EXECUTE FUNCTION kk_resource_guard();
CREATE FUNCTION kk_restore_gate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.serving_state='ready' AND NEW.restore_receipt_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM restore_receipts WHERE tenant_id=NEW.id AND id=NEW.restore_receipt_id AND serving_state='ready'
      AND proven_through_cutoff AND inventory_verified) THEN
    RAISE EXCEPTION 'restore proof required' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER restore_gate BEFORE UPDATE ON tenants FOR EACH ROW EXECUTE FUNCTION kk_restore_gate();

CREATE FUNCTION kk_generation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' OR (OLD.tenant_id,OLD.id,OLD.kb_id,OLD.profile_id) IS DISTINCT FROM (NEW.tenant_id,NEW.id,NEW.kb_id,NEW.profile_id) OR
     (OLD.state<>'building' AND (OLD.manifest_digest,OLD.manifest_json,OLD.embedding_space_digest,OLD.chunk_count) IS DISTINCT FROM
       (NEW.manifest_digest,NEW.manifest_json,NEW.embedding_space_digest,NEW.chunk_count)) OR
     (OLD.state<>'building' AND NEW.state='building') THEN RAISE EXCEPTION 'generation immutable' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER generation_guard BEFORE UPDATE OR DELETE ON index_generations FOR EACH ROW EXECUTE FUNCTION kk_generation_guard();
CREATE FUNCTION kk_content_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (OLD.tenant_id,OLD.id) IS DISTINCT FROM (NEW.tenant_id,NEW.id) THEN RAISE EXCEPTION 'identity immutable' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='sources' THEN
    IF ((OLD.lifecycle_state<>'active' AND NEW.lifecycle_state='active') OR NEW.lifecycle_epoch<OLD.lifecycle_epoch OR
       NEW.source_policy_epoch<OLD.source_policy_epoch OR NEW.revision<OLD.revision) THEN RAISE EXCEPTION 'source regression' USING ERRCODE='23514'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER source_identity BEFORE UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION kk_content_identity();
CREATE TRIGGER kb_identity BEFORE UPDATE ON knowledge_bases FOR EACH ROW EXECUTE FUNCTION kk_content_identity();
CREATE FUNCTION kk_tombstone_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE; RETURN NEW; END $$;
CREATE TRIGGER tombstone_guard BEFORE INSERT ON tombstones FOR EACH ROW EXECUTE FUNCTION kk_tombstone_guard();
CREATE FUNCTION kk_collection_closure_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF pg_trigger_depth()<2 THEN RAISE EXCEPTION 'closure is derived' USING ERRCODE='23514'; END IF;
  IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER closure_guard BEFORE INSERT OR UPDATE OR DELETE ON collection_closure FOR EACH ROW EXECUTE FUNCTION kk_collection_closure_guard();
CREATE FUNCTION kk_version_correction_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND (OLD.tenant_id,OLD.id) IS DISTINCT FROM (NEW.tenant_id,NEW.id) THEN RAISE EXCEPTION 'identity immutable' USING ERRCODE='23514'; END IF;
  IF NEW.corrected_by_version_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM source_versions a JOIN source_versions b ON b.tenant_id=a.tenant_id AND b.source_id=a.source_id
    WHERE a.tenant_id=NEW.tenant_id AND a.id=NEW.id AND b.id=NEW.corrected_by_version_id) THEN
    RAISE EXCEPTION 'replacement source mismatch' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER correction_guard BEFORE INSERT OR UPDATE ON source_version_state FOR EACH ROW EXECUTE FUNCTION kk_version_correction_guard();

-- Durable inventories retain relational resource identities beside their frozen metadata.
CREATE TABLE deletion_task_resources (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, task_id kk_ulid NOT NULL, resource_id kk_ulid NOT NULL,
  PRIMARY KEY (tenant_id,task_id,resource_id), FOREIGN KEY (tenant_id,task_id) REFERENCES deletion_tasks(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT
);
CREATE FUNCTION kk_inventory_resources() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref jsonb;
BEGIN
  FOR ref IN SELECT * FROM jsonb_array_elements(NEW.required_resources_json) LOOP
    INSERT INTO deletion_task_resources VALUES (NEW.tenant_id,NEW.id,(ref->>'resourceId')::kk_ulid);
    IF NOT EXISTS (SELECT 1 FROM resource_ledger WHERE tenant_id=NEW.tenant_id AND id=(ref->>'resourceId')::kk_ulid AND source_id=NEW.source_id) THEN
      RAISE EXCEPTION 'inventory source mismatch' USING ERRCODE='23514'; END IF;
  END LOOP;
  RETURN NEW;
END $$;
CREATE TRIGGER inventory_resources AFTER INSERT ON deletion_tasks FOR EACH ROW EXECUTE FUNCTION kk_inventory_resources();
CREATE FUNCTION kk_deletion_done() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND (OLD.tenant_id,OLD.id,OLD.source_id,OLD.required_resources_json,OLD.inventory_epoch,OLD.inventory_digest) IS DISTINCT FROM
      (NEW.tenant_id,NEW.id,NEW.source_id,NEW.required_resources_json,NEW.inventory_epoch,NEW.inventory_digest) THEN
    RAISE EXCEPTION 'inventory immutable' USING ERRCODE='23514'; END IF;
  IF NEW.status='done' AND (EXISTS (SELECT 1 FROM sources WHERE tenant_id=NEW.tenant_id AND id=NEW.source_id AND retention_hold) OR EXISTS (
    SELECT 1 FROM resource_ledger r WHERE r.tenant_id=NEW.tenant_id AND r.source_id=NEW.source_id AND r.owner_kind='owned' AND (
      r.state<>'deleted' OR r.retention_hold OR EXISTS (SELECT 1 FROM resource_writers w WHERE w.tenant_id=r.tenant_id AND w.resource_id=r.id AND w.state<>'closed') OR
      NOT EXISTS (SELECT 1 FROM deletion_attempts a WHERE a.tenant_id=r.tenant_id AND a.task_id=NEW.id AND a.resource_id=r.id AND NOT a.in_flight
        AND a.result IN ('deleted','absent') AND a.storage_key IS NOT DISTINCT FROM r.storage_key AND a.object_version IS NOT DISTINCT FROM r.object_version)))) THEN
    RAISE EXCEPTION 'deletion obligations outstanding' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER deletion_done BEFORE INSERT OR UPDATE ON deletion_tasks FOR EACH ROW EXECUTE FUNCTION kk_deletion_done();

ALTER TABLE chunks ADD UNIQUE (tenant_id,chunk_set_id,id,document_id,content_digest);
ALTER TABLE citations ADD FOREIGN KEY (tenant_id,chunk_set_id,chunk_id,document_id,content_digest)
  REFERENCES chunks(tenant_id,chunk_set_id,id,document_id,content_digest) ON DELETE RESTRICT;
ALTER TABLE index_memberships ADD UNIQUE (tenant_id,generation_id,chunk_id,source_version_id);
ALTER TABLE lexical_documents ADD FOREIGN KEY (tenant_id,generation_id,chunk_id,source_version_id)
  REFERENCES index_memberships(tenant_id,generation_id,chunk_id,source_version_id) ON DELETE RESTRICT;
CREATE FUNCTION kk_chunk_document() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM chunk_sets cs JOIN documents d ON d.tenant_id=cs.tenant_id AND d.revision_id=cs.revision_id
    WHERE cs.tenant_id=NEW.tenant_id AND cs.id=NEW.chunk_set_id AND d.id=NEW.document_id) THEN
    RAISE EXCEPTION 'chunk document mismatch' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER chunk_document BEFORE INSERT ON chunks FOR EACH ROW EXECUTE FUNCTION kk_chunk_document();
CREATE FUNCTION kk_snapshot_member() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM index_memberships WHERE tenant_id=NEW.tenant_id AND generation_id=NEW.generation_id
    AND source_version_id=NEW.source_version_id AND revision_id=NEW.revision_id AND chunk_set_id=NEW.chunk_set_id) THEN
    RAISE EXCEPTION 'snapshot exact member mismatch' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER snapshot_exact_member BEFORE INSERT ON snapshot_memberships FOR EACH ROW EXECUTE FUNCTION kk_snapshot_member();

CREATE FUNCTION kk_mutable_companion() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE allowed text[];
BEGIN
  IF TG_OP='DELETE' THEN RAISE EXCEPTION 'audit identity immutable' USING ERRCODE='23514'; END IF;
  allowed=CASE TG_TABLE_NAME WHEN 'citations' THEN ARRAY['payload_state'] WHEN 'evidence_usages' THEN ARRAY['provider_accepted_at']
    WHEN 'dependency_nodes' THEN ARRAY['invalidated_epoch'] ELSE ARRAY[]::text[] END;
  IF (to_jsonb(OLD)-allowed) IS DISTINCT FROM (to_jsonb(NEW)-allowed) THEN RAISE EXCEPTION 'immutable content fields' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='evidence_usages' THEN
    IF OLD.provider_accepted_at IS NOT NULL AND OLD.provider_accepted_at IS DISTINCT FROM NEW.provider_accepted_at THEN
      RAISE EXCEPTION 'provider acceptance immutable' USING ERRCODE='23514'; END IF;
  END IF;
  IF TG_TABLE_NAME='citations' THEN
    IF OLD.payload_state='removed' AND NEW.payload_state<>'removed' THEN RAISE EXCEPTION 'citation payload removed' USING ERRCODE='23514'; END IF;
  END IF;
  IF TG_TABLE_NAME='dependency_nodes' THEN
    IF OLD.invalidated_epoch IS NOT NULL AND (NEW.invalidated_epoch IS NULL OR NEW.invalidated_epoch<OLD.invalidated_epoch) THEN
      RAISE EXCEPTION 'invalidation regression' USING ERRCODE='23514'; END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER citation_content BEFORE UPDATE OR DELETE ON citations FOR EACH ROW EXECUTE FUNCTION kk_mutable_companion();
CREATE TRIGGER usage_content BEFORE UPDATE OR DELETE ON evidence_usages FOR EACH ROW EXECUTE FUNCTION kk_mutable_companion();
CREATE TRIGGER dependency_identity BEFORE UPDATE OR DELETE ON dependency_nodes FOR EACH ROW EXECUTE FUNCTION kk_mutable_companion();
CREATE TRIGGER resource_audit BEFORE DELETE ON resource_ledger FOR EACH ROW EXECUTE FUNCTION kk_immutable();
CREATE TRIGGER deletion_audit BEFORE DELETE ON deletion_tasks FOR EACH ROW EXECUTE FUNCTION kk_immutable();
CREATE TRIGGER inventory_audit BEFORE UPDATE OR DELETE ON deletion_task_resources FOR EACH ROW EXECUTE FUNCTION kk_immutable();
CREATE FUNCTION kk_writer_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND ((OLD.tenant_id,OLD.id,OLD.resource_id,OLD.fence,OLD.job_id,OLD.started_at) IS DISTINCT FROM
      (NEW.tenant_id,NEW.id,NEW.resource_id,NEW.fence,NEW.job_id,NEW.started_at) OR
      (OLD.state='closed' AND (OLD.state,OLD.closed_at) IS DISTINCT FROM (NEW.state,NEW.closed_at))) THEN
    RAISE EXCEPTION 'writer identity immutable' USING ERRCODE='23514'; END IF;
  IF EXISTS (SELECT 1 FROM resource_ledger WHERE tenant_id=NEW.tenant_id AND id=NEW.resource_id AND state IN ('deleting','deleted')) THEN
    RAISE EXCEPTION 'resource deleted' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER writer_guard BEFORE INSERT OR UPDATE ON resource_writers FOR EACH ROW EXECUTE FUNCTION kk_writer_guard();
CREATE TRIGGER writer_audit BEFORE DELETE ON resource_writers FOR EACH ROW EXECUTE FUNCTION kk_immutable();
CREATE FUNCTION kk_attempt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' THEN RAISE EXCEPTION 'deletion receipt immutable' USING ERRCODE='23514'; END IF;
  IF (OLD.tenant_id,OLD.id,OLD.task_id,OLD.resource_id) IS DISTINCT FROM (NEW.tenant_id,NEW.id,NEW.task_id,NEW.resource_id) OR
     (OLD.result IS NOT NULL AND OLD IS DISTINCT FROM NEW) THEN RAISE EXCEPTION 'deletion receipt immutable' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER attempt_guard BEFORE UPDATE OR DELETE ON deletion_attempts FOR EACH ROW EXECUTE FUNCTION kk_attempt_guard();
CREATE FUNCTION kk_job_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (OLD.tenant_id,OLD.id,OLD.source_id,OLD.source_version_id,OLD.kb_id,OLD.generation_id,OLD.type,OLD.idempotency_key,OLD.request_fingerprint) IS DISTINCT FROM
     (NEW.tenant_id,NEW.id,NEW.source_id,NEW.source_version_id,NEW.kb_id,NEW.generation_id,NEW.type,NEW.idempotency_key,NEW.request_fingerprint)
     OR NEW.fence<OLD.fence OR NEW.attempt_count<OLD.attempt_count THEN RAISE EXCEPTION 'job identity immutable' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER job_identity BEFORE UPDATE ON jobs FOR EACH ROW EXECUTE FUNCTION kk_job_identity();

CREATE FUNCTION kk_exact_spans() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE spans jsonb; s jsonb; text_hash kk_digest; text_length bigint; box jsonb; k text;
BEGIN
  IF TG_TABLE_NAME='chunks' THEN spans=NEW.spans_json; ELSE spans=NEW.locator_json; END IF;
  IF jsonb_typeof(spans)<>'array' OR jsonb_array_length(spans)<1 OR jsonb_array_length(spans)>1024 THEN
    RAISE EXCEPTION 'nonempty exact spans required' USING ERRCODE='23514'; END IF;
  FOR s IN SELECT * FROM jsonb_array_elements(spans) LOOP
    IF (jsonb_typeof(s)='object' AND s ?& ARRAY['kind','blockId','byteStart','byteEnd','textDigest'] AND
       jsonb_typeof(s->'byteStart')='number' AND jsonb_typeof(s->'byteEnd')='number' AND
       s->>'byteStart' ~ '^[0-9]+$' AND s->>'byteEnd' ~ '^[0-9]+$' AND
       (s->>'byteStart')::numeric<(s->>'byteEnd')::numeric AND (s->>'byteEnd')::numeric<=4294967295) IS NOT TRUE THEN
      RAISE EXCEPTION 'invalid span shape' USING ERRCODE='23514'; END IF;
    IF NOT EXISTS (SELECT 1 FROM blocks WHERE tenant_id=NEW.tenant_id AND document_id=NEW.document_id AND local_block_id=s->>'blockId') THEN
      RAISE EXCEPTION 'span block mismatch' USING ERRCODE='23514'; END IF;
    IF s->>'kind'='segment' THEN
      IF s ?| ARRAY['pageId','physicalNo','printedLabel','bbox'] THEN RAISE EXCEPTION 'segment/page union mismatch' USING ERRCODE='23514'; END IF;
      SELECT a.text_digest,r.byte_length INTO text_hash,text_length FROM segments a JOIN resource_ledger r ON r.tenant_id=a.tenant_id AND r.id=a.text_resource_id
        WHERE a.tenant_id=NEW.tenant_id AND a.document_id=NEW.document_id AND a.local_segment_id=s->>'segmentId';
    ELSIF s->>'kind'='page' THEN
      IF s ? 'segmentId' OR NOT (s ?& ARRAY['pageId','physicalNo','printedLabel']) OR jsonb_typeof(s->'physicalNo')<>'number' THEN
        RAISE EXCEPTION 'page/segment union mismatch' USING ERRCODE='23514'; END IF;
      SELECT a.text_digest,r.byte_length INTO text_hash,text_length FROM pages a JOIN resource_ledger r ON r.tenant_id=a.tenant_id AND r.id=a.text_resource_id
        WHERE a.tenant_id=NEW.tenant_id AND a.document_id=NEW.document_id AND a.local_page_id=s->>'pageId'
          AND a.physical_no::text=s->>'physicalNo' AND a.printed_label IS NOT DISTINCT FROM s->>'printedLabel';
      IF s ? 'bbox' THEN
        box=s->'bbox';
        IF (box->>'space'='page-millionths-top-left-v1' AND box->>'origin'='top-left' AND box->>'view'='rotated-page' AND
           box ?& ARRAY['x0','y0','x1','y1']) IS NOT TRUE THEN RAISE EXCEPTION 'invalid bbox' USING ERRCODE='23514'; END IF;
        FOREACH k IN ARRAY ARRAY['x0','y0','x1','y1'] LOOP
          IF (jsonb_typeof(box->k)='number' AND box->>k ~ '^[0-9]+$' AND (box->>k)::numeric<=1000000) IS NOT TRUE THEN
            RAISE EXCEPTION 'invalid bbox coordinate' USING ERRCODE='23514'; END IF;
        END LOOP;
        IF (box->>'x0')::numeric>=(box->>'x1')::numeric OR (box->>'y0')::numeric>=(box->>'y1')::numeric THEN
          RAISE EXCEPTION 'empty bbox' USING ERRCODE='23514'; END IF;
      END IF;
    ELSE RAISE EXCEPTION 'unknown span kind' USING ERRCODE='23514'; END IF;
    IF text_hash IS NULL OR text_hash::text IS DISTINCT FROM s->>'textDigest' OR text_length<(s->>'byteEnd')::numeric THEN
      RAISE EXCEPTION 'span exact text mismatch' USING ERRCODE='23514'; END IF;
  END LOOP;
  RETURN NEW;
END $$;
CREATE TRIGGER chunk_spans BEFORE INSERT ON chunks FOR EACH ROW EXECUTE FUNCTION kk_exact_spans();
CREATE TRIGGER citation_spans BEFORE INSERT ON citations FOR EACH ROW EXECUTE FUNCTION kk_exact_spans();

-- Every jsonb column is bounded even when a later owning writer supplies its stricter schema validation.
DO $$ DECLARE c record; BEGIN
  FOR c IN SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=current_schema() AND data_type='jsonb' LOOP
    EXECUTE format('ALTER TABLE %I ADD CHECK (octet_length(%I::text)<=16777216)',c.table_name,c.column_name);
  END LOOP;
END $$;

-- Bind trigger SQL to the installation schema, independent of a caller's search_path.
DO $$ DECLARE f record; BEGIN
  FOR f IN SELECT p.oid::regprocedure AS signature FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
    WHERE n.nspname=current_schema() AND p.proname LIKE 'kk_%' LOOP
    EXECUTE format('ALTER FUNCTION %s SET search_path FROM CURRENT',f.signature);
  END LOOP;
END $$;
