-- Replace platforms.machine_class with platforms.domains, a direct set of
-- the sections a platform participates in - the same shape companies.makes
-- already used. machine_class only ever existed to look up a fixed
-- class-to-sections table (computer/console/handheld -> hardware+software,
-- video-format -> video, audio-format -> music); this makes that mapping
-- the data itself rather than an indirect lookup.
--
-- Safe to re-run: ADD COLUMN IF NOT EXISTS, and the backfill only touches
-- rows still at the column's default, so running this twice does not
-- overwrite a domains value already set by hand or by a re-run.

ALTER TABLE platforms
  ADD COLUMN IF NOT EXISTS domains SET('hardware','software','video','music')
    NOT NULL DEFAULT 'hardware,software' AFTER machine_class;

UPDATE platforms
   SET domains = CASE machine_class
                   WHEN 'video-format' THEN 'video'
                   WHEN 'audio-format' THEN 'music'
                   ELSE 'hardware,software'
                 END
 WHERE domains = 'hardware,software';

ALTER TABLE platforms DROP COLUMN IF EXISTS machine_class;
