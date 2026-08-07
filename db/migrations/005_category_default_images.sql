-- What an entry filed under a category looks like with no photograph of
-- its own - a generic VHS clamshell, a boxed Amiga disk, whatever this
-- branch's own things generally come in. Inherited down the branch when
-- unset, the same walk-up-the-tree category_role already does for kind.
-- See db/schema.sql for the full reasoning.

ALTER TABLE categories
  ADD COLUMN IF NOT EXISTS default_image_filename VARCHAR(255) DEFAULT NULL AFTER description;
