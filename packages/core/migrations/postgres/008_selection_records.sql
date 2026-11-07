-- Private immutable server record retains original query, exact profile, snapshot and creation time.
-- Existing reference-only rows cannot be reused without their original server bindings.
ALTER TABLE selections ADD COLUMN record_json jsonb;
ALTER TABLE selections ADD CONSTRAINT selection_record_object
    CHECK (record_json IS NULL OR jsonb_typeof(record_json) = 'object');
