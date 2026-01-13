-- Dedupe script for `penulis` table
-- WARNING: Review before running. This script keeps the smallest id for each normalized name and removes other duplicates.

-- Create a backup table first (recommended)
CREATE TABLE IF NOT EXISTS penulis_backup AS SELECT * FROM penulis;

-- Add a normalized name temporary column (if not exists)
ALTER TABLE penulis ADD COLUMN IF NOT EXISTS nama_norm VARCHAR(255);

-- Populate normalized names
UPDATE penulis SET nama_norm = LOWER(TRIM(REGEXP_REPLACE(nama_penulis, '\\s+', ' ')));

-- Find duplicates (names with multiple rows)
-- The following deletes all rows that are duplicates keeping the one with smallest id_penulis

DELETE p FROM penulis p
JOIN (
  SELECT nama_norm, MIN(id_penulis) as keep_id
  FROM penulis
  GROUP BY nama_norm
  HAVING COUNT(*) > 1
) dup ON dup.nama_norm = p.nama_norm
WHERE p.id_penulis != dup.keep_id;

-- Optional: drop the helper column if you don't need it
-- ALTER TABLE penulis DROP COLUMN nama_norm;

-- Notes:
-- 1) Test on a copy of the database first.
-- 2) This script assumes MySQL 8+ for REGEXP_REPLACE. For older versions replace with appropriate logic.
-- 3) After dedupe, you can add a UNIQUE index on normalized name to prevent future duplicates:
-- ALTER TABLE penulis ADD UNIQUE KEY uniq_penulis_nama_norm (nama_norm);
