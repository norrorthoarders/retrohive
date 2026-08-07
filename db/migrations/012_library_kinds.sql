-- Two kinds of library, named for what they mean.
--
-- `shared` said how a library is used; `public` says who may reach it, which is
-- the question the column actually decides. The pair was also not really a pair:
-- a library could be `shared` and still members-only, because `public_read` was
-- a separate switch beside it - so "shared" meant nothing on its own and the
-- screen offering it was asking a question with no consequence.
--
-- Now:
--   private  explicit invitation only
--   public   any signed-in account may join, and gets read access by doing so
--
-- The rename is a two-step ALTER on purpose. Adding 'public' to the ENUM before
-- rewriting the rows means every row is valid at every moment; renaming the
-- value in place would have made every existing row invalid for the instant
-- between the two statements, and MySQL resolves that by silently writing ''.

ALTER TABLE libraries
  MODIFY kind ENUM('private','shared','public') NOT NULL DEFAULT 'private';

UPDATE libraries SET kind = 'public' WHERE kind = 'shared';

-- `public_read` is what `kind` now says, so the two are brought into line rather
-- than left to disagree. A library that was shared-but-private becomes private,
-- which is what it already behaved as.
UPDATE libraries SET kind = 'private' WHERE kind = 'public' AND public_read = 0 AND public_write = 0;
UPDATE libraries SET public_read = 1 WHERE kind = 'public';
UPDATE libraries SET public_read = 0, public_write = 0 WHERE kind = 'private';

-- Joining a public library grants read access and nothing more; a higher level
-- is something somebody is invited to, not something taken by arriving.
UPDATE libraries SET public_write = 0;

ALTER TABLE libraries
  MODIFY kind ENUM('private','public') NOT NULL DEFAULT 'private';
