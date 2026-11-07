-- P3.7: explicit, isolated restore. Recovery state never authorizes ordinary reads.
CREATE TABLE reconciliation_runs (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, id kk_ulid NOT NULL,
  fingerprint kk_digest NOT NULL, plan_json jsonb NOT NULL,
  stage text NOT NULL CHECK (stage IN ('journal','objects','ledger','manifests','outbox','canary','ready','blocked')),
  cursor varchar(1280), scanned kk_counter NOT NULL DEFAULT 0, digest kk_digest NOT NULL,
  recovery_sequence kk_counter NOT NULL, revision kk_counter NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id,id), CHECK (octet_length(plan_json::text)<=16384)
);
ALTER TABLE tenants ADD COLUMN reconciliation_run_id kk_ulid;
ALTER TABLE tenants ADD FOREIGN KEY (id,reconciliation_run_id) REFERENCES reconciliation_runs(tenant_id,id) ON DELETE RESTRICT;
CREATE TABLE reconciliation_findings (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, run_id kk_ulid NOT NULL,
  identity_digest kk_digest NOT NULL, resource_id kk_ulid NOT NULL, source_id kk_ulid,
  stage text NOT NULL, outcome text NOT NULL, PRIMARY KEY (tenant_id,run_id,identity_digest),
  registered_resource boolean NOT NULL DEFAULT false CHECK (NOT registered_resource OR stage='objects'),
  FOREIGN KEY (tenant_id,run_id) REFERENCES reconciliation_runs(tenant_id,id) ON DELETE RESTRICT
  -- No source/resource FK: an unregistered owned object is still a durable unknown obligation.
);
CREATE INDEX reconciliation_registered_resources ON reconciliation_findings(tenant_id,run_id,resource_id) WHERE registered_resource;
CREATE TABLE recovery_watermarks (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, source_id kk_ulid NOT NULL,
  lifecycle_epoch kk_counter NOT NULL, source_policy_epoch kk_counter NOT NULL,
  lifecycle_state text NOT NULL CHECK (lifecycle_state IN ('active','revoked','deletion_requested')),
  retention_hold boolean NOT NULL, PRIMARY KEY (tenant_id,source_id)
);
CREATE TABLE recovery_version_marks (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT, source_id kk_ulid NOT NULL,
  version_id kk_ulid NOT NULL, usability text NOT NULL CHECK (usability IN ('corrected','revoked')),
  event_id kk_ulid NOT NULL, corrected_by_version_id kk_ulid, PRIMARY KEY (tenant_id,version_id),
  CHECK (usability='corrected' OR corrected_by_version_id IS NULL),
  CHECK (version_id<>corrected_by_version_id)
);
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON recovery_version_marks FOR EACH ROW EXECUTE FUNCTION kk_immutable();
CREATE FUNCTION kk_recovery_version_floor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM recovery_version_marks m WHERE m.tenant_id=NEW.tenant_id AND m.version_id=NEW.id AND
    (NEW.usability<>m.usability OR
      (NEW.event_id IS DISTINCT FROM m.event_id AND (NEW.event_id IS NOT NULL OR EXISTS (
        SELECT 1 FROM lifecycle_events e WHERE e.tenant_id=m.tenant_id AND e.id=m.event_id))) OR
      (NEW.corrected_by_version_id IS DISTINCT FROM m.corrected_by_version_id AND
        (NEW.corrected_by_version_id IS NOT NULL OR m.corrected_by_version_id IS NULL OR EXISTS (
          SELECT 1 FROM source_versions v WHERE v.tenant_id=m.tenant_id AND v.id=m.corrected_by_version_id))))) THEN
    RAISE EXCEPTION 'recovery version regression' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER recovery_version_floor BEFORE INSERT OR UPDATE ON source_version_state FOR EACH ROW EXECUTE FUNCTION kk_recovery_version_floor();
CREATE FUNCTION kk_recovery_version_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM recovery_version_marks m WHERE m.tenant_id=NEW.tenant_id AND m.version_id=NEW.id AND m.source_id<>NEW.source_id) THEN
    RAISE EXCEPTION 'recovery version source mismatch' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER recovery_version_identity BEFORE INSERT ON source_versions FOR EACH ROW EXECUTE FUNCTION kk_recovery_version_identity();
