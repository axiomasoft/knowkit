-- Q1=a: unit disclosure has exact revision/unit lineage without a fabricated search Selection.
CREATE TABLE navigation_usages (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
  id kk_ulid NOT NULL,
  disclosure_attempt_id kk_ulid NOT NULL,
  kb_id kk_ulid NOT NULL,
  actor_id kk_ulid NOT NULL,
  purpose varchar(128) NOT NULL,
  host_scope_hash kk_digest NOT NULL,
  source_id kk_ulid NOT NULL,
  source_version_id kk_ulid NOT NULL,
  revision_id kk_ulid NOT NULL,
  ir_digest kk_digest NOT NULL,
  document_id kk_ulid NOT NULL,
  policy_binding_digest kk_digest NOT NULL,
  prepared_at timestamptz NOT NULL,
  payload_digest kk_digest NOT NULL,
  reference_json jsonb NOT NULL,
  PRIMARY KEY (tenant_id,id),
  UNIQUE (tenant_id,disclosure_attempt_id),
  FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_id,source_version_id) REFERENCES source_versions(tenant_id,source_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,source_version_id,revision_id) REFERENCES document_revisions(tenant_id,source_version_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,revision_id,ir_digest) REFERENCES document_revisions(tenant_id,id,ir_digest) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,revision_id,document_id) REFERENCES documents(tenant_id,revision_id,id) ON DELETE RESTRICT,
  CHECK (jsonb_typeof(reference_json)='object' AND octet_length(reference_json::text)<=65536)
);
CREATE TRIGGER navigation_usage_immutable BEFORE UPDATE OR DELETE ON navigation_usages
  FOR EACH ROW EXECUTE FUNCTION kk_immutable();
