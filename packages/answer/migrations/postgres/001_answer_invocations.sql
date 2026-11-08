-- Optional answer package. Explicitly apply after the core migrations in the same schema.
-- Private adaptation of AgenTools fenced journal/immutable-record boundaries; P4.8-reuse.json.
-- No prompt, question, raw history, credentials or answer bytes are SQL columns.
CREATE TABLE knowkit_answer_payloads (
  tenant_id kk_ulid NOT NULL,
  resource_id kk_ulid NOT NULL,
  kb_id kk_ulid NOT NULL,
  digest kk_digest NOT NULL,
  kind text NOT NULL CHECK (kind IN ('pins','request','call','response')),
  dependencies_json jsonb NOT NULL,
  retention_deadline timestamptz NOT NULL,
  PRIMARY KEY (tenant_id,resource_id),
  UNIQUE (tenant_id,resource_id,digest),
  FOREIGN KEY (tenant_id,resource_id) REFERENCES resource_ledger(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT,
  CHECK (jsonb_typeof(dependencies_json)='object' AND octet_length(dependencies_json::text)<=1048576)
);
CREATE TRIGGER answer_payload_immutable BEFORE UPDATE OR DELETE ON knowkit_answer_payloads
  FOR EACH ROW EXECUTE FUNCTION kk_immutable();

CREATE TABLE knowkit_answer_invocations (
  tenant_id kk_ulid NOT NULL,
  invocation_key varchar(256) NOT NULL CHECK (length(invocation_key)>0),
  kb_id kk_ulid NOT NULL,
  actor_id kk_ulid NOT NULL,
  business_purpose varchar(131) NOT NULL,
  policy_epoch kk_counter NOT NULL,
  host_scope_hash kk_digest NOT NULL,
  original_request_digest kk_digest NOT NULL,
  request_fingerprint kk_digest NOT NULL,
  binding_snapshot_ref kk_ulid NOT NULL,
  binding_snapshot_hash kk_digest NOT NULL,
  binding_digest kk_digest NOT NULL,
  profile_ref varchar(256) NOT NULL,
  profile_revision varchar(256) NOT NULL,
  profile_digest kk_digest NOT NULL,
  state text NOT NULL CHECK (state IN ('preparing','reserved','sending','completed','unknown')),
  row_version kk_counter NOT NULL,
  lease_owner varchar(256) NOT NULL,
  lease_until timestamptz NOT NULL,
  fencing_epoch kk_counter NOT NULL,
  request_payload_ref kk_ulid,
  request_payload_hash kk_digest,
  model_call_identity_ref kk_ulid,
  model_call_identity_hash kk_digest,
  response_payload_ref kk_ulid,
  response_payload_hash kk_digest,
  evidence_set_id kk_digest,
  egress_manifest_ref kk_ulid,
  egress_manifest_hash kk_digest,
  budget_key varchar(256),
  reservation_ref varchar(256),
  reservation_status text CHECK (reservation_status IN ('confirmed','denied','unknown')),
  provider_request_id varchar(256),
  observed_model_ref varchar(256),
  dispatch_certainty text NOT NULL CHECK (dispatch_certainty IN ('knownNotSent','sent','unknown')),
  outcome_code varchar(64),
  usage_ref varchar(256),
  retention_deadline timestamptz NOT NULL,
  created_at timestamptz NOT NULL,
  updated_at timestamptz NOT NULL,
  PRIMARY KEY (tenant_id,invocation_key),
  FOREIGN KEY (tenant_id,kb_id) REFERENCES knowledge_bases(tenant_id,id) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,binding_snapshot_ref,binding_snapshot_hash) REFERENCES knowkit_answer_payloads(tenant_id,resource_id,digest) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,request_payload_ref,request_payload_hash) REFERENCES knowkit_answer_payloads(tenant_id,resource_id,digest) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,model_call_identity_ref,model_call_identity_hash) REFERENCES knowkit_answer_payloads(tenant_id,resource_id,digest) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,response_payload_ref,response_payload_hash) REFERENCES knowkit_answer_payloads(tenant_id,resource_id,digest) ON DELETE RESTRICT,
  FOREIGN KEY (tenant_id,egress_manifest_ref,egress_manifest_hash) REFERENCES knowkit_answer_payloads(tenant_id,resource_id,digest) ON DELETE RESTRICT,
  CHECK ((request_payload_ref IS NULL)=(request_payload_hash IS NULL)),
  CHECK ((model_call_identity_ref IS NULL)=(model_call_identity_hash IS NULL)),
  CHECK ((response_payload_ref IS NULL)=(response_payload_hash IS NULL)),
  CHECK (state<>'reserved' OR (request_payload_ref IS NOT NULL AND reservation_ref IS NOT NULL)),
  CHECK (state NOT IN ('sending','unknown') OR model_call_identity_ref IS NOT NULL),
  CHECK (state<>'sending' OR dispatch_certainty='unknown'),
  CHECK (state<>'unknown' OR (dispatch_certainty='unknown' AND outcome_code='TransportUnknown')),
  CHECK (state<>'completed' OR (response_payload_ref IS NOT NULL AND outcome_code IS NOT NULL AND dispatch_certainty<>'unknown')),
  CHECK (state NOT IN ('preparing','reserved') OR dispatch_certainty='knownNotSent')
);
CREATE INDEX answer_expired_work ON knowkit_answer_invocations(tenant_id,state,lease_until);

