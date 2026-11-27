-- KnowKit core layers, artifacts, catalog mounts, and concept graph (PostgreSQL 17+).
-- Apply explicitly through Catalog\Migrate after 001_metadata.sql.

-- 1. Trusted directives (ARCH-DIRECTIVE-PROMOTION)
CREATE TABLE directive_artifacts (
    tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    id kk_ulid NOT NULL,
    version integer NOT NULL CHECK (version >= 1),
    title text NOT NULL CHECK (char_length(title) BETWEEN 1 AND 256),
    content_format text NOT NULL CHECK (content_format IN ('compact_markdown', 'canonical_json')),
    body text NOT NULL CHECK (octet_length(body) BETWEEN 1 AND 131072),
    body_digest kk_digest NOT NULL,
    locked_rules jsonb NOT NULL DEFAULT '{}'::jsonb,
    promoted_by kk_ulid NOT NULL,
    promoted_at timestamptz NOT NULL,
    derived_from jsonb NOT NULL DEFAULT '[]'::jsonb,
    approval_digest kk_digest NOT NULL,
    artifact_digest kk_digest NOT NULL,
    dependency_node_id kk_ulid NOT NULL,
    PRIMARY KEY (tenant_id, id),
    UNIQUE (tenant_id, artifact_digest)
);

CREATE TRIGGER trg_directive_artifacts_immutable
    BEFORE UPDATE OR DELETE ON directive_artifacts
    FOR EACH ROW EXECUTE FUNCTION kk_immutable();

-- 2. Immutable layer definitions for Layer DAG (ARCH-LAYER-DAG)
CREATE TABLE layer_definitions (
    tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    id kk_ulid NOT NULL,
    layer_key text NOT NULL CHECK (layer_key ~ '^[a-z][a-z0-9._-]{1,63}$'),
    trust_tier text NOT NULL CHECK (trust_tier IN ('trusted_directive', 'curated_reference', 'untrusted_evidence', 'dynamic_overlay')),
    delivery_mode text NOT NULL CHECK (delivery_mode IN ('pinned_prefix', 'session_pinned', 'hybrid_retrievable', 'tool_discoverable', 'volatile_suffix')),
    merge_strategy text NOT NULL CHECK (merge_strategy IN ('immutable_seal', 'guarded_extend', 'override_allowed', 'weighted_mix', 'exclusion_deny')),
    disclosure_mode text NOT NULL CHECK (disclosure_mode IN ('verbatim_allowed', 'derived_only', 'summary_only', 'metadata_only', 'index_only')),
    definition_json jsonb NOT NULL,
    node_digest kk_digest NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (tenant_id, id),
    UNIQUE (tenant_id, node_digest),
    CONSTRAINT chk_layer_trust_delivery CHECK (
        (trust_tier = 'trusted_directive' AND delivery_mode IN ('pinned_prefix', 'session_pinned'))
        OR (trust_tier = 'curated_reference' AND delivery_mode IN ('pinned_prefix', 'session_pinned', 'hybrid_retrievable'))
        OR (trust_tier = 'untrusted_evidence' AND delivery_mode IN ('hybrid_retrievable', 'tool_discoverable', 'volatile_suffix'))
        OR (trust_tier = 'dynamic_overlay' AND delivery_mode = 'volatile_suffix')
    )
);

CREATE TRIGGER trg_layer_definitions_immutable
    BEFORE UPDATE OR DELETE ON layer_definitions
    FOR EACH ROW EXECUTE FUNCTION kk_immutable();

-- 3. Immutable layer stacks
CREATE TABLE layer_stacks (
    tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    id kk_ulid NOT NULL,
    stack_json jsonb NOT NULL,
    static_prefix_digest kk_digest NOT NULL,
    session_prefix_digest kk_digest NOT NULL,
    stack_digest kk_digest NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (tenant_id, id),
    UNIQUE (tenant_id, stack_digest)
);

CREATE TRIGGER trg_layer_stacks_immutable
    BEFORE UPDATE OR DELETE ON layer_stacks
    FOR EACH ROW EXECUTE FUNCTION kk_immutable();

