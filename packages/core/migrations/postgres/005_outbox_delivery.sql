-- Delivery metadata is mutable; the original event envelope is immutable.
ALTER TABLE outbox ADD COLUMN lease_token_hash kk_digest,
  ADD COLUMN available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  ADD COLUMN enqueued_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  ADD COLUMN last_error_code text CHECK (last_error_code IN ('transport_error','poison_event')),
  ADD COLUMN transport_errors kk_counter NOT NULL DEFAULT 0;
-- A legacy in-flight lease has no capability: safely redeliver it.
UPDATE outbox SET delivery_state='pending',lease_until=NULL WHERE delivery_state='leased';
UPDATE outbox SET lease_until=NULL WHERE delivery_state<>'leased';
ALTER TABLE outbox ADD CHECK (
  (delivery_state='leased' AND lease_until IS NOT NULL AND lease_token_hash IS NOT NULL) OR
  (delivery_state<>'leased' AND lease_until IS NULL AND lease_token_hash IS NULL)
);
CREATE INDEX outbox_available ON outbox(tenant_id,available_at,id) WHERE delivery_state IN ('pending','leased');
CREATE FUNCTION kk_outbox_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' THEN RAISE EXCEPTION 'immutable event identity' USING ERRCODE='23514'; END IF;
  IF ROW(NEW.tenant_id,NEW.id,NEW.event_json,NEW.payload_digest,NEW.aggregate_version,NEW.enqueued_at)
     IS DISTINCT FROM ROW(OLD.tenant_id,OLD.id,OLD.event_json,OLD.payload_digest,OLD.aggregate_version,OLD.enqueued_at)
  THEN RAISE EXCEPTION 'immutable event identity' USING ERRCODE='23514'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER outbox_identity BEFORE UPDATE OR DELETE ON outbox FOR EACH ROW EXECUTE FUNCTION kk_outbox_identity();

ALTER TABLE inbox ADD COLUMN envelope_digest kk_digest;
CREATE TABLE event_consumers (
  tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
  consumer_id varchar(128) NOT NULL CHECK (consumer_id ~ '^[a-z][a-z0-9_.:-]{0,127}$'),
  blocked boolean NOT NULL DEFAULT false,
  error_code text CHECK (error_code IN ('event_identity_conflict','aggregate_sequence_conflict')),
  PRIMARY KEY (tenant_id,consumer_id)
);
CREATE TABLE event_sequences (
  tenant_id kk_ulid NOT NULL, consumer_id varchar(128) NOT NULL,
  aggregate_type varchar(128) NOT NULL, aggregate_id kk_ulid NOT NULL, aggregate_version kk_counter NOT NULL,
  event_id kk_ulid NOT NULL, payload_digest kk_digest NOT NULL,
  PRIMARY KEY (tenant_id,consumer_id,aggregate_type,aggregate_id,aggregate_version),
  FOREIGN KEY (tenant_id,consumer_id) REFERENCES event_consumers(tenant_id,consumer_id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,consumer_id,event_id) REFERENCES inbox(tenant_id,consumer_id,event_id) ON DELETE RESTRICT
);
-- Invalidation watermarks only. These rows never grant read permission or activate generations.
CREATE TABLE event_projections (
  tenant_id kk_ulid NOT NULL, consumer_id varchar(128) NOT NULL,
  aggregate_type varchar(128) NOT NULL, aggregate_id kk_ulid NOT NULL, aggregate_version kk_counter NOT NULL,
  lifecycle_epoch kk_counter NOT NULL DEFAULT 0, source_policy_epoch kk_counter NOT NULL DEFAULT 0,
  pointer_version kk_counter NOT NULL DEFAULT 0, invalidated boolean NOT NULL DEFAULT true CHECK (invalidated),
  stale boolean NOT NULL DEFAULT true CHECK (stale), version_gap boolean NOT NULL,
  PRIMARY KEY (tenant_id,consumer_id,aggregate_type,aggregate_id),
  FOREIGN KEY (tenant_id,consumer_id) REFERENCES event_consumers(tenant_id,consumer_id) ON DELETE RESTRICT
);
CREATE TABLE event_quarantine (
  tenant_id kk_ulid NOT NULL, consumer_id varchar(128) NOT NULL,
  envelope_digest kk_digest NOT NULL, error_code text NOT NULL CHECK (error_code='poison_event'),
  observed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY (tenant_id,consumer_id,envelope_digest),
  FOREIGN KEY (tenant_id,consumer_id) REFERENCES event_consumers(tenant_id,consumer_id) ON DELETE RESTRICT
);
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON event_sequences FOR EACH ROW EXECUTE FUNCTION kk_immutable();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON event_quarantine FOR EACH ROW EXECUTE FUNCTION kk_immutable();
