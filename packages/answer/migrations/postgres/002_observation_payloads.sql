-- Grounded flow persists exact egress receipts and provider observations before settlement.
-- Extend the immutable payload inventory without rewriting the installed answer:001 checksum.
ALTER TABLE knowkit_answer_payloads DROP CONSTRAINT knowkit_answer_payloads_kind_check;
ALTER TABLE knowkit_answer_payloads ADD CONSTRAINT knowkit_answer_payloads_kind_check
  CHECK (kind IN ('pins','request','call','response','observation','egress-check'));
