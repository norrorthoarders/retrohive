-- RetroVault :: schema
-- MariaDB 10.6+ / MySQL 8+
-- Charset utf8mb4 throughout so Scandinavian and Japanese titles survive.
--
-- Tables are declared in dependency order. There is deliberately no
-- SET FOREIGN_KEY_CHECKS = 0 wrapper: needing one is the signal that the order
-- is wrong, and with checks off a constraint against a table that does not
-- exist yet is accepted and then never validated.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Applied migrations. A fresh install of this file already contains everything
-- the migrations would add, so the installer records them rather than running
-- them. See src/migrate.php.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  migration   VARCHAR(190) NOT NULL,
  checksum    CHAR(64)     DEFAULT NULL,
  applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  duration_ms INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Authentication backends
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_methods (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type         ENUM('local','ldap','ad') NOT NULL DEFAULT 'local',
  name         VARCHAR(120) NOT NULL,
  description  VARCHAR(255) DEFAULT NULL,
  params       TEXT         DEFAULT NULL,   -- JSON
  is_enabled   TINYINT(1)   NOT NULL DEFAULT 1,
  is_protected TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_methods_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO auth_methods (id, type, name, description, is_enabled, is_protected, sort_order)
VALUES (1, 'local', 'Local database', 'Passwords stored and verified by RetroVault itself.', 1, 1, 0);

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(64)  NOT NULL,
  -- Which backend authenticates this account. 1 is always the local database.
  auth_method_id INT UNSIGNED NOT NULL DEFAULT 1,
  -- NULL for directory accounts: they have no local password at all.
  password_hash VARCHAR(255) DEFAULT NULL,
  display_name  VARCHAR(120) DEFAULT NULL,
  avatar_filename VARCHAR(255) DEFAULT NULL,
  -- A new avatar waiting for an administrator, when the instance asks for that.
  --
  -- A second column rather than a state on the one above, because that one *is*
  -- what is shown. Holding a pending picture there would mean either showing it -
  -- the thing being prevented - or teaching every reader of avatar_filename to
  -- check a flag, and there are readers in the API, this application's own
  -- screens and two clients. One column stays "the avatar" and nothing that
  -- draws a face has to know this feature exists.
  avatar_pending_filename VARCHAR(255) DEFAULT NULL,
  avatar_pending_at DATETIME DEFAULT NULL,
  -- Required when an account is registered, and nullable here on purpose:
  -- directory accounts are provisioned rather than registered, and a directory
  -- without a mail attribute would otherwise be unable to sign anybody in.
  -- Every path that creates a local account demands one.
  email         VARCHAR(190) DEFAULT NULL,
  -- An administrator's switch for one account, above that person's own
  -- preferences: for when an address bounces and nobody can reach them to ask.
  mail_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
  -- When the address was proven to belong to them. NULL means it has not been.
  --
  -- Whether that matters is a setting: an instance with no mail relay cannot
  -- verify anybody, so requiring it is off until one is configured. An
  -- administrator can also mark somebody verified by hand, which is the way out
  -- when a relay breaks or an address is unreachable for reasons nobody can fix
  -- from here.
  email_verified_at DATETIME DEFAULT NULL,
  -- SHA-256 of the token that was emailed, never the token itself: a stolen
  -- database should not hand out working links.
  verify_token      CHAR(64) DEFAULT NULL,
  verify_sent_at    DATETIME DEFAULT NULL,
  -- Directory bookkeeping, all NULL for local accounts.
  external_dn     VARCHAR(512) DEFAULT NULL,
  external_uid    VARCHAR(190) DEFAULT NULL,
  external_groups TEXT         DEFAULT NULL,   -- JSON array, last seen
  auto_created    TINYINT(1)   NOT NULL DEFAULT 0,
  last_sync_at    DATETIME     DEFAULT NULL,
  -- Instance role only: may this account configure the system? What it can
  -- reach is decided per library, in library_members. There are exactly two
  -- values and no third is coming; anything else is the old model talking.
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  -- Not unique: NULL never collides, so directory accounts without an address
  -- are unaffected, and two people genuinely sharing a mailbox is their
  -- business. Uniqueness is checked when registering, where it can explain
  -- itself.
  KEY idx_users_email (email),
  KEY idx_users_verify (verify_token),
  KEY idx_users_auth_method (auth_method_id),
  KEY idx_users_external_uid (external_uid),
  CONSTRAINT fk_users_auth_method FOREIGN KEY (auth_method_id)
    REFERENCES auth_methods (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Directory group -> role. The per-library grants live in
-- auth_group_library_access, one row per library, because a group that maps to
-- three libraries at two different levels cannot be said in one column.
CREATE TABLE IF NOT EXISTS auth_group_map (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  auth_method_id         INT UNSIGNED NOT NULL,
  group_name             VARCHAR(512) NOT NULL,   -- full DN or bare CN
  role                   ENUM('admin','user') NOT NULL DEFAULT 'user',
  -- Applied to every library the group does not name explicitly. 'none' makes
  -- the explicit grants an allow-list, which is the usual intent.
  default_access         ENUM('none','viewer','contributor','editor','curator','admin') NOT NULL DEFAULT 'none',
  priority               INT          NOT NULL DEFAULT 100,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group_map_method (auth_method_id),
  CONSTRAINT fk_group_map_method FOREIGN KEY (auth_method_id)
    REFERENCES auth_methods (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(190) NOT NULL,
  auth_method_id INT UNSIGNED DEFAULT NULL,
  succeeded      TINYINT(1)   NOT NULL DEFAULT 0,
  reason         VARCHAR(190) DEFAULT NULL,
  client_ip      VARCHAR(45)  DEFAULT NULL,
  user_agent     VARCHAR(255) DEFAULT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_auth_log_when (created_at),
  KEY idx_auth_log_user (username, created_at),
  -- The throttle counts failures per address inside a window; without this it
  -- scans the whole log on every sign-in attempt.
  KEY idx_auth_log_ip (client_ip, succeeded, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- What kind of machine a platform is: a console, a computer, a handheld.
--
-- ---------------------------------------------------------------------------
-- Platforms: the machine or format. Amiga, C64, MSX, DOS ...
--
-- A platform is not an access boundary and never has been. It says what an
-- entry runs on. Who may see it is decided by its library.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS platforms (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Whose it is. NULL means everybody's - the seeded machines, which every
  -- library can use and only an administrator can change.
  --
  -- Set means a library defined it for itself. Somebody cataloguing a machine
  -- nobody has heard of should not have to ask an administrator to add it
  -- first, and their Sharp MZ-2500 has no business appearing in a stranger's
  -- platform list either.
  library_id      INT UNSIGNED DEFAULT NULL,
  -- What kind of machine this is, when nothing else says.
  --
  -- Not a second source of truth: where the platform has machine models, they decide,
  -- because they are the thing that actually knows. This is the answer for the sixty
  -- platforms nobody has modelled yet, so their category trees can still be built
  -- correctly rather than all defaulting to 'computer'.
  --
  -- Replaced a single machine_class string (computer/console/handheld/video-format/
  -- audio-format) with a direct set of the sections a platform participates in -
  -- the same shape companies.makes already used. machine_class was a name for
  -- which machines exist, not what this column was actually for; it only ever
  -- existed to look up a fixed class-to-sections table. A platform now just says
  -- the sections directly, the same way a company already does.
  domains SET('hardware','software','video','audio') NOT NULL DEFAULT 'hardware,software',
  name            VARCHAR(120) NOT NULL,
  slug            VARCHAR(140) NOT NULL,
  -- Who made it, as a row rather than a word typed again. Nullable because a
  -- template may name a maker that has not been copied yet, and because "MSX"
  -- has no single manufacturer worth pretending about.
  vendor_id       INT UNSIGNED DEFAULT NULL,
  year_introduced SMALLINT UNSIGNED DEFAULT NULL,
  accent_color    CHAR(7)      NOT NULL DEFAULT '#cba6f7',
  description     TEXT         DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Per library, for the same reason vendors are: two collections may both
  -- catalogue an Amiga. Generated, so the template rows are constrained too.
  library_key     INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  UNIQUE KEY uq_platforms_slug (library_key, slug),
  -- Ordered by name, so the indexes lead on name rather than on a column
  -- nobody ever set.
  KEY idx_platforms_name (name),
  KEY idx_platforms_library (library_id, name),
  KEY idx_platforms_vendor (vendor_id, name)
  -- The constraint on library_id is added after `libraries` exists, further
  -- down. Platforms are declared first because most of the schema depends on
  -- them, and a library is a much later idea.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Sections: the top-level split - Hardware, Software, Video, Music.
--
-- Real rows, not an ENUM. The distinction used to be `categories.domain
-- ENUM('software','hardware')`, which meant a third kind needed a migration
-- before anybody could catalogue a single VHS tape. A future section - Books,
-- Board Games, whatever comes next - is now an INSERT, the same way a new
-- platform or company already is.
--
-- `platform_label` exists because the axis below a section means two related
-- but different things: "which computer" for Hardware and Software, "which
-- physical format" for Video and Music. The underlying mechanism - one shared
-- `platforms` table, one `platform_id` column on every item - does not change
-- between them; only the word a person reads does. A VHS tape does not have
-- a "Platform", it has a "Format", and this is where that label lives rather
-- than being hardcoded somewhere a fifth section could not reach.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sections (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug           VARCHAR(40)  NOT NULL,
  name           VARCHAR(80)  NOT NULL,
  platform_label VARCHAR(40)  NOT NULL DEFAULT 'Platform',
  sort_order     INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sections_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sections (slug, name, platform_label, sort_order) VALUES
  ('hardware', 'Hardware', 'Platform', 10),
  ('software', 'Software', 'Platform', 20),
  ('video',    'Video',    'Format',   30),
  -- Renamed from "Music" so a future release can file more than music
  -- under this section without the name itself being wrong - audiobooks,
  -- podcasts, radio recordings are all audio and none of them are music.
  -- The slug changed too, not just the label: every domain check
  -- throughout this codebase compares against the slug, and a section
  -- that reads "Audio" while still answering to 'music' internally is
  -- exactly the kind of mismatch that becomes a bug report later.
  ('audio',    'Audio',    'Format',   40);

-- ---------------------------------------------------------------------------
-- Categories: the filing tree, and the single place a section's own
-- vocabulary is recorded - Games and Applications under Software, Movies and
-- TV Shows under Video, and so on.
--
-- Everything else derives `domain` from here, by way of `section_id` - the
-- view exposes it, the routes read it, the form reads it. Section used to be
-- stated as a domain ENUM directly on this table, which is one fewer join but
-- meant the same four-places-to-disagree problem this comment used to warn
-- about, now one level up: a section had to be one of exactly two hardcoded
-- values everywhere it was checked, rather than a row anything could look up.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Whose taxonomy this is, exactly as platforms, vendors and hardware_models do it.
  -- NULL marks a template row: the seeded kinds, never filed under directly, existing
  -- only to be copied into a library when one is made.
  --
  -- Categories were the last shared thing. That was tolerable while the tree was one
  -- flat taxonomy, and stops being so the moment a platform is a branch in it: a
  -- platform belongs to a library, so the same path in two libraries has to be two
  -- sets of rows, or renaming one renames both.
  --
  -- The constraint on library_id is added after `libraries` exists, further down;
  -- this table is declared before that one.
  library_id  INT UNSIGNED DEFAULT NULL,
  -- Hardware does not offer "Demo"; software does not offer "Monitor". Which
  -- top-level section this branch belongs to - Hardware, Software, Video,
  -- Music, or whatever gets added after them. A real foreign key rather than
  -- the ENUM this used to be, so a new section is a row in `sections` and not
  -- a migration; see the comment there for why.
  section_id  INT UNSIGNED NOT NULL,
  -- Within hardware: is a thing filed here the machine itself, or something
  -- that goes with one?
  --
  --   machine     a console, a computer, a handheld - the thing that has a
  --               serial number and sits on the shelf
  --   peripheral  a gamepad, a graphics card, an accelerator - catalogued on
  --               its own and fitted to at most one machine at a time
  --   other       cables, blank media, spares: real things, but nothing is
  --               fitted to them and they are not fitted to anything
  --
  -- This used to be a hardcoded list of three slugs in PHP, so the answer
  -- lived in code while the tree it described lived in the database.
  -- What kind of thing this branch holds. Machines and peripherals on the
  -- hardware side, games and applications on the software side, movies and
  -- TV shows on the video side, music on the music side - said outright
  -- rather than inferred from a branch happening to be called "Games".
  role        ENUM('machine','peripheral','game','application','movie','tv_show','music','other') NOT NULL DEFAULT 'other',
  -- Which machines this kind belongs on, as a comma-separated list of classes:
  -- computer, console, handheld. Empty means all of them.
  --
  -- Only meaningful on template rows. A library's trees are built per platform, and
  -- this is what stops a Game Boy branch acquiring disk controllers and antivirus
  -- tools - it got a verbatim copy of one tree before, which is nonsense on a machine
  -- with no disks and no filesystem.
  applies_to  VARCHAR(60) NOT NULL DEFAULT '',
  -- A tree: Hardware > Amiga > Peripherals > Adapters > Network adapters.
  -- NULL is a root. Depth is not fixed; nothing stops a group under a group.
  parent_id   INT UNSIGNED DEFAULT NULL,
  -- Almost always NULL. Set it only for a kind that genuinely belongs to one
  -- machine - a Zorro card, a trapdoor expansion - so the shared case stays
  -- shared and the specific case is still expressible.
  platform_id INT UNSIGNED DEFAULT NULL,
  name        VARCHAR(120) NOT NULL,
  slug        VARCHAR(140) NOT NULL,
  -- Which template kind this row was copied from.
  --
  -- The copies are matched back to their templates constantly - to find a parent while
  -- seeding, and to overwrite on a forced resync - and the only handle was
  -- CONCAT(platform.slug, '-', template.slug). An expression on one side of a join means
  -- no index can serve it, so building sixty-three trees became sixty-three scans of a
  -- growing table: five and a half seconds of a twenty-second install.
  --
  -- NULL on template rows and on anything added by hand, which is the honest answer:
  -- neither came from a template.
  source_slug VARCHAR(140) DEFAULT NULL,
  -- Materialised so a subtree can be selected without recursion. Maintained by
  -- category_rebuild_paths() whenever the tree changes.
  path        VARCHAR(255) NOT NULL DEFAULT '',
  depth       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  description TEXT         DEFAULT NULL,
  -- What an entry filed here looks like with no photograph of its own -
  -- a generic VHS clamshell, a boxed Amiga disk, whatever this branch's
  -- own things generally come in. Inherited down the branch when unset:
  -- a leaf with nothing of its own uses the nearest ancestor that has
  -- one, the same walk-up-the-tree category_role already does for kind.
  -- NULL on every template row - a picture is a real file on this
  -- instance's own disk, and a template is copied to instances that
  -- have never seen it.
  default_image_filename VARCHAR(255) DEFAULT NULL,
  -- The same question answered by the template feed instead of by a person.
  --
  -- A slug from stock_images() - one of the pictures that ship in public/stock -
  -- rather than a filename, because these are part of the package and not part
  -- of the collection. Which is also why this one *is* set on template rows
  -- where default_image_filename cannot be: a shipped picture exists on every
  -- instance by definition, so a template can name it safely.
  --
  -- Kept apart from the column above on purpose. That one is a deliberate act by
  -- whoever curates the library; this one is rewritten on every structure
  -- import. In one column the import would either silently overwrite somebody's
  -- choice or be unable to tell it apart from its own. Two columns, and the
  -- curator's wins - see the fallback order in item_to_api().
  --
  -- Inherited down the branch when unset, the same walk as the two above.
  stock_image VARCHAR(80)  DEFAULT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- COALESCE, because NULL never equals NULL in a unique key and the template rows
  -- would otherwise be free to duplicate each other. Same trick as platforms.
  library_key INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  UNIQUE KEY uq_categories_slug (library_key, slug),
  KEY idx_categories_source (library_id, source_slug),
  KEY idx_categories_library (library_id, section_id, sort_order),
  KEY idx_categories_parent (parent_id, sort_order),
  KEY idx_categories_path (path),
  KEY idx_categories_platform (platform_id),
  KEY idx_categories_section (section_id, sort_order),
  KEY idx_categories_role (section_id, role, sort_order),
  CONSTRAINT fk_cat_parent   FOREIGN KEY (parent_id)   REFERENCES categories (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cat_platform FOREIGN KEY (platform_id) REFERENCES platforms  (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cat_section  FOREIGN KEY (section_id)  REFERENCES sections   (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Genres, scoped to a category
-- (Action/RPG belong to Games; Spreadsheet/DTP belong to Productivity)
-- There is no `genres` table.
--
-- A genre is a subcategory of Games, and `categories` is already a tree - parent_id,
-- path, depth - which the software side already uses that way: Applications › Music
-- and audio › Tracker. Games was the one category that did not, carrying a parallel
-- one-level hierarchy of its own instead. Two mechanisms for "a subcategory of a
-- software category", and the weaker one only worked for games.
--
-- So a genre is a row in categories whose parent is Games, and the same mechanism
-- files a word processor under Applications › Productivity without anybody adding a
-- concept.
--
-- There is no genre_id either. It used to name the leaf beneath the branch in
-- category_id, which was two columns doing one job: an entry now files at the leaf and
-- nowhere else. ?category= matches the whole branch, so asking for Games finds
-- everything under it and asking for Racing narrows to one - which is what the tree was
-- for, and what the second column was quietly preventing.


-- ---------------------------------------------------------------------------
-- What a piece of software runs on.
--
-- Separate from the machine, because they are separate questions. A PC is one
-- platform whether it boots MS-DOS, Windows 3.x or OS/2, and a motherboard
-- belongs to none of them. Hardware leaves this empty.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS operating_systems (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Per library, like the platforms they hang off.
  --
  -- These were template rows only, and an entry names a platform from its own library -
  -- so the join in the entry form matched nothing and the Environment select was always
  -- empty. Every other piece of structure was made per-library in the same refactor;
  -- this one was missed because nothing on screen said so, it just looked like a list
  -- nobody had filled in.
  library_id  INT UNSIGNED DEFAULT NULL,
  platform_id INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  slug        VARCHAR(140) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  -- Declared after the plain columns, as in `platforms`: MariaDB refuses a STORED
  -- generated column that appears before them.
  library_key INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_os_slug (library_key, slug),
  KEY idx_os_library (library_id, name),
  KEY idx_os_platform (platform_id, sort_order),
  CONSTRAINT fk_os_platform FOREIGN KEY (platform_id) REFERENCES platforms (id) ON DELETE CASCADE ON UPDATE CASCADE
  -- No foreign key on library_id, for the same two reasons platforms has none: this
  -- table is declared before `libraries` exists, and MariaDB refuses a STORED generated
  -- column whose source sits in a cascading key - library_key is exactly that. The rows
  -- are cleaned up with their platforms instead, which cascade from the library.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Companies: software houses and publishers.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Per library, as vendors was. The two tables have merged: a company is a company
  -- whether it made the machine, the game or both, and Nintendo used to be two rows
  -- with the same country, founding year and Wikipedia link, free to disagree.
  library_id    INT UNSIGNED DEFAULT NULL,
  -- What they make. A tag rather than two tables, so Commodore is one row that turns
  -- up in a manufacturer picker and a publisher picker alike. Extended for the
  -- same reason `sections` exists: a studio can make a film, a label can press
  -- a record, and neither should need a separate companies table of its own.
  makes         SET('hardware','software','video','audio') NOT NULL DEFAULT 'software',
  -- Games or other software. Two lists rather than one because they overlap
  -- barely at all - Psygnosis never shipped a spreadsheet - and a single list
  -- of both makes each harder to search. 'both' exists for the handful that
  -- genuinely did both, so nobody has to pick a side for Microsoft.
  domain        ENUM('game','software','both') NOT NULL DEFAULT 'game',
  name          VARCHAR(160) NOT NULL,
  slug          VARCHAR(180) NOT NULL,
  logo_filename VARCHAR(255) DEFAULT NULL,
  country       VARCHAR(80)  DEFAULT NULL,
  founded_year  SMALLINT UNSIGNED DEFAULT NULL,
  defunct_year  SMALLINT UNSIGNED DEFAULT NULL,
  website       VARCHAR(500) DEFAULT NULL,
  wikipedia_url VARCHAR(500) DEFAULT NULL,
  notes         TEXT         DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  library_key   INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  UNIQUE KEY uq_companies_slug (library_key, slug),
  KEY idx_companies_library (library_id, name),
  KEY idx_companies_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- People, and the credits that name them
--
-- Companies covers who published or developed something - a business
-- relationship every title already had a real answer for. It has no honest
-- answer for who directed a film or performed an album; those are people, not
-- companies, and forcing "Steven Spielberg" into a table with a hardware/
-- software/video/music `makes` set and a founding year was a category error
-- worth a table of its own rather than working around.
--
-- Two tables rather than one credit-holder column: a director is almost
-- always a person, but "scored by" a studio's in-house team, or a game's
-- soundtrack credited to a label, is a real company credit too. `credits`
-- points at whichever one actually did the work.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS people (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Per library, the same reasoning companies already settled on: a
  -- director's name, birth year and Wikipedia link do not change depending
  -- on whose shelf they are catalogued from, but two libraries disagreeing
  -- about them is nobody's problem to reconcile if each keeps its own row.
  library_id    INT UNSIGNED DEFAULT NULL,
  name          VARCHAR(160) NOT NULL,
  slug          VARCHAR(180) NOT NULL,
  born_year     SMALLINT UNSIGNED DEFAULT NULL,
  died_year     SMALLINT UNSIGNED DEFAULT NULL,
  website       VARCHAR(500) DEFAULT NULL,
  wikipedia_url VARCHAR(500) DEFAULT NULL,
  notes         TEXT         DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  library_key   INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  UNIQUE KEY uq_people_slug (library_key, slug),
  KEY idx_people_library (library_id, name),
  KEY idx_people_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a credit can be. A short, curated, editable list rather than a fixed
-- enum or open free text: fixed would mean a migration every time a real
-- role turns up that this list did not predict, and free text would mean
-- nothing to filter the picker by when adding a movie should not be offered
-- "Composer". `domains` is the same set platforms and companies already
-- carry, reused rather than re-invented, so a role can genuinely belong to
-- more than one - Producer means roughly the same thing on a film and an
-- album, and does not need to be two separate rows to say so.
CREATE TABLE IF NOT EXISTS credit_roles (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  library_id    INT UNSIGNED DEFAULT NULL,
  name          VARCHAR(80)  NOT NULL,
  slug          VARCHAR(100) NOT NULL,
  domains       SET('hardware','software','video','audio') NOT NULL DEFAULT 'software',
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  library_key   INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  UNIQUE KEY uq_credit_roles_slug (library_key, slug),
  KEY idx_credit_roles_library (library_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One title, one role, one credited person or company - never both, never
-- neither. Enforced by a CHECK as well as at the API layer: a database
-- constraint is the one guarantee that holds even for a row written by
-- something that is not this application.
--
-- library_id duplicated here rather than reached only through title_id, the
-- same choice categories and hardware_models already made - a straight,
-- indexed filter by library rather than a join through titles for every
-- permission check or listing.
CREATE TABLE IF NOT EXISTS credits (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  library_id    INT UNSIGNED NOT NULL,
  title_id      INT UNSIGNED NOT NULL,
  role_id       INT UNSIGNED NOT NULL,
  person_id     INT UNSIGNED DEFAULT NULL,
  company_id    INT UNSIGNED DEFAULT NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_credits_title (title_id, sort_order),
  KEY idx_credits_role (role_id),
  KEY idx_credits_person (person_id),
  KEY idx_credits_company (company_id),
  KEY idx_credits_library (library_id),
  CONSTRAINT chk_credits_one_holder CHECK (
    (person_id IS NOT NULL AND company_id IS NULL) OR
    (person_id IS NULL AND company_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Companies used to be two tables
--
-- `vendors` held whoever made the hardware and `companies` whoever made the software,
-- with the same columns in both. Nintendo, Commodore, Atari and Sega existed twice over,
-- once in each, free to disagree about a founding year or a website. They are one table
-- now - `companies` - with a `makes` tag saying which side of the shop a firm is on, and
-- both if both.
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- Known interfaces and features, per platform.
--
-- Scoped by platform because "Zorro III" is meaningless on a C64 and "cartridge
-- port" is meaningless on an Amiga. platform_id 0 means "applies anywhere".
--
-- Zero rather than NULL on purpose: in SQL no NULL equals another NULL, so with
-- a nullable column the UNIQUE key below would not constrain the shared rows at
-- all - and the shared rows are the common case. You could insert
-- ('interface', NULL, 'zorro-ii') twice and the database would not care.
--
-- The Amiga rows use the vocabulary the Amiga Hardware Database itself uses,
-- so anything scraped from there lines up without translation.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hardware_vocab (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- interface   how it attaches, or what the board offers to attach to
  -- socket      what processor it takes - a separate question from the bus
  -- formfactor  whether it will physically fit the case
  -- feature     what it adds
  kind        ENUM('interface','feature','socket','formfactor') NOT NULL,
  platform_id INT UNSIGNED NOT NULL DEFAULT 0,
  code        VARCHAR(40)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 100,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hardware_vocab (kind, platform_id, code),
  KEY idx_hardware_vocab_lookup (kind, platform_id, sort_order),
  KEY idx_hardware_vocab_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Hardware models: machines and the things that go in them.
--
-- One table, because the type decides which it is. A computer, a console or a
-- handheld is a machine; an accelerator, a network adapter or a sound card is a
-- part. Two tables encoded a distinction the type already carried, and meant
-- writing the same editor twice.
--
-- This is the hardware half of "the thing that exists in the world, as against
-- the one you own". `titles` below is the software half. They are the same
-- idea and should stay symmetric.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hardware_models (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Whose list this is, exactly as platforms and vendors do it. NULL marks a
  -- template row: the seeded models, never filed under directly, existing only to
  -- be copied into a library when one is made.
  --
  -- Models used to be global, which meant a new library came up already knowing
  -- every machine anybody had ever defined, and adding an Amiga model to a private
  -- shelf published it to every other library on the instance. A model carries a
  -- maker and a platform, and both of those are per library, so it could not stay
  -- shared without straddling the boundary they draw.
  -- The constraint on library_id is added after `libraries` exists, further down,
  -- the same way platforms does it: this table is declared before that one.
  library_id   INT UNSIGNED DEFAULT NULL,
  vendor_id    INT UNSIGNED DEFAULT NULL,
  -- Optional. A card that suits several families, or a model nobody has
  -- decided about yet, should still be recordable.
  platform_id  INT UNSIGNED DEFAULT NULL,
  -- What kind of thing this model is: Computers, Consoles, a kind of
  -- peripheral. Decided once, here, when the model is defined - an Amiga 600 is
  -- a computer and a Blizzard 1230 is an accelerator, and neither fact changes
  -- from one unit to the next. Entries inherit it, and the entry form stops
  -- asking a question the model has already answered.
  -- Not nullable, and the constraint below refuses a delete rather than
  -- nulling this. It is the column that decides whether a model is a machine
  -- or a part, so a model without one is neither and falls out of every query
  -- that joins categories.
  category_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(160) NOT NULL,
  slug         VARCHAR(180) NOT NULL,
  year_from    SMALLINT     DEFAULT NULL,
  -- For a part: which machines it suits, in words.
  -- Which machines this fits lives in model_fits: a card goes in more than one,
  -- and a column can only name a single answer. What the free text used to say
  -- is kept, because "any ISA PC" is true and is not a model.
  fits_note    VARCHAR(200) DEFAULT NULL,
  -- For a part: the slot it occupies. Free text so nothing is unrecordable,
  -- with interface_vocab_id alongside for the cases that are known.
  interface    VARCHAR(40)  DEFAULT NULL,
  interface_vocab_id INT UNSIGNED DEFAULT NULL,
  notes        TEXT         DEFAULT NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- COALESCE, because NULL never equals NULL in a unique key and the template rows
  -- would otherwise be free to duplicate each other. Same trick as platforms.
  library_key  INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  UNIQUE KEY uq_hwmodels_slug (library_key, slug),
  KEY idx_hwmodels_library (library_id, name),
  KEY idx_hwmodels_platform (platform_id, sort_order),
  KEY idx_hwmodels_category (category_id),
  KEY idx_hwmodels_vendor (vendor_id),
  KEY idx_hwmodels_interface (interface_vocab_id),
  -- companies, not vendors: the tables merged. The column keeps its name because it
  -- still means the same thing - who made this - and renaming it would touch every
  -- query for no gain.
  CONSTRAINT fk_hwm_vendor    FOREIGN KEY (vendor_id)   REFERENCES companies (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_hwm_platform  FOREIGN KEY (platform_id) REFERENCES platforms  (id) ON DELETE SET NULL ON UPDATE CASCADE,
  -- CASCADE, not RESTRICT.
  --
  -- Both a library's models and its categories cascade from `libraries`, and InnoDB
  -- does not promise which goes first - so RESTRICT here made deleting a library fail
  -- roughly whenever the taxonomy went first. The protection it was providing lives in
  -- the application instead: the category delete guard counts models under a node and
  -- refuses, which is the case it was actually written for.
  CONSTRAINT fk_hwm_category  FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_hwm_interface FOREIGN KEY (interface_vocab_id) REFERENCES hardware_vocab (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Titles: the software half of "the thing that exists in the world".
--
-- Everything true of Speedball 2 the game rather than of the boxed copy on
-- your shelf: who wrote it, who published it, when, what genre. One row,
-- however many copies you own.
--
-- Scoped to a platform, because the Amiga and C64 releases of one game are
-- genuinely different artefacts - different developers sometimes, certainly
-- different media and packaging. `work_key` is what ties them back together:
-- a shared slug across platforms, so "every version of Speedball 2" is one
-- query and neither release has to pretend to be the other.
--
-- Optional throughout. An entry with no title_id is a perfectly good entry;
-- this exists so that the second copy of something does not mean retyping its
-- metadata, and so an import that runs twice does not produce two divergent
-- descriptions of one game.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS titles (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform_id   INT UNSIGNED NOT NULL,
  category_id   INT UNSIGNED DEFAULT NULL,
  developer_id  INT UNSIGNED DEFAULT NULL,
  publisher_id  INT UNSIGNED DEFAULT NULL,

  name          VARCHAR(220) NOT NULL,
  subtitle      VARCHAR(220) DEFAULT NULL,
  sort_name     VARCHAR(220) DEFAULT NULL,
  slug          VARCHAR(240) NOT NULL,
  -- Shared across platforms so the Amiga and C64 releases of one game can be
  -- found together. Defaults to the slug of the name alone.
  work_key      VARCHAR(240) NOT NULL DEFAULT '',
  -- Which template this was made from, if any. Optional throughout: a title typed by
  -- hand is a perfectly good title, and detaching the model later leaves what it filled
  -- in behind rather than taking it back.
  software_model_id INT UNSIGNED DEFAULT NULL,

  release_year  SMALLINT UNSIGNED DEFAULT NULL,
  release_date  DATE         DEFAULT NULL,
  language      VARCHAR(80)  DEFAULT NULL,
  region        VARCHAR(80)  DEFAULT NULL,
  external_url  VARCHAR(500) DEFAULT NULL,
  synopsis      TEXT         DEFAULT NULL,

  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by    INT UNSIGNED DEFAULT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_titles_slug (slug),
  -- One row per name per platform per year. This is what stops an import
  -- running twice from producing two Speedball 2s.
  UNIQUE KEY uq_titles_platform_name (platform_id, name, release_year),
  KEY idx_titles_work (work_key),
  KEY idx_titles_category (category_id),
  KEY idx_titles_developer (developer_id),
  KEY idx_titles_publisher (publisher_id),
  KEY idx_titles_year (release_year),
  FULLTEXT KEY ft_titles_name (name, subtitle),
  CONSTRAINT fk_titles_platform  FOREIGN KEY (platform_id)  REFERENCES platforms  (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_titles_category  FOREIGN KEY (category_id)  REFERENCES categories (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_titles_developer FOREIGN KEY (developer_id) REFERENCES companies (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_titles_publisher FOREIGN KEY (publisher_id) REFERENCES companies (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_titles_creator   FOREIGN KEY (created_by)   REFERENCES users     (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The fields a hardware model carries.
--
-- Processor, memory, video and storage were columns, which meant every model
-- answered the same four questions whether or not they applied - and a monitor's
-- tube size or a printer's resolution had nowhere to go at all.
--
-- Here the template is data: a model has whatever fields somebody decided it
-- should have, each with a default the entry form offers.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS model_fields (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  model_id    INT UNSIGNED NOT NULL,
  label       VARCHAR(60)  NOT NULL,
  -- A name and a starting value. It was once a kind, a set of choices and a
  -- width as well; the row editor asks for none of those, so nothing wrote them
  -- and nothing read them.
  default_value VARCHAR(200) DEFAULT NULL,
  hint        VARCHAR(160) DEFAULT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_model_field (model_id, label),
  KEY idx_model_fields (model_id, sort_order),
  CONSTRAINT fk_mf_model FOREIGN KEY (model_id) REFERENCES hardware_models (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- What a given model will actually take.
--
-- This is the point of recording models at all. Offering a C64 owner a PCI slot
-- is not merely untidy - it invites a wrong entry, and the catalogue is only
-- worth keeping if what it says is true.
-- ---------------------------------------------------------------------------
-- Which machine models a peripheral fits. Many to many because that is what it
-- is: a Blizzard 1230 goes in an A1200 and an A1200T, and an ISA card goes in
-- every PC anybody built.
-- What a peripheral model is compatible with, as a catalogue fact - not
-- whether one is physically installed anywhere right now. That is
-- item_links, relation='installed_in'. This table only says what is
-- possible; item_links says what is actually true today. The two used to
-- share the word "fits" and read as the same idea from the schema alone,
-- which they are not.
CREATE TABLE IF NOT EXISTS model_compatibility (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  model_id           INT UNSIGNED NOT NULL,
  compatible_model_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_model_compatibility (model_id, compatible_model_id),
  KEY idx_model_compatibility_target (compatible_model_id),
  CONSTRAINT fk_model_compatibility_model
    FOREIGN KEY (model_id) REFERENCES hardware_models (id) ON DELETE CASCADE,
  CONSTRAINT fk_model_compatibility_target
    FOREIGN KEY (compatible_model_id) REFERENCES hardware_models (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_slots (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  model_id  INT UNSIGNED NOT NULL,
  vocab_id  INT UNSIGNED NOT NULL,
  quantity  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  notes     VARCHAR(160) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_model_slot (model_id, vocab_id),
  KEY idx_model_slots (model_id),
  KEY idx_model_slots_vocab (vocab_id),
  CONSTRAINT fk_slot_model FOREIGN KEY (model_id) REFERENCES hardware_models (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_slot_vocab FOREIGN KEY (vocab_id) REFERENCES hardware_vocab  (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Libraries
--
-- The thing that has members, and the only access boundary in the system.
-- Your own Amiga shelf and a shared Amiga shelf are two libraries; both file
-- entries under the Amiga platform. What a person calls their collection is
-- everything they can reach across all of them, which is a view rather than a
-- table.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS libraries (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(160) NOT NULL,
  slug         VARCHAR(180) NOT NULL,
  description  VARCHAR(500) DEFAULT NULL,
  owner_id     INT UNSIGNED DEFAULT NULL,

  -- An offer of ownership, waiting on the other person.
  --
  -- Handing a library over is not something to do to somebody: it makes them
  -- responsible for a shelf, and on an instance where owners can delete libraries it
  -- hands them a loaded button. So the transfer is an offer that sits here until it is
  -- accepted, and the library keeps its current owner until then.
  --
  -- One column rather than a table: there can only ever be one offer outstanding,
  -- because there is only one owner to make it. Withdrawing is setting it back to NULL.
  pending_owner_id INT UNSIGNED DEFAULT NULL,
  pending_owner_at DATETIME     DEFAULT NULL,
  -- What sort of library this is, and it is one decision rather than three.
  --
  --   private  yours alone. No invitations, no public access, no exceptions.
  --   shared   may be opened up, by invitation or to everyone or both.
  --
  -- The owner can move a library between the two, except a personal one: that
  -- is the shelf every account is guaranteed to have, and guaranteeing it means
  -- guaranteeing it stays yours.
  -- Who may reach it, which is the question this decides.
  --
  --   private  explicit invitation only
  --   public   any signed-in account may join, and gets read access by doing so
  --
  -- This was private/shared, and `shared` said how a library is used rather than
  -- who may reach it - a library could be shared and still members-only, because
  -- public_read was a separate switch beside it. The two are one answer now:
  -- public_read follows kind, and public_write is always 0 because joining
  -- grants read and nothing more. A higher level is something somebody is
  -- invited to, not something taken by arriving.
  kind         ENUM('private','public') NOT NULL DEFAULT 'private',

  -- Only meaningful on a shared library, and forced off on a private one.
  -- Two flags rather than one setting because reading and writing are
  -- different decisions: a club might publish its catalogue to everyone while
  -- only letting invited members add to it.
  public_read  TINYINT(1)   NOT NULL DEFAULT 0,
  public_write TINYINT(1)   NOT NULL DEFAULT 0,
  -- Whether a photograph uploaded here waits for somebody to approve it.
  --
  -- Per library, because one shared shelf with contributors wanting review and
  -- one private shelf wanting none is the ordinary arrangement rather than an
  -- exotic one. Off by default. Anybody who curates the library is exempt from
  -- their own switch: they are the ones who would be approving it.
  photo_approval TINYINT(1) NOT NULL DEFAULT 0,
  -- Optional: set it and the library only accepts entries for that machine.
  restrict_to_platform_id INT UNSIGNED DEFAULT NULL,
  accent_color CHAR(7)      NOT NULL DEFAULT '#cba6f7',
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  -- One per account, created with it and never deletable. Somewhere to put
  -- things has to exist unconditionally, or a person can arrange to have
  -- nowhere at all and the add form has nothing to offer.
  is_personal  TINYINT(1)   NOT NULL DEFAULT 0,
  -- Switched off rather than deleted.
  --
  -- An administrator needs a way to take a shelf out of circulation without destroying
  -- what is on it: a library whose owner has left, one being investigated, one that
  -- turned out to hold something it should not. Deleting takes the entries with it and
  -- cannot be undone; disabling is reversible and loses nothing.
  --
  -- A disabled library is invisible to everyone except an administrator - it does not
  -- appear in switchers, pickers or the API, and nothing can be filed into it.
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order   INT          NOT NULL DEFAULT 100,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_libraries_slug (slug),
  KEY idx_libraries_owner (owner_id),
  KEY idx_libraries_personal (is_personal, owner_id),
  KEY idx_libraries_kind (kind, public_read, public_write),
  CONSTRAINT fk_lib_owner    FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_lib_platform FOREIGN KEY (restrict_to_platform_id) REFERENCES platforms (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- viewer      read
-- contributor read, add, edit and delete their own additions
-- curator     read, add, edit and delete anything
-- owner       curator, plus managing members and deleting the library
CREATE TABLE IF NOT EXISTS library_members (
  library_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  access        ENUM('viewer','contributor','editor','curator','admin','owner') NOT NULL DEFAULT 'viewer',
  -- An invitation is an offer, not an appointment. Nobody is put into somebody
  -- else's library without saying yes: a row sits at 'pending' and confers
  -- nothing at all until the person it names accepts it.
  --
  -- 'declined' is kept rather than deleted so the same invitation is not sent
  -- again next week, and so the person who sent it can see what happened.
  status        ENUM('pending','accepted','declined') NOT NULL DEFAULT 'accepted',
  -- Recorded so an administrator granting themselves access stays visible.
  granted_by    INT UNSIGNED DEFAULT NULL,
  granted_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at  DATETIME     DEFAULT NULL,
  note          VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (library_id, user_id),
  KEY idx_library_members_user (user_id, status),
  KEY idx_library_members_pending (status, granted_at),
  KEY idx_library_members_granter (granted_by),
  CONSTRAINT fk_lm_library FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_lm_user    FOREIGN KEY (user_id)    REFERENCES users     (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_lm_granter FOREIGN KEY (granted_by) REFERENCES users     (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Directory group -> library grant. One row per library the group confers.
-- 'owner' is deliberately absent: a directory group should not be able to
-- confer the right to hand out further grants.
CREATE TABLE IF NOT EXISTS auth_group_library_access (
  group_map_id INT UNSIGNED NOT NULL,
  library_id   INT UNSIGNED NOT NULL,
  access       ENUM('viewer','contributor','editor','curator','admin') NOT NULL DEFAULT 'viewer',
  PRIMARY KEY (group_map_id, library_id),
  KEY idx_agla_library (library_id),
  CONSTRAINT fk_agla_map     FOREIGN KEY (group_map_id) REFERENCES auth_group_map (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_agla_library FOREIGN KEY (library_id)   REFERENCES libraries      (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Where things physically are.
--
-- A tree of any depth, because collections differ: "Computer room > Cabinet >
-- Shelf 2 > Box A" and "Loft" are both complete answers, and nothing forces
-- you through levels you do not use.
--
-- Scoped to a library, for the same reason everything else is: it is the only
-- access boundary in the system, so a shared club shelf does not list the rooms
-- in your house. The cost is that a room holding entries from two libraries has
-- to be named in both - which is the honest trade, because the alternative is
-- either leaking your floor plan to everyone on the instance or inventing a
-- second ownership model that only locations use.
--
-- `sort_order` is what makes Shelf 10 come after Shelf 2 rather than before it.
-- There is deliberately no per-entry slot number: "third from the left" is a
-- level of precision nobody maintains, and a wrong answer is worse than none.
-- If you want it, it belongs in the entry's notes where it can be a sentence.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  library_id  INT UNSIGNED NOT NULL,
  parent_id   INT UNSIGNED DEFAULT NULL,
  name        VARCHAR(120) NOT NULL,
  -- Materialised the same way categories are, so a subtree is one LIKE rather
  -- than recursion. Maintained by location_rebuild_paths().
  path        VARCHAR(255) NOT NULL DEFAULT '',
  depth       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  -- Which floor of the building. Signed, because basements exist and -1 is
  -- what everybody calls them. NULL where it does not apply: a shelf inside a
  -- room is on whatever floor the room is.
  floor_level TINYINT      DEFAULT NULL,
  notes       VARCHAR(255) DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- No slug, and no unique key on the name. Both were copied from `categories`
  -- and both were wrong here.
  --
  -- A slug made two people's offices collide: unique_slug() checks the whole
  -- table, so the second "Office" in a different library became "office-2".
  -- Nothing ever read the column, so it is gone rather than fixed.
  --
  -- A unique name per library forbids "Cabinet A > Shelf 1" alongside
  -- "Cabinet B > Shelf 1", which are two real shelves. The rule that is
  -- actually true is "no two places with the same name in the same place", and
  -- that cannot be a unique key here: parent_id is NULL at the top level, and
  -- NULL never collides with NULL, so the roots would go unconstrained anyway.
  -- location_name_taken() enforces it where it can also explain itself.
  -- There is no sort_order. Places are sorted naturally in PHP, so Shelf 10
  -- lands after Shelf 2 without anybody maintaining a column to say so - and a
  -- number you have to keep in step by hand is a number that drifts.
  KEY idx_locations_sibling (library_id, parent_id, name),
  KEY idx_locations_library (library_id, name),
  KEY idx_locations_parent (parent_id, name),
  KEY idx_locations_path (path),
  CONSTRAINT fk_loc_library FOREIGN KEY (library_id) REFERENCES libraries (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_loc_parent  FOREIGN KEY (parent_id)  REFERENCES locations (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The shelf itself.
--
-- An item is *your copy*, and almost every column here is about the copy
-- rather than about the thing: where it is, what condition, what you paid,
-- whether it is lent out. Those questions are identical for a disk and for a
-- network card, which is why software and hardware share this table.
--
-- What the thing *is* lives in `titles` (software) or `hardware_models`
-- (hardware). Both are optional: a bare entry is still a valid entry.
--
-- Two copies of one game are two rows pointing at one title. That is the
-- point - they have different condition, different completeness, one may be
-- missing its manual - and nothing about that is a duplicate.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  library_id       INT UNSIGNED NOT NULL,

  -- What it is. Software points at a title; hardware at a model. Both NULL is
  -- allowed and means "described entirely by the columns below".
  title_id         INT UNSIGNED DEFAULT NULL,
  model_id         INT UNSIGNED DEFAULT NULL,

  -- Which environments a release runs under is in item_environments, not here: a PC
  -- release commonly runs on DOS *and* Windows 3.x, and one column could only ever
  -- record the first answer somebody happened to pick.
  platform_id      INT UNSIGNED NOT NULL,
  category_id      INT UNSIGNED NOT NULL,
  developer_id     INT UNSIGNED DEFAULT NULL,
  publisher_id     INT UNSIGNED DEFAULT NULL,

  title            VARCHAR(220) NOT NULL,
  subtitle         VARCHAR(220) DEFAULT NULL,
  sort_title       VARCHAR(220) DEFAULT NULL,

  release_year     SMALLINT UNSIGNED DEFAULT NULL,
  release_date     DATE         DEFAULT NULL,

  rating           TINYINT UNSIGNED DEFAULT NULL,       -- 1..10 personal score
  condition_grade  ENUM('mint','near_mint','very_good','good','fair','poor','unknown')
                   NOT NULL DEFAULT 'unknown',
  completeness     ENUM('cib','boxed_no_manual','loose','manual_only','digital','unknown')
                   NOT NULL DEFAULT 'unknown',
  -- Whether a box exists at all, which condition_box cannot say: "not graded"
  -- and "there is no box" are different facts, and a bare board that never
  -- shipped in one is not the same as a boxed card whose box was lost.
  has_box          TINYINT(1)   NOT NULL DEFAULT 0,

  media_type       VARCHAR(60)  DEFAULT NULL,           -- 3.5" floppy, cartridge, tape, CD-ROM
  media_count      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  catalog_number   VARCHAR(80)  DEFAULT NULL,
  barcode          VARCHAR(40)  DEFAULT NULL,
  language         VARCHAR(80)  DEFAULT NULL,
  region           VARCHAR(80)  DEFAULT NULL,

  -- Everything else true of this particular copy, for every domain
  -- item_hardware.specs is not - a genre, a director, a running time,
  -- a record label's catalogue number nobody has a dedicated column
  -- for. Same shape, same reasoning: an ordered list of label/value
  -- pairs, [{"label":"Genre","value":"Action"}, ...], seeded by a
  -- metadata lookup and freely added to or renamed by hand afterward.
  -- Hardware keeps its own dedicated column rather than sharing this
  -- one - item_hardware.specs is tied to the hardware detail row
  -- itself, one record per unit, and splitting a machine's specs
  -- across two places would be worse than the duplication of having
  -- both columns exist.
  specs            JSON DEFAULT NULL,

  -- Where it came from and where it went. Both halves have the same shape -
  -- when, who with, how much, and anything worth remembering - because they are
  -- the same event seen from opposite ends.
  acquired_on      DATE         DEFAULT NULL,
  acquired_from    VARCHAR(140) DEFAULT NULL,
  acquired_price   DECIMAL(10,2) DEFAULT NULL,
  acquired_note    VARCHAR(255) DEFAULT NULL,
  currency         CHAR(3)      NOT NULL DEFAULT 'SEK',
  -- Where it physically is. A row in `locations` rather than free text, so
  -- "what is on shelf 2" is a question with an answer.
  location_id      INT UNSIGNED DEFAULT NULL,
  -- Whereabouts in that place. Free text on purpose: people number shelves
  -- 1, 2, 3, letter them a, b, c, or say "left" and "behind the printer", and
  -- an integer would force everyone into one of those and be wrong for the
  -- rest. It belongs to the entry rather than the location because it
  -- describes this object's spot, not a property of the shelf.
  location_position VARCHAR(40) DEFAULT NULL,
  is_original      TINYINT(1)   NOT NULL DEFAULT 1,
  -- Status is the only truth about where an entry stands. The old is_wishlist
  -- flag duplicated part of it and was maintained by hand in four places, so
  -- browse and the dashboard could disagree about the same row.
  status           ENUM('owned','wishlist','ordered','sold') NOT NULL DEFAULT 'owned',
  sold_on          DATE         DEFAULT NULL,
  sold_to          VARCHAR(140) DEFAULT NULL,
  sold_price       DECIMAL(10,2) DEFAULT NULL,
  -- The sale has its own currency. Bought in SEK and sold in EUR is ordinary,
  -- and one shared column meant one of the two figures was always wrong. NULL
  -- until there is a sale, so an unsold entry does not look like a closed trade.
  sold_currency    CHAR(3)      DEFAULT NULL,
  sold_note        VARCHAR(255) DEFAULT NULL,

  -- Graded per component, the way collectors actually describe a boxed title.
  condition_box    ENUM('mint','near_mint','very_good','good','fair','poor','missing','unknown') NOT NULL DEFAULT 'unknown',
  condition_manual ENUM('mint','near_mint','very_good','good','fair','poor','missing','unknown') NOT NULL DEFAULT 'unknown',
  condition_media  ENUM('mint','near_mint','very_good','good','fair','poor','missing','unknown') NOT NULL DEFAULT 'unknown',

  current_value    DECIMAL(10,2) DEFAULT NULL,
  valued_on        DATE          DEFAULT NULL,
  copies           TINYINT UNSIGNED NOT NULL DEFAULT 1,

  external_url     VARCHAR(500) DEFAULT NULL,           -- Lemon Amiga, CSDb, MobyGames ...
  notes            TEXT         DEFAULT NULL,
  -- The release's own blurb, from a metadata source or typed. Separate from the
  -- notes, which are about this copy rather than about the release.
  description      TEXT         DEFAULT NULL,

  -- Denormalised from item_images so a list query does not need a correlated
  -- subquery per row. Maintained by ensure_primary_image() and delete_image(),
  -- which already run at exactly the right moments.
  cover_image_id   INT UNSIGNED DEFAULT NULL,
  image_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Who added it, so 'contributor' can mean "edit your own".
  created_by       INT UNSIGNED DEFAULT NULL,
  -- Set rather than removed. A catalogue built over months should not lose an
  -- entry to one mis-click, and a confirm dialog is not a safety net.
  deleted_at       DATETIME DEFAULT NULL,

  PRIMARY KEY (id),
  KEY idx_items_library (library_id, deleted_at),
  KEY idx_items_deleted (deleted_at),
  KEY idx_items_title_ref (title_id),
  KEY idx_items_model (model_id),
  KEY idx_items_created_by (created_by),
  KEY idx_items_platform (platform_id),
  KEY idx_items_category (category_id),
  KEY idx_items_developer (developer_id),
  KEY idx_items_publisher (publisher_id),
  KEY idx_items_title (title),
  KEY idx_items_year (release_year),
  KEY idx_items_rating (rating),
  KEY idx_items_status (status),
  KEY idx_items_barcode (barcode),
  KEY idx_items_catalog (catalog_number),
  KEY idx_items_cover (cover_image_id),
  KEY idx_items_location (location_id),
  -- Search used to be LIKE '%term%' across seven columns, which no index can
  -- serve. Exact-ish columns (barcode, catalog number) keep their own indexes;
  -- the prose goes through this.
  FULLTEXT KEY ft_items_search (title, subtitle, notes, description),
  CONSTRAINT fk_items_library   FOREIGN KEY (library_id)   REFERENCES libraries (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_items_title     FOREIGN KEY (title_id)     REFERENCES titles    (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_items_model     FOREIGN KEY (model_id)     REFERENCES hardware_models   (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_items_platform  FOREIGN KEY (platform_id)  REFERENCES platforms  (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_items_category  FOREIGN KEY (category_id)  REFERENCES categories (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_items_developer FOREIGN KEY (developer_id) REFERENCES companies (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_items_publisher FOREIGN KEY (publisher_id) REFERENCES companies (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_items_creator   FOREIGN KEY (created_by)   REFERENCES users     (id) ON DELETE SET NULL ON UPDATE CASCADE,
  -- Losing a shelf should not lose what was on it.
  CONSTRAINT fk_items_location  FOREIGN KEY (location_id)  REFERENCES locations (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Packaging photos. Files live on disk, rows describe them.
-- ---------------------------------------------------------------------------
-- Which machines one particular card fits, where its model does not say.
--
-- The model is the authority when it has an answer: a BigRAM 2008 fits what a
-- BigRAM 2008 fits, and a copy of one cannot fit something else. This is for the
-- card with no model, or whose model has never been told - somebody's unlabelled
-- Zorro board that they know goes in an A2000.
--
-- Kept rather than cleared when a model is later attached, so detaching it again
-- does not lose an answer somebody typed.
-- ---------------------------------------------------------------------------
-- What a piece of software runs under.
--
-- Many to many, for the same reason item_fits is: a boxed PC release from 1995 runs on
-- MS-DOS and Windows 3.x and often Windows 9x, and items.os_id could hold exactly one
-- of those. A cartridge has none, which is the empty set rather than a null.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_environments (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  os_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_env (item_id, os_id),
  KEY idx_item_env_target (os_id),
  CONSTRAINT fk_item_env_item
    FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_env_target
    FOREIGN KEY (os_id) REFERENCES operating_systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The same fact as model_compatibility, recorded per item instead of per
-- model - for the one card whose compatibility was never given a model of
-- its own to hang the fact off.
CREATE TABLE IF NOT EXISTS item_compatibility (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id            INT UNSIGNED NOT NULL,
  compatible_model_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_compatibility (item_id, compatible_model_id),
  KEY idx_item_compatibility_target (compatible_model_id),
  CONSTRAINT fk_item_compatibility_item
    FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_compatibility_target
    FOREIGN KEY (compatible_model_id) REFERENCES hardware_models (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Software models
--
-- The counterpart to hardware_models, and the piece that was missing.
--
-- `titles` is a specific work - Speedball 2 on the Amiga - which makes it the software
-- equivalent of an *item*, not of a model. What had no equivalent at all was the
-- template: the thing that says what an Amiga boxed game generally *is*, so that
-- recording one does not mean typing "3.5-inch disks, manual, registration card" again
-- every time.
--
-- A model belongs to a platform and a category, carries default specification fields
-- and a default box contents list, and a title made from one starts with both filled
-- in - exactly as choosing an Amiga 500 fills in a machine's specification rows.
CREATE TABLE IF NOT EXISTS software_models (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Per library, like every other template-copied table.
  library_id   INT UNSIGNED DEFAULT NULL,
  platform_id  INT UNSIGNED DEFAULT NULL,
  -- Which branch of the tree it belongs to: Games, Applications, and so on. Optional,
  -- because a model can describe packaging that spans several.
  category_id  INT UNSIGNED DEFAULT NULL,
  publisher_id INT UNSIGNED DEFAULT NULL,
  name         VARCHAR(160) NOT NULL,
  slug         VARCHAR(180) NOT NULL,
  -- What it comes on, where that is a fact about the format rather than the game:
  -- "3 x 3.5-inch disk", "CD-ROM", "cartridge".
  media        VARCHAR(120) DEFAULT NULL,
  year_from    SMALLINT     DEFAULT NULL,
  notes        TEXT         DEFAULT NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  library_key  INT UNSIGNED AS (COALESCE(library_id, 0)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_software_models_slug (library_key, slug),
  KEY idx_swm_library (library_id, sort_order),
  KEY idx_swm_platform (platform_id),
  CONSTRAINT fk_swm_platform FOREIGN KEY (platform_id) REFERENCES platforms (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_swm_category FOREIGN KEY (category_id) REFERENCES categories (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_swm_publisher FOREIGN KEY (publisher_id) REFERENCES companies (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a title made from this model asks about. Same shape as model_fields.
CREATE TABLE IF NOT EXISTS software_model_fields (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  model_id      INT UNSIGNED NOT NULL,
  label         VARCHAR(60)  NOT NULL,
  default_value VARCHAR(200) DEFAULT NULL,
  hint          VARCHAR(160) DEFAULT NULL,
  sort_order    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_swmf (model_id, label),
  KEY idx_swmf (model_id, sort_order),
  CONSTRAINT fk_swmf_model FOREIGN KEY (model_id) REFERENCES software_models (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the box should hold. Copied onto a title's contents list when the model is
-- chosen, and editable from there - the model says what is usual, the title says what
-- this release actually shipped with.
CREATE TABLE IF NOT EXISTS software_model_contents (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  model_id   INT UNSIGNED NOT NULL,
  label      VARCHAR(120) NOT NULL,
  note       VARCHAR(255) DEFAULT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_swmc (model_id, label),
  KEY idx_swmc (model_id, sort_order),
  CONSTRAINT fk_swmc_model FOREIGN KEY (model_id) REFERENCES software_models (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- What a release comes on.
--
-- A row per medium, because a boxed release is often more than one: a 1994 PC game on
-- six floppies and a CD, a compilation on two cartridges. `software_models.media` held
-- a single string and could say "3.5-inch disk, CD-ROM" only as prose nobody could
-- count or filter on.
--
-- The count is per medium, so "6 x 3.5-inch disk" and "1 x CD-ROM" are two rows rather
-- than one sentence.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_media (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id    INT UNSIGNED NOT NULL,
  medium     VARCHAR(60)  NOT NULL,
  quantity   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_item_media_item (item_id, sort_order),
  CONSTRAINT fk_item_media_item FOREIGN KEY (item_id)
    REFERENCES items (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS software_model_media (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  model_id   INT UNSIGNED NOT NULL,
  medium     VARCHAR(60)  NOT NULL,
  quantity   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_smm_model (model_id, sort_order),
  CONSTRAINT fk_smm_model FOREIGN KEY (model_id)
    REFERENCES software_models (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ---------------------------------------------------------------------------
-- What is in the box
--
-- Two halves, the same shape as a hardware model and its specification rows.
--
-- `title_contents` is what a release *should* contain - manual, registration card,
-- map, poster, the disks themselves. It belongs to the title, because it is a fact
-- about the release rather than about anybody's copy.
--
-- `item_contents` is what a particular copy actually has. Filing a copy of a known
-- title offers the release's list as ticks; an entry with no title can still list
-- whatever is in its box, which is why the label is repeated here rather than pointing
-- back at a title row. A copy that later loses its manual should not rewrite what the
-- release contained.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS title_contents (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title_id   INT UNSIGNED NOT NULL,
  label      VARCHAR(120) NOT NULL,
  note       VARCHAR(255) DEFAULT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_title_contents (title_id, label),
  KEY idx_title_contents (title_id, sort_order),
  CONSTRAINT fk_tc_title FOREIGN KEY (title_id) REFERENCES titles (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_contents (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id    INT UNSIGNED NOT NULL,
  label      VARCHAR(120) NOT NULL,
  -- Three states, not two. "Not ticked" on a fresh entry means nobody has looked,
  -- which is a different thing from having checked and found it missing - and the
  -- difference is the whole value of a completeness list.
  present    ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown',
  note       VARCHAR(255) DEFAULT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_contents (item_id, label),
  KEY idx_item_contents (item_id, sort_order),
  CONSTRAINT fk_ic_item FOREIGN KEY (item_id) REFERENCES items (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_images (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id       INT UNSIGNED NOT NULL,
  filename      VARCHAR(255) NOT NULL,       -- stored name, relative to uploads dir
  original_name VARCHAR(255) DEFAULT NULL,
  -- SHA-256 of the original bytes, so re-uploading the same photo can be
  -- spotted. A batch upload from a phone produces duplicates constantly.
  content_hash  CHAR(64)     DEFAULT NULL,
  -- What the picture is of.
  kind          ENUM('box_front','box_back','box_spine','media','manual',
                     'extras','screenshot','unit','other')
                NOT NULL DEFAULT 'other',
  -- Where it came from. The other axis: `kind` says what it shows, this says
  -- whether it is the publisher's artwork or a photograph of somebody's own
  -- copy - which is what decides whether a scraper may write here.
  provenance    ENUM('official','personal') NOT NULL DEFAULT 'personal',
  -- Whether anybody but its uploader may see it yet.
  --
  -- A state on the row here, unlike an avatar: an entry has many pictures, and a
  -- pending one is a new row rather than a replacement for an existing one.
  -- 'approved' by default, so every picture already on an instance stays
  -- visible - and so does every picture a metadata agent writes, which is
  -- correct: an agent is not a person and its artwork is not somebody's
  -- snapshot.
  approval_state ENUM('approved','pending') NOT NULL DEFAULT 'approved',
  uploaded_by   INT UNSIGNED DEFAULT NULL,
  approved_by   INT UNSIGNED DEFAULT NULL,
  approved_at   DATETIME     DEFAULT NULL,
  -- The address an imported picture was taken from, so a review screen can say
  -- "already here" before anything is fetched. Null for uploads.
  source_url    VARCHAR(500) DEFAULT NULL,
  caption       VARCHAR(255) DEFAULT NULL,
  width         SMALLINT UNSIGNED DEFAULT NULL,
  height        SMALLINT UNSIGNED DEFAULT NULL,
  filesize      INT UNSIGNED DEFAULT NULL,
  is_primary    TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order    INT          NOT NULL DEFAULT 0,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_images_item (item_id, sort_order),
  KEY idx_images_primary (item_id, is_primary),
  -- Asked as "what is waiting on this library": one library's pending rows.
  KEY idx_images_approval (approval_state, item_id),
  UNIQUE KEY uq_images_item_hash (item_id, content_hash),
  CONSTRAINT fk_images_item FOREIGN KEY (item_id)
    REFERENCES items (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The cover pointer had to wait until item_images existed.
ALTER TABLE items
  ADD CONSTRAINT fk_items_cover FOREIGN KEY (cover_image_id)
    REFERENCES item_images (id) ON DELETE SET NULL ON UPDATE CASCADE;

-- Deferred for the same reason: platforms are declared near the top because
-- most of the schema depends on them, and a library's own platforms are a much
-- later idea than a platform.
ALTER TABLE platforms
  -- No ON UPDATE CASCADE: MariaDB refuses a generated column that depends on
  -- one, and library ids are surrogate keys that are never rewritten, so the
  -- clause had nothing to fire on.
  ADD CONSTRAINT fk_platforms_library FOREIGN KEY (library_id)
    REFERENCES libraries (id) ON DELETE CASCADE;

ALTER TABLE software_models
  -- No ON UPDATE CASCADE: library_key is generated from this column.
  ADD CONSTRAINT fk_swm_library FOREIGN KEY (library_id)
    REFERENCES libraries (id) ON DELETE CASCADE;

ALTER TABLE titles
  ADD CONSTRAINT fk_titles_swmodel FOREIGN KEY (software_model_id)
    REFERENCES software_models (id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE companies
  -- No ON UPDATE CASCADE: library_key is generated from this column.
  ADD CONSTRAINT fk_companies_library FOREIGN KEY (library_id)
    REFERENCES libraries (id) ON DELETE CASCADE;

ALTER TABLE people
  -- No ON UPDATE CASCADE: library_key is generated from this column, the
  -- same reason companies' own library FK has none.
  ADD CONSTRAINT fk_people_library FOREIGN KEY (library_id)
    REFERENCES libraries (id) ON DELETE CASCADE;

ALTER TABLE credit_roles
  ADD CONSTRAINT fk_credit_roles_library FOREIGN KEY (library_id)
    REFERENCES libraries (id) ON DELETE CASCADE;

ALTER TABLE credits
  ADD CONSTRAINT fk_credits_library FOREIGN KEY (library_id)
    REFERENCES libraries (id) ON DELETE CASCADE,
  -- A title's credits are part of the title - deleting one takes them with
  -- it rather than leaving a credit pointed at nothing.
  ADD CONSTRAINT fk_credits_title FOREIGN KEY (title_id)
    REFERENCES titles (id) ON DELETE CASCADE,
  -- RESTRICT, not CASCADE or SET NULL: a role in use is the same "cannot
  -- silently orphan real data" reasoning categories' own delete guards
  -- already enforce for hardware models. Reassign the credits first.
  ADD CONSTRAINT fk_credits_role FOREIGN KEY (role_id)
    REFERENCES credit_roles (id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_credits_person FOREIGN KEY (person_id)
    REFERENCES people (id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_credits_company FOREIGN KEY (company_id)
    REFERENCES companies (id) ON DELETE RESTRICT;

ALTER TABLE categories
  -- No ON UPDATE CASCADE: library_key is generated from this column, and MariaDB
  -- refuses a generated column that depends on one. Deleting a library takes its
  -- taxonomy with it, and the tree cascades from parent to child.
  ADD CONSTRAINT fk_cat_library FOREIGN KEY (library_id)
    REFERENCES libraries (id) ON DELETE CASCADE;

ALTER TABLE hardware_models
  -- Same reasoning as platforms above: no ON UPDATE CASCADE, because library_key
  -- is generated from this column. Deleting a library takes its models with it,
  -- along with their fields, slots and compatibility rows, which cascade from the
  -- model.
  ADD CONSTRAINT fk_hwm_library FOREIGN KEY (library_id)
    REFERENCES libraries (id) ON DELETE CASCADE;

ALTER TABLE platforms
  -- Losing a manufacturer should not lose the machines it made.
  ADD CONSTRAINT fk_platforms_vendor FOREIGN KEY (vendor_id)
    REFERENCES companies (id) ON DELETE SET NULL ON UPDATE CASCADE;


-- ---------------------------------------------------------------------------
-- Hardware detail. One row per hardware entry; software entries have none.
--
-- Everything a peripheral needs lives here. A Blizzard 1230 is a first-class
-- entry, not a note on the machine it happens to be fitted to: it has its own
-- serial, its own condition, its own photographs, and it can be moved between
-- machines without rewriting anything.
--
-- Where a column also exists on hardware_models, the value here is an
-- *override* for this particular unit, and NULL means "whatever the model
-- says". hardware_detail() in src/models.php is the one place that resolves
-- the two, so no template has to decide.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_hardware (
  item_id           INT UNSIGNED NOT NULL,
  -- Override for hardware_models.name. NULL when the model already says it.
  model             VARCHAR(160) DEFAULT NULL,
  -- Rev 6A and Rev 8A of an A500 are meaningfully different machines, and it
  -- is the first thing anyone asks. It deserves better than a note.
  board_revision    VARCHAR(80)  DEFAULT NULL,
  -- What version is on the board, where it has one: a ROM revision, a flashed
  -- firmware, the sticker on the EPROM. Free text because every maker numbered
  -- these differently and half of them not at all.
  firmware          VARCHAR(80)  DEFAULT NULL,
  serial_number     VARCHAR(120) DEFAULT NULL,
  manufactured_year SMALLINT UNSIGNED DEFAULT NULL,

  -- How it attaches to a host. Free text so nothing is unrecordable, with the
  -- vocabulary id alongside for the cases that are known - which is what makes
  -- "what do I own that fits a Zorro III slot?" an exact query rather than a
  -- string comparison.
  interface         VARCHAR(80)  DEFAULT NULL,   -- a1200-trapdoor, zorro-ii, db9 ...
  interface_vocab_id INT UNSIGNED DEFAULT NULL,
  -- What it adds or exposes. For an adapter this is the far end; for a
  -- controller card it is the bus it provides.
  provides          VARCHAR(120) DEFAULT NULL,   -- SCSI-2, VGA, USB, IDE ...
  -- Which machines it actually works in, in the words a collector would use.
  -- Override for hardware_models.fits.
  fits              VARCHAR(255) DEFAULT NULL,

  -- Everything else true of this particular unit, as an ordered list of
  -- label/value pairs: [{"label":"Processor","value":"68030 @ 50 MHz"}, ...].
  --
  -- This replaces three overlapping mechanisms that all answered the same
  -- question: fixed cpu/memory/storage columns, a field_values blob keyed by a
  -- model's template, and a slot_contents blob keyed by slot name. Every
  -- machine answered the same three questions whether or not they applied, a
  -- monitor's tube size had nowhere to go, and a model with a "Storage" field
  -- and a "Storage" slot overwrote itself in silence.
  --
  -- One list. The model seeds it with sensible rows; the person adds, removes
  -- and renames whatever they like. An upgraded machine is the interesting
  -- kind, and it should not need a schema change to say so.
  specs             JSON DEFAULT NULL,

  working_state     ENUM('working','intermittent','not_working','untested','restored')
                    NOT NULL DEFAULT 'untested',
  -- Which region the machine itself is. It was called video_standard and sat
  -- under Condition, which is neither what it is nor where you look for it: a
  -- PAL A500 is not in worse condition than an NTSC one, it is a different
  -- machine, and that belongs with the board revision and the serial.
  region            ENUM('unknown','PAL','NTSC','both') NOT NULL DEFAULT 'unknown',
  -- Electrolytics dry out and every one of these machines is past forty. When
  -- it was recapped is the second thing anyone asks after the board revision.
  recapped_on       DATE         DEFAULT NULL,
  -- A fuller going-over than a recap: belts, drives, retrobright, the lot. Two dates
  -- rather than one, so "recapped in 2019, serviced last year" is expressible - they
  -- are different jobs and collectors track them separately.
  serviced_on       DATE         DEFAULT NULL,
  -- Recapped, Kickstart switcher, replaced PSU: what has been done to it.
  modifications     TEXT         DEFAULT NULL,
  psu_included      TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (item_id),
  KEY idx_hardware_interface (interface),
  KEY idx_hardware_vocab (interface_vocab_id),
  KEY idx_hardware_state (working_state),
  CONSTRAINT fk_hw_item  FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_hw_vocab FOREIGN KEY (interface_vocab_id) REFERENCES hardware_vocab (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- What is fitted to what.
--
-- The thing a spreadsheet can never express. A Blizzard 1230 is installed in
-- an A1200; a 4 MB SIMM is installed in the Blizzard; a 1084S was bundled with
-- the machine. Links nest, so the chain holds. It also means that selling a
-- machine can show you exactly what leaves with it.
--
-- Cycles are prevented in item_link_save(), not here: SQL cannot express
-- "and no path from child back to parent" as a constraint.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_links (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_item_id INT UNSIGNED NOT NULL,
  child_item_id  INT UNSIGNED NOT NULL,
  relation       ENUM('installed_in','bundled_with','spare_for','connects_to')
                 NOT NULL DEFAULT 'installed_in',
  note           VARCHAR(255) DEFAULT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- A physical object is inside one machine or none. Without this a card could
  -- be recorded as installed in two machines at once, which is not a state the
  -- world can be in - and the catalogue is only worth keeping if what it says
  -- is true.
  --
  -- Generated so the constraint applies to 'installed_in' alone: the other
  -- relations are genuinely many-to-many, and a NULL never collides with
  -- another NULL, so they stay unconstrained.
  fitted_child_id INT UNSIGNED AS (IF(relation = 'installed_in', child_item_id, NULL)) STORED,

  PRIMARY KEY (id),
  UNIQUE KEY uq_item_links (parent_item_id, child_item_id, relation),
  UNIQUE KEY uq_fitted_once (fitted_child_id),
  KEY idx_item_links_child (child_item_id),
  CONSTRAINT fk_link_parent FOREIGN KEY (parent_item_id) REFERENCES items (id) ON DELETE CASCADE ON UPDATE CASCADE,
  -- No ON UPDATE CASCADE on the child, and it is not an oversight: MariaDB
  -- refuses a generated column that depends on one, and the cascade was inert
  -- anyway. items.id is a surrogate key that is never rewritten, so the rule
  -- had nothing to fire on. Trading a clause that could never run for a
  -- constraint the database will actually enforce is a good trade.
  CONSTRAINT fk_link_child  FOREIGN KEY (child_item_id)  REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Free-form tags (compilation, budget re-release, cracked, boxed-large ...)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tags (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80)  NOT NULL,
  slug VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_tags (
  item_id INT UNSIGNED NOT NULL,
  tag_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (item_id, tag_id),
  KEY idx_item_tags_tag (tag_id),
  CONSTRAINT fk_item_tags_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_item_tags_tag  FOREIGN KEY (tag_id)  REFERENCES tags  (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- What happened to an entry, and when.
--
-- acquired_price, current_value and sold_price are single-valued, so
-- re-valuing an item used to destroy what it was worth before. One row per
-- event makes the collection valuation a time series instead of a snapshot,
-- and makes "what did this cost me in 2019" answerable.
--
-- The columns on items remain the current state, written alongside the event,
-- because every list query wants them and none of them wants a join.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id     INT UNSIGNED NOT NULL,
  kind        ENUM('acquired','valued','lent','returned','sold','noted') NOT NULL,
  happened_on DATE         DEFAULT NULL,
  amount      DECIMAL(10,2) DEFAULT NULL,
  currency    CHAR(3)      DEFAULT NULL,
  party       VARCHAR(140) DEFAULT NULL,   -- lent to, bought from, sold to
  note        VARCHAR(255) DEFAULT NULL,
  user_id     INT UNSIGNED DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_item (item_id, happened_on, id),
  KEY idx_events_kind (kind, happened_on),
  CONSTRAINT fk_events_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Instance settings an administrator can change without editing a file.
--
-- Deliberately not everything: database credentials and paths stay in
-- config.local.php, where they belong and where a broken value cannot lock
-- somebody out of the screen that would fix it. This is for the things that are
-- genuinely operational - the mail relay, the site's own address, the defaults
-- for who gets told what.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  name       VARCHAR(120) NOT NULL,
  value      TEXT         DEFAULT NULL,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The log
--
-- One table, three streams, told apart by `channel`:
--
--   security  who signed in, who was let into what, who was locked out
--   server    what the instance did - libraries made, entries changed, settings
--             altered, jobs that failed
--
-- Kept apart because they answer different questions and have different
-- audiences: "is somebody trying to get in" and "why did that entry change" are
-- not read at the same time or by the same person.
--
-- The shape is syslog's on purpose - facility, severity, host, tag, message -
-- so forwarding to a syslog receiver later is a formatter and not a redesign.
-- `severity` follows RFC 5424: 0 emergency through 7 debug, with the numbers
-- the same way round, so nothing has to be translated at the boundary.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Three streams: who got in, what the instance did, and what the metadata
  -- sources were asked. The third is separate because a lookup is the one thing
  -- here that reaches somebody else's server.
  channel     ENUM('security','server','metadata') NOT NULL DEFAULT 'server',
  -- RFC 5424 numbering: 0 emerg, 3 error, 5 notice, 6 info, 7 debug.
  severity    TINYINT UNSIGNED NOT NULL DEFAULT 6,
  -- A dotted verb: 'library.created', 'auth.signin.failed', 'item.deleted'.
  -- A string rather than an enum so adding one is a call, not a migration.
  event       VARCHAR(60)  NOT NULL,
  message     VARCHAR(500) NOT NULL,

  -- Who, if anybody. Kept as a name as well as an id: the account may be
  -- deleted later, and a log that forgets who did something is not a log.
  actor_id    INT UNSIGNED DEFAULT NULL,
  actor_name  VARCHAR(120) DEFAULT NULL,

  -- What it was about, loosely. Not a foreign key: an entry about something
  -- deleted is exactly the entry worth keeping.
  subject_type VARCHAR(40) DEFAULT NULL,
  subject_id   INT UNSIGNED DEFAULT NULL,

  ip          VARBINARY(16) DEFAULT NULL,
  -- Anything else worth having, as JSON. Deliberately loose: the alternative is
  -- a column per event, and there is no end to that.
  context     JSON         DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_logs_stream (channel, created_at),
  KEY idx_logs_event (event, created_at),
  KEY idx_logs_actor (actor_id, created_at),
  KEY idx_logs_severity (channel, severity, created_at),
  CONSTRAINT fk_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Notifications
--
-- One row per thing that happened to one person. Deliberately generic: a kind,
-- a subject line, a body, and an optional link to whatever it is about. New
-- kinds are a string and a template, not a schema change.
--
-- Written for three readers at once. The web interface counts the unread ones
-- and lists them; the API serves the same rows to a native client, which is why
-- `created_at` is indexed with the recipient - a phone syncing after a week
-- asks "what since this timestamp" and must not scan the table. And the mailer
-- reads the ones still waiting to be sent.
--
-- `dedupe_key` stops the same thing being announced twice: re-inviting somebody
-- to the same library should not queue a second notice.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  kind        VARCHAR(60)  NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  body        TEXT         DEFAULT NULL,
  -- Where it points. A path rather than a URL so the same row serves the web
  -- interface and a native client, which have different ideas about hostnames.
  link_path   VARCHAR(255) DEFAULT NULL,
  -- What it is about, loosely: 'library', 'item', 'user'. Not a foreign key,
  -- because a notice about something deleted is still worth reading.
  subject_type VARCHAR(40) DEFAULT NULL,
  subject_id   INT UNSIGNED DEFAULT NULL,
  dedupe_key  VARCHAR(120) DEFAULT NULL,
  read_at     DATETIME     DEFAULT NULL,
  -- Mail is queued rather than sent inline: a slow relay must not make saving
  -- an entry slow, and a broken one must not make it fail.
  mail_state  ENUM('none','queued','sent','failed') NOT NULL DEFAULT 'none',
  mail_error  VARCHAR(255) DEFAULT NULL,
  mailed_at   DATETIME     DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_inbox (user_id, created_at),
  KEY idx_notifications_unread (user_id, read_at),
  KEY idx_notifications_mail (mail_state, created_at),
  UNIQUE KEY uq_notifications_dedupe (user_id, dedupe_key),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Who wants what, and how.
--
-- A row here is an override. No row means "use the default for this kind",
-- which is set by an administrator in settings - so adding a notification kind
-- does not require writing a row for every existing account, and changing the
-- instance default actually changes it for everyone who has not expressed a
-- preference.
--
-- Two channels, decided separately: seeing something in the interface and being
-- emailed about it are different appetites.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_prefs (
  user_id  INT UNSIGNED NOT NULL,
  kind     VARCHAR(60)  NOT NULL,
  in_app   TINYINT(1)   NOT NULL DEFAULT 1,
  by_mail  TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, kind),
  CONSTRAINT fk_notifpref_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Small per-user choices that are nobody else's business.
--
-- Not columns on `users`: the first of these is which view each browser section
-- opens in, and the next will be something else, and a column apiece means a
-- migration apiece for settings individually worth almost nothing. Same shape as
-- notification_prefs above - a row per user per key, cascading away with the
-- account.
--
-- Deliberately not the `settings` table, which holds instance configuration an
-- administrator sets. Mixing per-user preference into it would mean every read
-- had to say whose it was.
CREATE TABLE IF NOT EXISTS user_prefs (
  user_id INT UNSIGNED NOT NULL,
  name    VARCHAR(60)  NOT NULL,
  -- A preference needing more than this is not a preference.
  value   VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (user_id, name),
  CONSTRAINT fk_userpref_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- API tokens for native clients (macOS, iOS, Android).
-- Only the SHA-256 hash is stored; the plaintext token is shown once.
-- An invitation to join this instance.
--
-- Hashed like a password and like an API token, because that is what it is:
-- whoever holds one becomes a user here. Single use, with a lifetime.
CREATE TABLE IF NOT EXISTS invites (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(190) NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  prefix      VARCHAR(12)  NOT NULL,
  created_by  INT UNSIGNED     NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME         NULL,
  user_id     INT UNSIGNED     NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invites_token (token_hash),
  KEY idx_invites_email (email),
  CONSTRAINT fk_invites_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_invites_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,          -- "Tommy's iPhone"
  token_hash   CHAR(64)     NOT NULL,          -- hash('sha256', plaintext)
  prefix       VARCHAR(16)  NOT NULL,          -- shown in the UI so tokens are identifiable
  scope        ENUM('read','write') NOT NULL DEFAULT 'write',
  platform     VARCHAR(40)  DEFAULT NULL,      -- ios, macos, android, other
  last_used_at DATETIME     DEFAULT NULL,
  last_used_ip VARCHAR(45)  DEFAULT NULL,
  expires_at   DATETIME     DEFAULT NULL,      -- NULL = never expires
  revoked_at   DATETIME     DEFAULT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Tombstones. Offline clients need to learn about deletions, not just changes,
-- so every delete leaves a row here for the /api/v1/sync endpoint to report.
--
-- library_id is the one that matters: sync withholds deletions in libraries
-- the caller cannot see. NULL means the row predates access control and is
-- reported to nobody.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tombstones (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity     VARCHAR(40)  NOT NULL,            -- items, item_images ...
  entity_id  INT UNSIGNED NOT NULL,
  library_id INT UNSIGNED DEFAULT NULL,
  deleted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tombstones_when (deleted_at),
  KEY idx_tombstones_entity (entity, deleted_at),
  KEY idx_tombstones_library (library_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Metadata providers: pluggable lookup sources (CSDb, Wikidata, MobyGames...)
-- Same shape as auth_methods; nothing runs until one is added and enabled.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS metadata_providers (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Not an ENUM: adding a source should not need a migration.
  type         VARCHAR(40)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  description  VARCHAR(255) DEFAULT NULL,
  params       TEXT         DEFAULT NULL,   -- JSON: API keys, endpoints, limits
  is_enabled   TINYINT(1)   NOT NULL DEFAULT 1,
  -- Lowest first when several providers can answer the same lookup.
  priority     INT          NOT NULL DEFAULT 100,
  last_used_at DATETIME     DEFAULT NULL,
  last_error   VARCHAR(255) DEFAULT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_metadata_providers_name (name),
  KEY idx_metadata_providers_enabled (is_enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Our platform id mapped to whatever the remote service calls that machine.
-- Without this a search for an Amiga title happily returns the DOS version.
-- Documents an entry points at.
--
-- The metadata lookup finds manuals, schematics and ROM listings archived at the
-- source, and listed them with "not imported" underneath - which told you the
-- manual exists somewhere and left you to write the address down by hand.
--
-- Links rather than files, deliberately. A scanned service manual is tens of
-- megabytes, it is already hosted by somebody who curates it, and copying it here
-- would make this instance responsible for storage and for a redistribution
-- question nobody asked. What is worth keeping is that the document exists and
-- where it is.
CREATE TABLE IF NOT EXISTS item_documents (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id    INT UNSIGNED NOT NULL,
  label      VARCHAR(200)  NOT NULL,
  url        VARCHAR(1000) NOT NULL,
  -- Where it came from, so a link found by a scraper can be told from one
  -- somebody typed - and so a re-run can leave the typed ones alone.
  source     VARCHAR(60)  DEFAULT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_item_documents_item (item_id),
  -- The same address twice on one entry is a duplicate, not a second document.
  UNIQUE KEY uq_item_documents (item_id, url(255)),
  CONSTRAINT fk_item_documents_item FOREIGN KEY (item_id) REFERENCES items (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metadata_provider_platforms (
  provider_id        INT UNSIGNED NOT NULL,
  platform_id        INT UNSIGNED NOT NULL,
  remote_platform_id VARCHAR(80)  NOT NULL,
  PRIMARY KEY (provider_id, platform_id),
  KEY idx_mpp_platform (platform_id),
  CONSTRAINT fk_mpp_provider FOREIGN KEY (provider_id) REFERENCES metadata_providers (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_mpp_platform FOREIGN KEY (platform_id) REFERENCES platforms          (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Which metadata source serves which part of the tree.
--
-- Bound to a node and inherited downwards: enabling the Amiga Hardware
-- Database at "Hardware > Amiga" covers everything beneath it unless a row
-- lower down says otherwise.
--
-- platform_id 0 means every machine, for the same reason as hardware_vocab:
-- a nullable column would leave the common case unconstrained by the UNIQUE
-- key below.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_scopes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  platform_id INT UNSIGNED NOT NULL DEFAULT 0,
  -- A row can also switch a source off for a subtree its parent enabled.
  enabled     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_scope (provider_id, category_id, platform_id),
  KEY idx_provider_scope_lookup (category_id, platform_id),
  CONSTRAINT fk_ps_provider FOREIGN KEY (provider_id) REFERENCES metadata_providers (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ps_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Responses are cached so that repeating a search, or paging back to a result,
-- does not spend another request against a rate-limited free tier.
CREATE TABLE IF NOT EXISTS metadata_cache (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cache_key  CHAR(64)     NOT NULL,          -- sha256 of provider + operation + args
  provider   VARCHAR(40)  NOT NULL,
  payload    LONGTEXT     NOT NULL,          -- JSON
  fetched_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_metadata_cache_key (cache_key),
  KEY idx_metadata_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What was imported, from where, and by whom. Metadata arriving from a third
-- party should be traceable back to its source. It can land on a title (the
-- usual case now) or directly on an entry.
CREATE TABLE IF NOT EXISTS metadata_imports (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id     INT UNSIGNED DEFAULT NULL,
  title_id    INT UNSIGNED DEFAULT NULL,
  provider_id INT UNSIGNED DEFAULT NULL,
  provider    VARCHAR(40)  NOT NULL,
  remote_id   VARCHAR(120) DEFAULT NULL,
  remote_url  VARCHAR(500) DEFAULT NULL,
  fields      TEXT         DEFAULT NULL,     -- JSON: which fields were applied
  user_id     INT UNSIGNED DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_imports_item (item_id, created_at),
  KEY idx_imports_title (title_id, created_at),
  KEY idx_imports_provider (provider_id),
  CONSTRAINT fk_imports_item     FOREIGN KEY (item_id)     REFERENCES items  (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imports_title    FOREIGN KEY (title_id)    REFERENCES titles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_imports_provider FOREIGN KEY (provider_id) REFERENCES metadata_providers (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Convenience view: everything you need for a list row in one read.
--
-- The three correlated subqueries this used to carry - cover filename, image
-- count, tag list - ran once per row and made the whole thing unindexable.
-- Cover and count are now columns on items; tags are filtered with EXISTS
-- against item_tags rather than FIND_IN_SET over a GROUP_CONCAT.
--
-- `domain` is derived from the entry's category, by way of its section, and
-- from nowhere else. This is the only place it is stated. It is a real
-- foreign key underneath now (categories.section_id -> sections), not the
-- ENUM it used to be, but every caller reading `domain` off this view sees
-- the identical string it always did - 'hardware', 'software', plus 'video'
-- and 'music' now that those sections exist. The join is new; the shape is
-- not, and that is deliberate: it is what let this change land without
-- touching most of the ~200 places in this codebase that read this column.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_items AS
SELECT
  i.*,
  lib.name AS library_name, lib.slug AS library_slug, lib.accent_color AS library_color,
  lib.kind AS library_kind, lib.owner_id AS library_owner_id,
  s.slug AS domain, c.path AS category_path, c.depth AS category_depth,
  p.name  AS platform_name,  p.slug AS platform_slug, p.accent_color AS platform_color,
  pv.name AS platform_vendor,
  c.name  AS category_name,  c.slug AS category_slug, c.role AS category_role,
  d.name  AS developer_name, d.slug AS developer_slug, d.website AS developer_website, d.logo_filename AS developer_logo,
  pb.name AS publisher_name, pb.slug AS publisher_slug,
  t.name  AS title_name,     t.slug AS title_slug, t.work_key AS title_work_key,
  t.synopsis AS title_synopsis,
  hm.name AS model_name,     hm.slug AS model_slug,
  img.filename AS cover_filename,
  loc.name AS location_name, loc.path AS location_path
FROM items i
JOIN libraries lib ON lib.id = i.library_id
JOIN platforms  p  ON p.id  = i.platform_id
LEFT JOIN companies pv ON pv.id = p.vendor_id
JOIN categories c  ON c.id  = i.category_id
JOIN sections   s  ON s.id  = c.section_id
LEFT JOIN companies       d   ON d.id   = i.developer_id
LEFT JOIN companies       pb  ON pb.id  = i.publisher_id
LEFT JOIN titles          t   ON t.id   = i.title_id
LEFT JOIN hardware_models hm  ON hm.id  = i.model_id
LEFT JOIN item_images     img ON img.id = i.cover_image_id
LEFT JOIN locations       loc ON loc.id = i.location_id
WHERE i.deleted_at IS NULL;

-- ---------------------------------------------------------------------------
-- Titles with how many copies of each are on the shelf. Used by the title
-- picker on the entry form and by the "you may already own this" check.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_titles AS
SELECT
  t.*,
  p.name AS platform_name, p.slug AS platform_slug, p.accent_color AS platform_color,
  c.name AS category_name, c.slug AS category_slug, s.slug AS domain,
  d.name AS developer_name,
  pb.name AS publisher_name,
  (SELECT COUNT(*) FROM items i WHERE i.title_id = t.id AND i.deleted_at IS NULL) AS copy_count
FROM titles t
JOIN platforms p ON p.id = t.platform_id
LEFT JOIN categories c  ON c.id  = t.category_id
LEFT JOIN sections    s  ON s.id  = c.section_id
LEFT JOIN companies  d  ON d.id  = t.developer_id
LEFT JOIN companies  pb ON pb.id = t.publisher_id;

-- ---------------------------------------------------------------------------
-- API request statistics - bucketed by hour, not one row per request, which
-- would grow unbounded fast on a busy instance and answer no question a
-- bucket doesn't already answer just as well. Powers the System status
-- page's own request-volume figures: which endpoints get hit, how often,
-- by what kind of caller, and how they're trending, without keeping a full
-- request log anywhere.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_request_stats (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket_hour   DATETIME     NOT NULL,
  method        VARCHAR(10)  NOT NULL,
  -- The route's own pattern, not the raw path - "/items/{id}" rather than
  -- "/items/482", so ten thousand different item ids bucket into one row
  -- instead of ten thousand different ones.
  route         VARCHAR(120) NOT NULL,
  status_class  ENUM('2xx','3xx','4xx','5xx') NOT NULL,
  -- Where the call came from: a token's own declared platform (ios,
  -- android, macos, other), or 'web' for this client's own session-based
  -- calls, or 'unknown' for a bare request with no token and no session.
  source        VARCHAR(20)  NOT NULL DEFAULT 'unknown',
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_ms      INT UNSIGNED NOT NULL DEFAULT 0,
  -- The slowest single call folded into this bucket, not just the sum
  -- averaging can hide - a bucket that's fast on average because 99
  -- calls were instant and one took four seconds looks identical to a
  -- bucket where all 100 took forty milliseconds, unless this is kept
  -- alongside the total.
  max_ms        INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_request_stats (bucket_hour, method, route, status_class, source),
  KEY idx_api_request_stats_hour (bucket_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The same idea as api_request_stats, five minutes at a time instead of
-- one hour - close enough to watch traffic move in something closer to
-- real time, source rather than route, since a route-and-status
-- breakdown at this resolution would be mostly empty cells: "recent
-- activity by kind of caller" is the question this answers, "which
-- endpoint" stays the hourly table's own to answer. Kept for hours
-- rather than days on purpose - api_prune_request_stats_5m() removes
-- what a 24-hour chart could never have shown anyway, so the table
-- never grows into something the hourly one already exists to be.
CREATE TABLE IF NOT EXISTS api_request_stats_5m (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket_5m     DATETIME     NOT NULL,
  source        VARCHAR(20)  NOT NULL DEFAULT 'unknown',
  status_class  ENUM('2xx','3xx','4xx','5xx') NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_ms      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_request_stats_5m (bucket_5m, source, status_class),
  KEY idx_api_request_stats_5m_bucket (bucket_5m)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
