-- Apply in the same catalog schema as 002. Preserve the existing migration's
-- bytes for deployed installations, and bind its trigger to this exact schema.
DO $$
BEGIN
  EXECUTE format('ALTER FUNCTION %I.kk_vector_staging_guard() SET search_path TO pg_catalog,%I,pg_temp',
                 current_schema(), current_schema());
END $$;