-- 4. Unidirectional read-only shared catalog mounts (ARCH-CATALOG-MOUNT)
-- Documented SchemaTest.php exception: cross-tenant FK
-- FOREIGN KEY (catalog_tenant_id, catalog_kb_id, snapshot_id) REFERENCES publication_snapshots(tenant_id, kb_id, id).
CREATE TABLE catalog_mounts (
    tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    id kk_ulid NOT NULL,
    catalog_tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    catalog_kb_id kk_ulid NOT NULL,
    snapshot_id kk_ulid NOT NULL,
    manifest_digest kk_digest NOT NULL,
    mount_grant_digest kk_digest NOT NULL,
    max_disclosure_mode text NOT NULL CHECK (max_disclosure_mode IN ('verbatim_allowed', 'derived_only', 'summary_only', 'metadata_only', 'index_only')),
    state text NOT NULL CHECK (state IN ('active', 'revoked')),
    mounted_at timestamptz NOT NULL,
    revoked_at timestamptz NULL,
    PRIMARY KEY (tenant_id, id),
    CHECK (tenant_id <> catalog_tenant_id),
    CHECK ((state = 'active' AND revoked_at IS NULL) OR (state = 'revoked' AND revoked_at IS NOT NULL)),
    FOREIGN KEY (catalog_tenant_id, catalog_kb_id, snapshot_id)
        REFERENCES publication_snapshots(tenant_id, kb_id, id) ON DELETE RESTRICT
);

CREATE INDEX idx_catalog_mounts_tenant_state ON catalog_mounts (tenant_id, state, catalog_tenant_id);

CREATE OR REPLACE FUNCTION kk_catalog_mount_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_catalog_kind text;
    v_sealed boolean;
    v_manifest_digest kk_digest;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.state = 'revoked' THEN
            RAISE EXCEPTION 'revoked catalog_mount cannot be reactivated or mutated' USING ERRCODE = '23514';
        END IF;
        IF NEW.tenant_id <> OLD.tenant_id
           OR NEW.id <> OLD.id
           OR NEW.catalog_tenant_id <> OLD.catalog_tenant_id
           OR NEW.catalog_kb_id <> OLD.catalog_kb_id
           OR NEW.snapshot_id <> OLD.snapshot_id
           OR NEW.manifest_digest <> OLD.manifest_digest
           OR NEW.mount_grant_digest <> OLD.mount_grant_digest
           OR NEW.max_disclosure_mode <> OLD.max_disclosure_mode
           OR NEW.mounted_at <> OLD.mounted_at
           OR NEW.state <> 'revoked' THEN
            RAISE EXCEPTION 'catalog_mount only permits state transition active -> revoked' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    SELECT isolation_kind INTO v_catalog_kind
    FROM tenants WHERE id = NEW.catalog_tenant_id;

    IF v_catalog_kind IS DISTINCT FROM 'shared_catalog_readonly' THEN
        RAISE EXCEPTION 'catalog_mount_forbidden: catalog tenant must be shared_catalog_readonly' USING ERRCODE = '23514';
    END IF;

    SELECT sealed, manifest_digest INTO v_sealed, v_manifest_digest
    FROM publication_snapshots
    WHERE tenant_id = NEW.catalog_tenant_id
      AND kb_id = NEW.catalog_kb_id
      AND id = NEW.snapshot_id;

    IF v_sealed IS DISTINCT FROM TRUE THEN
        RAISE EXCEPTION 'catalog_mount_forbidden: publication snapshot must be sealed' USING ERRCODE = '23514';
    END IF;

    IF v_manifest_digest IS DISTINCT FROM NEW.manifest_digest THEN
        RAISE EXCEPTION 'catalog_mount_forbidden: snapshot manifest_digest mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_catalog_mounts_guard
    BEFORE INSERT OR UPDATE ON catalog_mounts
    FOR EACH ROW EXECUTE FUNCTION kk_catalog_mount_guard();

CREATE TRIGGER trg_catalog_mounts_no_delete
    BEFORE DELETE ON catalog_mounts
    FOR EACH ROW EXECUTE FUNCTION kk_immutable();

