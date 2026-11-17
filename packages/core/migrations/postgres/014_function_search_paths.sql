-- PostgreSQL implicitly searches temporary relations first unless pg_temp is
-- explicitly positioned. Pin every core guard to its owning catalog so a
-- runtime connection's temporary tables cannot hide tombstones or sealed state.
DO $$
DECLARE f record;
BEGIN
  FOR f IN
    SELECT p.oid::regprocedure AS signature, n.nspname AS schema_name
    FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
    WHERE n.nspname=current_schema() AND left(p.proname,3)='kk_'
  LOOP
    EXECUTE format('ALTER FUNCTION %s SET search_path TO pg_catalog,%I,pg_temp',
                   f.signature, f.schema_name);
  END LOOP;
END $$;
