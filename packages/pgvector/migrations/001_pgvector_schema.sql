-- Optional package administration only (PostgreSQL 17+ / pgvector 0.8+).
-- Apply explicitly in the host-owned catalog schema after core migrations.
-- Install vector in public as the deployment owner before applying this migration.

CREATE TABLE embedding_requests (
    tenant_id char(26) NOT NULL,
    kb_id char(26) NOT NULL,
    request_digest char(64) NOT NULL,
    scope_digest char(64) NOT NULL,
    space_digest char(64) NOT NULL,
    next_attempt integer NOT NULL CHECK (next_attempt > 0),
    accepted_result_digest char(64),
    PRIMARY KEY (tenant_id, kb_id, request_digest)
);

CREATE TABLE embedding_receipts (
    tenant_id char(26) NOT NULL,
    kb_id char(26) NOT NULL,
    request_digest char(64) NOT NULL,
    attempt integer NOT NULL CHECK (attempt > 0),
    status text NOT NULL CHECK (status IN ('accepted', 'unknown', 'failed')),
    result_fingerprint char(64),
    provider_request_id varchar(128),
    failure_outcome text CHECK (failure_outcome IN ('denied', 'unknown', 'unavailable', 'incompatible')),
    PRIMARY KEY (tenant_id, kb_id, request_digest, attempt),
    FOREIGN KEY (tenant_id, kb_id, request_digest)
        REFERENCES embedding_requests (tenant_id, kb_id, request_digest) ON DELETE RESTRICT,
    CHECK ((status = 'accepted') = (result_fingerprint IS NOT NULL)),
    CHECK (status <> 'accepted' OR failure_outcome IS NULL),
    CHECK (status <> 'failed' OR (failure_outcome IS NOT NULL AND failure_outcome <> 'unknown')),
    CHECK (status <> 'unknown' OR failure_outcome IS NULL OR failure_outcome = 'unknown')
);

CREATE TABLE vector_generations (
    tenant_id kk_ulid NOT NULL,
    generation_id kk_ulid NOT NULL,
    manifest_digest kk_digest NOT NULL,
    space_digest kk_digest NOT NULL,
    space_json jsonb NOT NULL CHECK (jsonb_typeof(space_json) = 'object'),
    storage_format text NOT NULL DEFAULT 'float32' CHECK (storage_format IN ('float32', 'halfvec', 'binary_quantized')),
    PRIMARY KEY (tenant_id, generation_id),
    UNIQUE (tenant_id, generation_id, space_digest),
    FOREIGN KEY (tenant_id, generation_id) REFERENCES index_generations(tenant_id, id) ON DELETE RESTRICT
);

CREATE TABLE vector_documents (
    tenant_id kk_ulid NOT NULL,
    generation_id kk_ulid NOT NULL,
    chunk_id kk_ulid NOT NULL,
    space_digest kk_digest NOT NULL,
    vector_digest kk_digest NOT NULL,
    embedding public.vector NOT NULL,
    PRIMARY KEY (tenant_id, generation_id, chunk_id),
    FOREIGN KEY (tenant_id, generation_id, space_digest)
        REFERENCES vector_generations(tenant_id, generation_id, space_digest) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, generation_id, chunk_id)
        REFERENCES index_memberships(tenant_id, generation_id, chunk_id) ON DELETE RESTRICT
);

CREATE TABLE vector_documents_halfvec (
    tenant_id kk_ulid NOT NULL,
    generation_id kk_ulid NOT NULL,
    chunk_id kk_ulid NOT NULL,
    space_digest kk_digest NOT NULL,
    vector_digest kk_digest NOT NULL,
    embedding public.halfvec NOT NULL,
    PRIMARY KEY (tenant_id, generation_id, chunk_id),
    FOREIGN KEY (tenant_id, generation_id, space_digest)
        REFERENCES vector_generations(tenant_id, generation_id, space_digest) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, generation_id, chunk_id)
        REFERENCES index_memberships(tenant_id, generation_id, chunk_id) ON DELETE RESTRICT
);

CREATE TABLE vector_documents_binary (
    tenant_id kk_ulid NOT NULL,
    generation_id kk_ulid NOT NULL,
    chunk_id kk_ulid NOT NULL,
    space_digest kk_digest NOT NULL,
    vector_digest kk_digest NOT NULL,
    quantized bit varying NOT NULL,
    rescore_halfvec public.halfvec NOT NULL,
    PRIMARY KEY (tenant_id, generation_id, chunk_id),
    FOREIGN KEY (tenant_id, generation_id, space_digest)
        REFERENCES vector_generations(tenant_id, generation_id, space_digest) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, generation_id, chunk_id)
        REFERENCES index_memberships(tenant_id, generation_id, chunk_id) ON DELETE RESTRICT
);

CREATE FUNCTION kk_vector_staging_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE generation_state text;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'vector identity immutable' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN
        SELECT state INTO generation_state FROM index_generations
            WHERE tenant_id = OLD.tenant_id AND id = OLD.generation_id FOR UPDATE;
    ELSE
        SELECT state INTO generation_state FROM index_generations
            WHERE tenant_id = NEW.tenant_id AND id = NEW.generation_id FOR UPDATE;
    END IF;
    IF generation_state IS DISTINCT FROM 'building' THEN
        RAISE EXCEPTION 'vector generation sealed' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER vector_generation_guard BEFORE INSERT OR UPDATE OR DELETE ON vector_generations
    FOR EACH ROW EXECUTE FUNCTION kk_vector_staging_guard();
CREATE TRIGGER vector_document_guard BEFORE INSERT OR UPDATE OR DELETE ON vector_documents
    FOR EACH ROW EXECUTE FUNCTION kk_vector_staging_guard();
CREATE TRIGGER vector_document_halfvec_guard BEFORE INSERT OR UPDATE OR DELETE ON vector_documents_halfvec
    FOR EACH ROW EXECUTE FUNCTION kk_vector_staging_guard();
CREATE TRIGGER vector_document_binary_guard BEFORE INSERT OR UPDATE OR DELETE ON vector_documents_binary
    FOR EACH ROW EXECUTE FUNCTION kk_vector_staging_guard();

-- Dynamic search_path pinning for all kk_* functions in the owning schema
DO $$
DECLARE
    fn record;
BEGIN
    FOR fn IN
        SELECT p.oid::regprocedure AS sig, n.nspname AS sch
        FROM pg_proc p
        JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = current_schema()
          AND left(p.proname, 3) = 'kk_'
    LOOP
        EXECUTE format('ALTER FUNCTION %s SET search_path TO pg_catalog,%I,pg_temp', fn.sig, fn.sch);
    END LOOP;
END;
$$;
