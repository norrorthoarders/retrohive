-- Which shipped picture a branch's things get, as declared by the template
-- feed rather than by somebody clicking Upload.
--
-- Deliberately a second column and not a reuse of default_image_filename.
-- That one holds a deliberate act by whoever curates the library, and this one
-- holds an answer the structure feed brings in and rewrites on every import -
-- putting both in one column would mean either the import silently overwriting
-- a curator's choice, or the curator's choice being indistinguishable from a
-- default and so impossible to preserve. Two columns, and the curator's wins.
--
-- Holds a bare slug from stock_images(), not a `stock:` reference and not a
-- filename: the feed names a picture, and the engine decides where it is served
-- from. A slug naming nothing in the catalogue resolves to null and is ignored,
-- so a feed that gets ahead of a release does no harm.
--
-- Inherited down the branch when unset, the same walk category_role and
-- default_image_filename already use.

ALTER TABLE categories
  ADD COLUMN IF NOT EXISTS stock_image VARCHAR(80) DEFAULT NULL AFTER default_image_filename;
