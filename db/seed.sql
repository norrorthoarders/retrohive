-- RetroVault :: core taxonomy
-- Safe to re-run: every insert is INSERT IGNORE keyed on slug.

SET NAMES utf8mb4;

-- Libraries -----------------------------------------------------------------
-- What kind of machine a platform holds is no longer recorded on the platform:
-- it is read from the machine models filed under it, which carry the same fact
-- and are the ones that decide.


-- Hardware makers. Kept apart from `companies`, which is studios and
-- publishers: Commodore made machines and never published a game, and putting
-- them in one list makes both harder to read.

-- Everything else that used to be here is structure data, and moved to
-- structure/*.json - by way of db/seed-templates.sql for a while in between,
-- itself since removed once nothing loaded it any more. The line is whether
-- removing it breaks the software or merely leaves the catalogue empty: auth
-- methods and platform classes are structure, and a Commodore Amiga is a fact
-- about the world.
--
-- The installer offers the structure data as a choice, and an instance that
-- declines it starts genuinely empty rather than pre-filled with somebody
-- else's idea of a collection.
