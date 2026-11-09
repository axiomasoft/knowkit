-- 001 added both a legacy 4 MiB column-specific guard and the shared 16 MiB
-- JSONB guard. The approved index-manifest schema permits 16 MiB. Remove only
-- the obsolete smaller guard; index_generations_manifest_json_check1 still
-- enforces 16 MiB. Keep every deployed migration checksum unchanged.
ALTER TABLE index_generations
    DROP CONSTRAINT index_generations_manifest_json_check;
