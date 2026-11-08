-- P9.3: ordinary writers already hold the tenant SHARE guard before KB/source/job.
-- A trigger must not upgrade that early guard after subordinate locks: concurrent
-- enqueue/checkpoint writers could otherwise deadlock. SHARE stabilizes the restore
-- header against start/advance's tenant UPDATE barrier for the whole transaction.
CREATE OR REPLACE FUNCTION kk_recovery_freeze() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t kk_ulid; r kk_ulid;
BEGIN
  t=CASE WHEN TG_OP='DELETE' THEN OLD.tenant_id ELSE NEW.tenant_id END;
  SELECT reconciliation_run_id INTO r FROM tenants WHERE id=t FOR SHARE;
  IF r IS NOT NULL AND EXISTS (SELECT 1 FROM reconciliation_runs WHERE tenant_id=t AND id=r AND stage<>'ready')
     AND current_setting('knowkit.recovery_run',true) IS DISTINCT FROM r::text THEN
    RAISE EXCEPTION 'restore isolated' USING ERRCODE='23514'; END IF;
  IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
ALTER FUNCTION kk_recovery_freeze() SET search_path FROM CURRENT;
