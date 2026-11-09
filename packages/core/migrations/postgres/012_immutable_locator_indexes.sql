-- Keep the exact tenant/document/block and generation/source identity predicates
-- used by kk_exact_spans and kk_snapshot_member indexed at catalog scale.
-- Non-unique indexes preserve every existing constraint and guard function.
CREATE INDEX blocks_document_locator
    ON blocks (tenant_id, document_id, local_block_id);

CREATE INDEX index_memberships_source_locator
    ON index_memberships (tenant_id, generation_id, source_version_id, revision_id, chunk_set_id);
