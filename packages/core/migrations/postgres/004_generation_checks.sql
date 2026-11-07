-- Internal staging receipt sidecar; approved IndexManifest wire schema is unchanged.
CREATE TABLE generation_checks (
  tenant_id kk_ulid NOT NULL, generation_id kk_ulid NOT NULL, job_id kk_ulid NOT NULL,
  manifest_digest kk_digest NOT NULL, source_epochs jsonb NOT NULL,
  receipt_json jsonb, event_id kk_ulid, failure_outcome text, failure_code varchar(64),
  PRIMARY KEY (tenant_id,generation_id),
  FOREIGN KEY (tenant_id,generation_id) REFERENCES index_generations(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,job_id) REFERENCES jobs(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,event_id) REFERENCES outbox(tenant_id,id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED,
  CHECK (jsonb_typeof(source_epochs)='array' AND octet_length(source_epochs::text)<=1048576),
  CHECK (receipt_json IS NULL OR (jsonb_typeof(receipt_json)='object' AND octet_length(receipt_json::text)<=4194304)),
  CHECK ((receipt_json IS NULL)=(event_id IS NULL)),
  CHECK ((failure_outcome IS NULL)=(failure_code IS NULL)),
  CHECK (failure_outcome IS NULL OR failure_outcome IN ('denied','unknown','unavailable','incompatible'))
);
CREATE FUNCTION kk_generation_check_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' OR (OLD.tenant_id,OLD.generation_id,OLD.job_id,OLD.manifest_digest,OLD.source_epochs) IS DISTINCT FROM
      (NEW.tenant_id,NEW.generation_id,NEW.job_id,NEW.manifest_digest,NEW.source_epochs)
    OR OLD.receipt_json IS NOT NULL OR OLD.failure_outcome IS NOT NULL THEN
    RAISE EXCEPTION 'generation check immutable' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER generation_check_guard BEFORE UPDATE OR DELETE ON generation_checks FOR EACH ROW EXECUTE FUNCTION kk_generation_check_guard();

-- A failed build is terminal; retry/rebuild uses a new generation identity.
CREATE FUNCTION kk_failed_generation_terminal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.state='failed' AND NEW.state<>'failed' THEN
    RAISE EXCEPTION 'failed generation terminal' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER failed_generation_terminal BEFORE UPDATE ON index_generations FOR EACH ROW EXECUTE FUNCTION kk_failed_generation_terminal();
