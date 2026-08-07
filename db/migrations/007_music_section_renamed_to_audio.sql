-- Renamed from "Music" so a future release can file more than music
-- under this section without the name itself being wrong - audiobooks,
-- podcasts, radio recordings are all audio and none of them are music.
-- The slug changes too, not just the label: every domain check
-- throughout the codebase compares against the slug, and this
-- migration exists alongside every one of those checks being updated
-- in the same release, not ahead of it or behind it.
--
-- Existing categories, items, and everything else that files under
-- this section reference it by id, not by slug, so nothing about what
-- they hold changes - only the row's own two columns do.

UPDATE sections SET slug = 'audio', name = 'Audio' WHERE slug = 'music';

-- Three SET columns declare which domains something belongs to, the
-- same way the section itself just did: a platform's own domains, a
-- company's own makes, a credit role's own domains. Renaming a SET
-- value safely means widening first - narrowing straight to the new
-- definition would silently drop 'music' from any row holding it
-- before the data below had a chance to move to 'audio' instead.

ALTER TABLE platforms
  MODIFY COLUMN domains SET('hardware','software','video','music','audio')
  NOT NULL DEFAULT 'hardware,software';
UPDATE platforms SET domains = REPLACE(domains, 'music', 'audio') WHERE FIND_IN_SET('music', domains);
ALTER TABLE platforms
  MODIFY COLUMN domains SET('hardware','software','video','audio')
  NOT NULL DEFAULT 'hardware,software';

ALTER TABLE companies
  MODIFY COLUMN makes SET('hardware','software','video','music','audio')
  NOT NULL DEFAULT 'software';
UPDATE companies SET makes = REPLACE(makes, 'music', 'audio') WHERE FIND_IN_SET('music', makes);
ALTER TABLE companies
  MODIFY COLUMN makes SET('hardware','software','video','audio')
  NOT NULL DEFAULT 'software';

ALTER TABLE credit_roles
  MODIFY COLUMN domains SET('hardware','software','video','music','audio')
  NOT NULL DEFAULT 'software';
UPDATE credit_roles SET domains = REPLACE(domains, 'music', 'audio') WHERE FIND_IN_SET('music', domains);
ALTER TABLE credit_roles
  MODIFY COLUMN domains SET('hardware','software','video','audio')
  NOT NULL DEFAULT 'software';
