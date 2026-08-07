-- A place for a non-hardware copy's own label/value spec rows - a genre, a
-- director, a running time, a record label's catalogue number - the same
-- shape item_hardware.specs already uses, on the items table itself so
-- every domain has somewhere real to keep one. Hardware keeps its own
-- dedicated column rather than sharing this one; see db/schema.sql for
-- the full reasoning.

ALTER TABLE items
  ADD COLUMN IF NOT EXISTS specs JSON DEFAULT NULL AFTER region;