-- 5. Multi-resolution artifact pyramid R0-R2 (ARCH-MULTI-RES)
CREATE TABLE resolution_manifests (
    tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    id kk_ulid NOT NULL,
    document_revision_id kk_ulid NOT NULL,
    processing_digest kk_digest NOT NULL,
    manifest_json jsonb NOT NULL,
    manifest_digest kk_digest NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (tenant_id, id),
    UNIQUE (tenant_id, document_revision_id, processing_digest),
    FOREIGN KEY (tenant_id, document_revision_id)
        REFERENCES document_revisions(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER trg_resolution_manifests_immutable
    BEFORE UPDATE OR DELETE ON resolution_manifests
    FOR EACH ROW EXECUTE FUNCTION kk_immutable();

CREATE TABLE resolution_artifacts (
    tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    id kk_ulid NOT NULL,
    manifest_id kk_ulid NOT NULL,
    document_revision_id kk_ulid NOT NULL,
    tier text NOT NULL CHECK (tier IN ('R0', 'R1', 'R2')),
    blob_digest kk_digest NOT NULL,
    byte_length integer NOT NULL CHECK (byte_length > 0),
    token_count integer NOT NULL CHECK (token_count > 0),
    tokenizer_id text NOT NULL,
    origin text NOT NULL CHECK (origin IN ('source', 'ocr', 'generated', 'deterministic')),
    provenance_kind text NOT NULL,
    dependency_node_id kk_ulid NOT NULL,
    protected_atom_count integer NOT NULL CHECK (protected_atom_count >= 0),
    created_at timestamptz NOT NULL,
    PRIMARY KEY (tenant_id, id),
    UNIQUE (tenant_id, manifest_id, tier),
    FOREIGN KEY (tenant_id, manifest_id)
        REFERENCES resolution_manifests(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, document_revision_id)
        REFERENCES document_revisions(tenant_id, id) ON DELETE RESTRICT
);

CREATE INDEX idx_resolution_artifacts_rev_tier ON resolution_artifacts (tenant_id, document_revision_id, tier);

CREATE TRIGGER trg_resolution_artifacts_immutable
    BEFORE UPDATE OR DELETE ON resolution_artifacts
    FOR EACH ROW EXECUTE FUNCTION kk_immutable();

-- 6. Concept graph edges (bound to publication snapshot; immutable once sealed = true)
CREATE TABLE concept_edges (
    tenant_id kk_ulid NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    kb_id kk_ulid NOT NULL,
    snapshot_id kk_ulid NOT NULL,
    from_chunk_id kk_ulid NOT NULL,
    to_chunk_id kk_ulid NOT NULL,
    relation text NOT NULL CHECK (relation IN ('prerequisite_of', 'defines', 'proves', 'exemplifies', 'refines', 'contradicts', 'cites', 'applies_to')),
    weight_bps integer NOT NULL CHECK (weight_bps BETWEEN 1 AND 10000),
    origin text NOT NULL CHECK (origin IN ('source', 'ocr', 'generated', 'deterministic')),
    PRIMARY KEY (tenant_id, kb_id, snapshot_id, from_chunk_id, relation, to_chunk_id),
    CHECK (from_chunk_id <> to_chunk_id),
    FOREIGN KEY (tenant_id, kb_id, snapshot_id)
        REFERENCES publication_snapshots(tenant_id, kb_id, id) ON DELETE RESTRICT
);

CREATE INDEX idx_concept_edges_from ON concept_edges (tenant_id, kb_id, snapshot_id, from_chunk_id, weight_bps DESC);
CREATE INDEX idx_concept_edges_to ON concept_edges (tenant_id, kb_id, snapshot_id, to_chunk_id);

CREATE OR REPLACE FUNCTION kk_concept_edge_sealed_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_sealed boolean;
    v_t kk_ulid;
    v_kb kk_ulid;
    v_snap kk_ulid;
BEGIN
    v_t := COALESCE(NEW.tenant_id, OLD.tenant_id);
    v_kb := COALESCE(NEW.kb_id, OLD.kb_id);
    v_snap := COALESCE(NEW.snapshot_id, OLD.snapshot_id);
    SELECT sealed INTO v_sealed
    FROM publication_snapshots
    WHERE tenant_id = v_t AND kb_id = v_kb AND id = v_snap;
    IF v_sealed IS TRUE THEN
        RAISE EXCEPTION 'concept_edges cannot be mutated after publication_snapshot is sealed' USING ERRCODE = '23514';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$;

CREATE TRIGGER trg_concept_edges_sealed_guard
    BEFORE INSERT OR UPDATE OR DELETE ON concept_edges
    FOR EACH ROW EXECUTE FUNCTION kk_concept_edge_sealed_guard();

-- 7. Dynamic search_path pinning for all kk_* functions in the owning schema
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
