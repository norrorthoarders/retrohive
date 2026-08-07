-- "Public" is always read-only to join now - a library open to
-- everybody granted Contributor on join as well as Viewer used to be a
-- real, selectable state; it no longer is. A higher role for a
-- specific person still goes through inviting them directly, which
-- this does not touch - only public_write, which no longer has any
-- code path that can set it, is cleared here so an instance upgraded
-- from before this change does not keep carrying a state nothing can
-- select or reproduce again.

UPDATE libraries SET public_write = 0 WHERE public_write = 1;