CREATE FUNCTION kk_recovery_freeze() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t kk_ulid; r kk_ulid;
BEGIN
  t=CASE WHEN TG_OP='DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
  SELECT reconciliation_run_id INTO r FROM tenants WHERE id=t FOR UPDATE;
  IF r IS NOT NULL AND EXISTS (SELECT 1 FROM reconciliation_runs WHERE tenant_id=t AND id=r AND stage<>'ready')
     AND current_setting('knowkit.recovery_run',true) IS DISTINCT FROM r::text THEN
    RAISE EXCEPTION 'restore isolated' USING ERRCODE='23514'; END IF;
  IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
DO $$ DECLARE t text; BEGIN
  FOREACH t IN ARRAY ARRAY['knowledge_bases','collections','source_memberships','sources','source_versions',
    'source_version_state','resource_ledger','resource_writers','tombstones','document_revisions','documents',
    'pages','segments','blocks','assets','chunk_sets','chunks','index_generations','index_memberships',
    'lexical_documents','publication_snapshots','snapshot_memberships','snapshot_generations','jobs',
    'job_checkpoints','outbox','deletion_tasks','deletion_attempts','deletion_task_resources'] LOOP
    EXECUTE format('CREATE TRIGGER recovery_freeze BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION kk_recovery_freeze()',t);
  END LOOP;
END $$;
CREATE FUNCTION kk_recovery_source_floor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM recovery_watermarks w WHERE w.tenant_id=NEW.tenant_id AND w.source_id=NEW.id AND
    (NEW.lifecycle_epoch<w.lifecycle_epoch OR NEW.source_policy_epoch<w.source_policy_epoch OR
      (w.lifecycle_state<>'active' AND NEW.lifecycle_state='active') OR
      (w.retention_hold AND NOT NEW.retention_hold AND NEW.lifecycle_epoch<=w.lifecycle_epoch))) THEN
    RAISE EXCEPTION 'recovery high water regression' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER recovery_source_floor BEFORE INSERT OR UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION kk_recovery_source_floor();
CREATE OR REPLACE FUNCTION kk_restore_gate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.serving_state='ready' AND (
    (NEW.reconciliation_run_id IS NOT NULL AND NOT EXISTS (
      SELECT 1 FROM reconciliation_runs WHERE tenant_id=NEW.id AND id=NEW.reconciliation_run_id AND stage='ready')) OR
    (NEW.restore_receipt_id IS NOT NULL AND NOT EXISTS (
      SELECT 1 FROM restore_receipts WHERE tenant_id=NEW.id AND id=NEW.restore_receipt_id AND serving_state='ready'
        AND proven_through_cutoff AND inventory_verified))) THEN
    RAISE EXCEPTION 'restore proof required' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
-- Only the derived lexical projection may be rebuilt in a gated restore transaction.
-- Immutable memberships, manifests, snapshots and historical citation identities stay sealed.
CREATE FUNCTION kk_recovery_lexical() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE recovery_tenant kk_ulid; recovery_generation kk_ulid;
BEGIN
  recovery_tenant=CASE WHEN TG_OP='DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
  recovery_generation=CASE WHEN TG_OP='DELETE' THEN OLD.generation_id ELSE NEW.generation_id END;
  IF TG_OP<>'UPDATE' AND current_setting('knowkit.recovery_generation',true)=recovery_generation::text AND EXISTS (
    SELECT 1 FROM tenants t JOIN reconciliation_runs r ON r.tenant_id=t.id AND r.id=t.reconciliation_run_id
    WHERE t.id=recovery_tenant AND t.serving_state='blocked' AND r.stage='manifests'
      AND current_setting('knowkit.recovery_run',true)=r.id::text) THEN
    IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM index_generations WHERE tenant_id=recovery_tenant AND id=recovery_generation AND state='building' FOR UPDATE)
    OR TG_OP='UPDATE' THEN RAISE EXCEPTION 'generation sealed' USING ERRCODE='23514'; END IF;
  IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
DROP TRIGGER lexical_seal ON lexical_documents;
CREATE TRIGGER lexical_seal BEFORE INSERT OR UPDATE OR DELETE ON lexical_documents FOR EACH ROW EXECUTE FUNCTION kk_recovery_lexical();
DO $$ DECLARE f record; BEGIN
  FOR f IN SELECT p.oid::regprocedure signature FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
    WHERE n.nspname=current_schema() AND p.proname IN
      ('kk_recovery_freeze','kk_recovery_source_floor','kk_recovery_version_floor','kk_recovery_version_identity','kk_restore_gate','kk_recovery_lexical') LOOP
    EXECUTE format('ALTER FUNCTION %s SET search_path FROM CURRENT',f.signature);
  END LOOP;
END $$;
