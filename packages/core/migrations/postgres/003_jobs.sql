-- P2.7 Q1=a: additive durable representation; baseline checksums stay unchanged.
ALTER TABLE jobs
  ADD COLUMN scope_json jsonb,
  ADD COLUMN processing_identity_json jsonb,
  ADD COLUMN processing_digest kk_digest,
  ADD COLUMN stage_plan_json jsonb,
  ADD COLUMN completed_stage_count integer NOT NULL DEFAULT 0 CHECK (completed_stage_count BETWEEN 0 AND 16),
  ADD COLUMN stage_input_digest kk_digest,
  ADD COLUMN attempt_id kk_ulid,
  ADD COLUMN resume_verified_fence kk_counter,
  ADD COLUMN accepted_result_digest kk_digest,
  ADD COLUMN accepted_outcome text CHECK (accepted_outcome IN ('success','empty','denied','unknown','unavailable','incompatible')),
  ADD COLUMN accepted_coverage_json jsonb,
  ADD COLUMN uncertain_external_effect boolean NOT NULL DEFAULT false,
  ADD CHECK (scope_json IS NULL OR (jsonb_typeof(scope_json)='object' AND octet_length(scope_json::text)<=4096)),
  ADD CHECK (processing_identity_json IS NULL OR (jsonb_typeof(processing_identity_json)='object' AND octet_length(processing_identity_json::text)<=16384)),
  ADD CHECK (stage_plan_json IS NULL OR (jsonb_typeof(stage_plan_json)='array' AND jsonb_array_length(stage_plan_json) BETWEEN 1 AND 16)),
  ADD CHECK (accepted_coverage_json IS NULL OR (jsonb_typeof(accepted_coverage_json)='object' AND octet_length(accepted_coverage_json::text)<=32768)),
  ADD CHECK ((accepted_result_digest IS NULL)=(accepted_outcome IS NULL)),
  ADD CHECK (stage_plan_json IS NULL OR completed_stage_count<=jsonb_array_length(stage_plan_json)),
  ADD CHECK (processing_digest IS NULL OR (scope_json IS NOT NULL AND processing_identity_json IS NOT NULL
    AND stage_plan_json IS NOT NULL AND stage_input_digest IS NOT NULL AND (state<>'leased' OR attempt_id IS NOT NULL)));
-- Legacy rows lack the complete binding. They remain intact and cannot be leased by the queue.
ALTER TABLE job_checkpoints
  ADD COLUMN checkpoint_digest kk_digest,
  ADD COLUMN checkpoint_json jsonb,
  ADD COLUMN uncertain_external_effect boolean NOT NULL DEFAULT false,
  ADD CHECK (checkpoint_json IS NULL OR (jsonb_typeof(checkpoint_json)='object' AND octet_length(checkpoint_json::text)<=65536));
ALTER TABLE job_checkpoints DROP CONSTRAINT job_checkpoints_tenant_id_job_id_stage_input_digest_key;
-- Uncertain observations must not occupy a successful stage's immutable checkpoint.
CREATE UNIQUE INDEX job_verified_stage ON job_checkpoints(tenant_id,job_id,stage,input_digest) WHERE NOT uncertain_external_effect;
CREATE UNIQUE INDEX job_checkpoint_replay ON job_checkpoints(tenant_id,job_id,checkpoint_digest);
CREATE FUNCTION kk_job_durable_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (OLD.scope_json,OLD.processing_identity_json,OLD.processing_digest,OLD.stage_plan_json) IS DISTINCT FROM
     (NEW.scope_json,NEW.processing_identity_json,NEW.processing_digest,NEW.stage_plan_json)
     OR NEW.completed_stage_count<OLD.completed_stage_count
     OR (OLD.uncertain_external_effect AND NOT NEW.uncertain_external_effect)
     OR (OLD.state IN ('succeeded','failed','cancelled') AND NEW IS DISTINCT FROM OLD)
     OR (NEW.attempt_id IS DISTINCT FROM OLD.attempt_id AND NOT
         (NEW.state='leased' AND NEW.fence>OLD.fence AND NEW.attempt_count=OLD.attempt_count+1)) THEN
    RAISE EXCEPTION 'durable job identity immutable' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER job_durable_identity BEFORE UPDATE ON jobs FOR EACH ROW EXECUTE FUNCTION kk_job_durable_identity();
ALTER FUNCTION kk_job_durable_identity() SET search_path FROM CURRENT;
