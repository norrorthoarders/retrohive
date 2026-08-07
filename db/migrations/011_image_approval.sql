-- Pictures that wait for somebody to say yes.
--
-- Two features, one shape: an image somebody uploaded that nobody else should
-- see until it is approved. They are kept apart because the two answers are
-- different - who approves an avatar is an instance administrator, who approves
-- a library's photographs is whoever curates that library - but the storage
-- rule is the same both times, and worth stating once here.
--
-- Nothing is hidden by default. Both switches are off, both columns default to
-- the permissive value, and an instance that never turns either on behaves
-- exactly as it did.

-- ---------------------------------------------------------------------------
-- Avatars.
--
-- A second column rather than a state on the first, because the first *is* what
-- is shown. Holding a pending picture there would mean either showing it (which
-- is the thing being prevented) or teaching every reader of avatar_filename to
-- check a flag - and there are readers in the API, the engine's own screens and
-- two clients. One column stays "the avatar", the other is "the one waiting",
-- and nothing that draws a face has to know this feature exists.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS avatar_pending_filename VARCHAR(255) DEFAULT NULL AFTER avatar_filename,
  ADD COLUMN IF NOT EXISTS avatar_pending_at DATETIME DEFAULT NULL AFTER avatar_pending_filename;

-- ---------------------------------------------------------------------------
-- Library photographs.
--
-- Here a state on the row is right, because unlike an avatar an entry has many
-- pictures and a pending one is a new row rather than a replacement for an
-- existing one. Defaulting to 'approved' means every picture already on the
-- instance stays visible, and so does every picture a metadata agent writes -
-- agents are not people and their artwork is not somebody's snapshot.
ALTER TABLE item_images
  ADD COLUMN IF NOT EXISTS approval_state ENUM('approved','pending') NOT NULL DEFAULT 'approved' AFTER provenance,
  ADD COLUMN IF NOT EXISTS uploaded_by INT UNSIGNED DEFAULT NULL AFTER approval_state,
  ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED DEFAULT NULL AFTER uploaded_by,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL AFTER approved_by;

-- Asked as "what is waiting on this library", which is a scan of one library's
-- pending rows and nothing else.
ALTER TABLE item_images
  ADD KEY IF NOT EXISTS idx_images_approval (approval_state, item_id);

-- ---------------------------------------------------------------------------
-- Whether a library asks at all. Per library, because one shared shelf with
-- contributors wanting review and one private shelf wanting none is the
-- ordinary case, not an exotic one.
ALTER TABLE libraries
  ADD COLUMN IF NOT EXISTS photo_approval TINYINT(1) NOT NULL DEFAULT 0;
