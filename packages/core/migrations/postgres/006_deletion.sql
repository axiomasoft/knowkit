-- P3.6: durable obligations do not depend on optional storage/provider configuration.
ALTER TABLE resource_ledger
  ADD COLUMN resource_class text NOT NULL DEFAULT 'provider_resource'
    CHECK (resource_class IN ('original','ir','canonical_slice','asset','embedding','provider_resource','upload_reservation','backup_obligation')),
  ADD COLUMN retention_until timestamptz;
ALTER TABLE deletion_attempts
  ADD COLUMN attempt_sequence bigint GENERATED ALWAYS AS IDENTITY,
  ADD COLUMN exact_version_proof boolean NOT NULL DEFAULT false,
  ADD COLUMN error_code text CHECK (error_code IN ('DELETION_TRANSPORT_EXCEPTION','DELETION_RECEIPT_MISMATCH','DELETION_UNPROVEN','DELETION_INTERRUPTED')),
  ADD CHECK (NOT exact_version_proof OR (NOT in_flight AND result IN ('deleted','absent') AND provider_receipt_json IS NOT NULL));
CREATE INDEX deletion_latest_attempt ON deletion_attempts(tenant_id,task_id,resource_id,attempt_sequence DESC);

CREATE OR REPLACE FUNCTION kk_attempt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' THEN RAISE EXCEPTION 'deletion receipt immutable' USING ERRCODE='23514'; END IF;
  IF (OLD.tenant_id,OLD.id,OLD.task_id,OLD.resource_id,OLD.storage_key,OLD.object_version,OLD.staging_id,OLD.attempt_sequence)
      IS DISTINCT FROM (NEW.tenant_id,NEW.id,NEW.task_id,NEW.resource_id,NEW.storage_key,NEW.object_version,NEW.staging_id,NEW.attempt_sequence)
      OR (OLD.result IS NOT NULL AND OLD IS DISTINCT FROM NEW) THEN
    RAISE EXCEPTION 'deletion receipt immutable' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;

-- Older receipts have no adapter exact-version assertion. Preserve them without carrying forward done.
UPDATE deletion_tasks SET status='unknown' WHERE status='done';

CREATE FUNCTION kk_resource_obligation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND (OLD.resource_class,OLD.retention_until) IS DISTINCT FROM (NEW.resource_class,NEW.retention_until) THEN
    RAISE EXCEPTION 'resource obligation immutable' USING ERRCODE='23514'; END IF;
  -- A discovered orphan is admitted only as unknown. New normal writers cannot attach after revoke.
  IF TG_OP='INSERT' AND NEW.state<>'unknown' AND EXISTS (
      SELECT 1 FROM tombstones WHERE tenant_id=NEW.tenant_id AND source_id=NEW.source_id) THEN
    RAISE EXCEPTION 'source not accepting resources' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER resource_obligation BEFORE INSERT OR UPDATE ON resource_ledger FOR EACH ROW EXECUTE FUNCTION kk_resource_obligation();
CREATE FUNCTION kk_late_obligation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.owner_kind='owned' THEN
    UPDATE deletion_tasks SET status='unknown' WHERE tenant_id=NEW.tenant_id AND source_id=NEW.source_id AND status='done';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER late_obligation AFTER INSERT ON resource_ledger FOR EACH ROW EXECUTE FUNCTION kk_late_obligation();

CREATE OR REPLACE FUNCTION kk_deletion_done() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='UPDATE' AND (OLD.tenant_id,OLD.id,OLD.source_id,OLD.required_resources_json,OLD.inventory_epoch,OLD.inventory_digest) IS DISTINCT FROM
      (NEW.tenant_id,NEW.id,NEW.source_id,NEW.required_resources_json,NEW.inventory_epoch,NEW.inventory_digest) THEN
    RAISE EXCEPTION 'inventory immutable' USING ERRCODE='23514'; END IF;
  IF NEW.status='done' AND (
    jsonb_array_length(NEW.required_resources_json)=0 OR
    NOT EXISTS (SELECT 1 FROM sources s JOIN tombstones t ON t.tenant_id=s.tenant_id AND t.source_id=s.id
      WHERE s.tenant_id=NEW.tenant_id AND s.id=NEW.source_id AND s.lifecycle_state<>'active' AND NOT s.retention_hold) OR
    EXISTS (SELECT 1 FROM jobs WHERE tenant_id=NEW.tenant_id AND source_id=NEW.source_id AND (state IN ('pending','leased') OR uncertain_external_effect)) OR
    EXISTS (SELECT 1 FROM deletion_attempts a JOIN deletion_tasks t ON t.tenant_id=a.tenant_id AND t.id=a.task_id
      WHERE t.tenant_id=NEW.tenant_id AND t.source_id=NEW.source_id AND a.in_flight) OR
    EXISTS (SELECT 1 FROM resource_ledger r WHERE r.tenant_id=NEW.tenant_id AND r.source_id=NEW.source_id AND r.owner_kind='owned' AND (
      r.state<>'deleted' OR r.retention_hold OR
      NOT EXISTS (SELECT 1 FROM deletion_task_resources i WHERE i.tenant_id=r.tenant_id AND i.task_id=NEW.id AND i.resource_id=r.id) OR
      EXISTS (SELECT 1 FROM resource_writers w WHERE w.tenant_id=r.tenant_id AND w.resource_id=r.id AND w.state<>'closed') OR
      NOT EXISTS (SELECT 1 FROM deletion_attempts a WHERE a.tenant_id=r.tenant_id AND a.task_id=NEW.id AND a.resource_id=r.id
        AND a.exact_version_proof AND a.result IN ('deleted','absent') AND NOT a.in_flight
        AND a.storage_key IS NOT DISTINCT FROM r.storage_key AND a.object_version IS NOT DISTINCT FROM r.object_version
        AND a.staging_id IS NOT DISTINCT FROM r.staging_id
        AND (r.retention_until IS NULL OR a.observed_at>=r.retention_until)
        AND (r.resource_class<>'backup_obligation' OR (a.known_expiry IS NOT NULL AND a.known_expiry<=a.observed_at))
        AND NOT EXISTS (SELECT 1 FROM deletion_attempts newer WHERE newer.tenant_id=a.tenant_id AND newer.task_id=a.task_id
          AND newer.resource_id=a.resource_id AND newer.attempt_sequence>a.attempt_sequence))))) THEN
    RAISE EXCEPTION 'deletion obligations outstanding' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
