-- Preserve the deployed 001 checksum and immutable rows while widening uint32 storage.
-- physicalNo is one-based; readingOrder is zero-based. Both retain the wire uint32 cap.
ALTER TABLE pages
  ALTER COLUMN physical_no TYPE bigint,
  ADD CONSTRAINT pages_physical_no_uint32 CHECK (physical_no BETWEEN 1 AND 4294967295);

ALTER TABLE blocks
  ALTER COLUMN reading_order TYPE bigint,
  ADD CONSTRAINT blocks_reading_order_uint32 CHECK (reading_order BETWEEN 0 AND 4294967295);