CREATE FUNCTION kk_answer_invocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP='DELETE' THEN
    RAISE EXCEPTION 'invocation audit immutable' USING ERRCODE='23514';
  END IF;
  IF TG_OP='INSERT' THEN
    IF NEW.state<>'preparing' OR NEW.row_version<>0 OR NEW.fencing_epoch<>0
       OR NEW.request_payload_ref IS NOT NULL OR NEW.model_call_identity_ref IS NOT NULL
       OR NEW.response_payload_ref IS NOT NULL THEN
      RAISE EXCEPTION 'invocation starts preparing' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
  END IF;
  IF OLD.state IN ('preparing','reserved') AND NEW.state='completed' AND NEW.dispatch_certainty<>'knownNotSent' THEN
    RAISE EXCEPTION 'pre-send completion cannot claim dispatch' USING ERRCODE='23514';
  END IF;
  IF (OLD.tenant_id,OLD.invocation_key,OLD.kb_id,OLD.actor_id,OLD.business_purpose,
      OLD.policy_epoch,OLD.host_scope_hash,OLD.original_request_digest,OLD.request_fingerprint,
      OLD.binding_snapshot_ref,OLD.binding_snapshot_hash,OLD.binding_digest,
      OLD.profile_ref,OLD.profile_revision,OLD.profile_digest,OLD.retention_deadline,OLD.created_at)
      IS DISTINCT FROM
     (NEW.tenant_id,NEW.invocation_key,NEW.kb_id,NEW.actor_id,NEW.business_purpose,
      NEW.policy_epoch,NEW.host_scope_hash,NEW.original_request_digest,NEW.request_fingerprint,
      NEW.binding_snapshot_ref,NEW.binding_snapshot_hash,NEW.binding_digest,
      NEW.profile_ref,NEW.profile_revision,NEW.profile_digest,NEW.retention_deadline,NEW.created_at)
     OR OLD.state IN ('completed','unknown') OR NEW.row_version<>OLD.row_version+1 THEN
    RAISE EXCEPTION 'immutable invocation identity or stale version' USING ERRCODE='23514';
  END IF;
  IF OLD.request_payload_ref IS NOT NULL AND
      (OLD.request_payload_ref,OLD.request_payload_hash,OLD.budget_key,OLD.reservation_ref)
      IS DISTINCT FROM (NEW.request_payload_ref,NEW.request_payload_hash,NEW.budget_key,NEW.reservation_ref) THEN
    RAISE EXCEPTION 'reserved request immutable' USING ERRCODE='23514';
  END IF;
  IF OLD.model_call_identity_ref IS NOT NULL AND
      (OLD.model_call_identity_ref,OLD.model_call_identity_hash,OLD.egress_manifest_ref,OLD.egress_manifest_hash)
      IS DISTINCT FROM (NEW.model_call_identity_ref,NEW.model_call_identity_hash,NEW.egress_manifest_ref,NEW.egress_manifest_hash) THEN
    RAISE EXCEPTION 'outbound identity immutable' USING ERRCODE='23514';
  END IF;
  IF NEW.fencing_epoch=OLD.fencing_epoch+1 THEN
    IF OLD.lease_until>clock_timestamp() OR NEW.lease_until<=clock_timestamp()
       OR NOT ((OLD.state IN ('preparing','reserved') AND NEW.state=OLD.state)
           OR (OLD.state='sending' AND NEW.state='unknown')) THEN
      RAISE EXCEPTION 'invalid recovery fence' USING ERRCODE='23514';
    END IF;
  ELSIF NEW.fencing_epoch<>OLD.fencing_epoch OR NEW.lease_owner<>OLD.lease_owner
      OR NEW.lease_until<>OLD.lease_until OR OLD.lease_until<=clock_timestamp() THEN
    RAISE EXCEPTION 'expired or replaced claim' USING ERRCODE='23514';
  ELSIF NOT ((OLD.state='preparing' AND NEW.state IN ('reserved','completed'))
      OR (OLD.state='reserved' AND NEW.state IN ('sending','completed'))
      OR (OLD.state='sending' AND NEW.state IN ('completed','unknown'))) THEN
    RAISE EXCEPTION 'invalid invocation transition' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER answer_invocation_guard BEFORE INSERT OR UPDATE OR DELETE ON knowkit_answer_invocations
  FOR EACH ROW EXECUTE FUNCTION kk_answer_invocation_guard();
