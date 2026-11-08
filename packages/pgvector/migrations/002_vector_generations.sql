-- Explicit optional migration; core has no pgvector extension dependency.
-- Install vector in public as the deployment owner before applying this migration.
CREATE TABLE vector_generations (
    tenant_id kk_ulid NOT NULL,
    generation_id kk_ulid NOT NULL,
    manifest_digest kk_digest NOT NULL,
    space_digest kk_digest NOT NULL,
    space_json jsonb NOT NULL CHECK (jsonb_typeof(space_json) = 'object'),
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
